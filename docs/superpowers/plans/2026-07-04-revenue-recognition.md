# Revenue Recognition (Slice A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Recognize an invoice's revenue straight-line over N monthly periods, posting one balanced GL journal entry (Dr Deferred Revenue / Cr Revenue) per due period.

**Architecture:** A `RevenueSchedule` (one per invoice: total, start date, N periods, explicit deferred + revenue accounts) owns N `RevenueScheduleEntry` rows (one per period, straight-line split with the rounding remainder on the last). `RevenueRecognitionService::createFromInvoice` builds the schedule + entries; `recognizeDue` is a catch-up loop (mirroring `App\Concerns\Recurring` / the P2-1 subscription engine) that, for each un-recognized entry whose `recognition_date <= today`, posts a `JournalEntry` via the existing engine (`create → lines → post()`), marks the entry recognized and links the JE. A `revenue:recognize` command runs it daily; a Filament resource manages schedules.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- `RevenueSchedule` uses `App\Traits\IsTenantModel` + a `creating` hook stamping `team_id` from `auth()->user()?->currentTeam` when empty (same pattern as `Plan`/`Subscription`). `RevenueScheduleEntry` is child data (no own tenant hook — it inherits scope through its schedule).
- Money columns `decimal(15,2)`.
- **GL posting from the scheduled command runs with NO `auth()` context. Two traps, both mandatory:**
  - `journal_entries.user_id` is a **non-nullable** FK. The `creating` hook only sets it from `auth()->id()` (null in the command). You MUST set it explicitly: `user_id = $schedule->team->user_id` (the team owner — always non-null).
  - `journal_entries.team_id` is **not fillable** and **defaults to `1`** when unset (the hook stamps it only from `auth()->user()?->currentTeam`, null in the command). You MUST set it explicitly to `$schedule->team_id`, or every recognition entry silently leaks into team 1. Set both via `forceFill([...])` before `post()` (mass-assign ignores non-fillable `user_id`/`team_id` in production where models are guarded).
- The posted entry is **Dr `deferred_account` / Cr `revenue_account`** for the period amount — a balanced two-line entry. `JournalEntry::post()` validates `isBalanced()` and updates account balances; call it once per entry.
- Recognition is idempotent: re-running `recognizeDue` on a fully-recognized (or up-to-date) schedule generates 0 entries. Per-entry `DB::transaction` (crash-safe: a mid-catch-up failure leaves already-recognized periods posted and re-runnable).
- Slice A posts only the recognition side; the upstream defer-on-invoice posting (Dr AR / Cr Deferred at billing) is a later slice. Do not wire `InvoicePostingService` here.
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml / never weaken a guard**. No `TeamFactory` — `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`. `Invoice::factory()`, `Customer::factory()`, `Account` via `Account::create([...])` (explicit, like `tests/Unit/DoubleEntryAccountingTest.php`). `invoices.team_id` has a real FK → always pass `team_id` explicitly.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: RevenueSchedule + RevenueScheduleEntry models & migrations

**Files:**
- Create: `src/database/migrations/2026_07_10_100001_create_revenue_schedules_table.php`
- Create: `src/database/migrations/2026_07_10_100002_create_revenue_schedule_entries_table.php`
- Create: `src/app/Models/RevenueSchedule.php`
- Create: `src/app/Models/RevenueScheduleEntry.php`
- Test: `src/tests/Feature/RevenueRecognition/RevenueScheduleModelTest.php`

**Interfaces:**
- Produces: `RevenueSchedule` (PK `id`; fillable `invoice_id, total_amount, start_date, periods, deferred_account_id, revenue_account_id, status, team_id`; `total_amount` cast `decimal:2`, `start_date` cast `date`, `periods` cast `integer`; `invoice()`/`deferredAccount()`/`revenueAccount()` belongsTo; `entries()` hasMany). `RevenueScheduleEntry` (PK `id`; fillable `revenue_schedule_id, period_number, recognition_date, amount, recognized, recognized_at, journal_entry_id`; `recognition_date` cast `date`, `amount` cast `decimal:2`, `recognized` cast `boolean`, `recognized_at` cast `datetime`; `schedule()`/`journalEntry()` belongsTo).

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/RevenueRecognition/RevenueScheduleModelTest.php
declare(strict_types=1);
namespace Tests\Feature\RevenueRecognition;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\RevenueSchedule;
use App\Models\RevenueScheduleEntry;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueScheduleModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_owns_entries_and_links_invoice_and_accounts(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 1200]);
        $deferred = Account::create(['account_number' => 2400, 'account_name' => 'Deferred Revenue', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        $revenue = Account::create(['account_number' => 4000, 'account_name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);

        $schedule = RevenueSchedule::create([
            'invoice_id' => $invoice->id, 'total_amount' => 1200, 'start_date' => '2026-01-01',
            'periods' => 12, 'deferred_account_id' => $deferred->id, 'revenue_account_id' => $revenue->id,
            'status' => 'active', 'team_id' => $team->id,
        ]);
        $entry = RevenueScheduleEntry::create([
            'revenue_schedule_id' => $schedule->id, 'period_number' => 1,
            'recognition_date' => '2026-01-01', 'amount' => 100, 'recognized' => false,
        ]);

        $this->assertTrue($schedule->invoice->is($invoice));
        $this->assertTrue($schedule->deferredAccount->is($deferred));
        $this->assertTrue($schedule->revenueAccount->is($revenue));
        $this->assertTrue($schedule->entries->first()->is($entry));
        $this->assertFalse($entry->recognized);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=RevenueScheduleModelTest`
Expected: FAIL (`App\Models\RevenueSchedule` not found).

- [ ] **Step 3: Create the migrations**

```php
<?php // 2026_07_10_100001_create_revenue_schedules_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('revenue_schedules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->unique()->constrained()->cascadeOnDelete();
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->date('start_date');
            $t->unsignedInteger('periods');
            $t->foreignId('deferred_account_id')->constrained('accounts');
            $t->foreignId('revenue_account_id')->constrained('accounts');
            $t->string('status')->default('active'); // active, completed, cancelled
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('revenue_schedules'); }
};
```

```php
<?php // 2026_07_10_100002_create_revenue_schedule_entries_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('revenue_schedule_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('revenue_schedule_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('period_number');
            $t->date('recognition_date');
            $t->decimal('amount', 15, 2)->default(0);
            $t->boolean('recognized')->default(false);
            $t->timestamp('recognized_at')->nullable();
            $t->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $t->timestamps();
            $t->unique(['revenue_schedule_id', 'period_number']);
        });
    }
    public function down(): void { Schema::dropIfExists('revenue_schedule_entries'); }
};
```

- [ ] **Step 4: Create the models**

```php
<?php // src/app/Models/RevenueSchedule.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueSchedule extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'invoice_id', 'total_amount', 'start_date', 'periods',
        'deferred_account_id', 'revenue_account_id', 'status', 'team_id',
    ];

    #[\Override]
    protected $casts = [
        'total_amount' => 'decimal:2',
        'start_date' => 'date',
        'periods' => 'integer',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (RevenueSchedule $schedule): void {
            if (empty($schedule->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $schedule->team_id = $team->getKey();
            }
        });
    }

    public function invoice(): BelongsTo { return $this->belongsTo(Invoice::class); }
    public function deferredAccount(): BelongsTo { return $this->belongsTo(Account::class, 'deferred_account_id'); }
    public function revenueAccount(): BelongsTo { return $this->belongsTo(Account::class, 'revenue_account_id'); }
    public function entries(): HasMany { return $this->hasMany(RevenueScheduleEntry::class); }
}
```

```php
<?php // src/app/Models/RevenueScheduleEntry.php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueScheduleEntry extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'revenue_schedule_id', 'period_number', 'recognition_date',
        'amount', 'recognized', 'recognized_at', 'journal_entry_id',
    ];

    #[\Override]
    protected $casts = [
        'recognition_date' => 'date',
        'amount' => 'decimal:2',
        'recognized' => 'boolean',
        'recognized_at' => 'datetime',
    ];

    public function schedule(): BelongsTo { return $this->belongsTo(RevenueSchedule::class, 'revenue_schedule_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=RevenueScheduleModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Models/RevenueSchedule.php app/Models/RevenueScheduleEntry.php database/migrations/2026_07_10_1000*.php tests/Feature/RevenueRecognition/RevenueScheduleModelTest.php
git -C src commit -m "feat(revrec): schedule + entry models"
```

---

### Task 2: RevenueRecognitionService::createFromInvoice (straight-line split)

**Files:**
- Create: `src/app/Services/RevenueRecognitionService.php`
- Test: `src/tests/Feature/RevenueRecognition/CreateScheduleTest.php`

**Interfaces:**
- Consumes: `RevenueSchedule`, `RevenueScheduleEntry` (Task 1); `App\Models\Invoice` (`total_amount`, `invoice_date`, `team_id`); `App\Models\Account`.
- Produces: `RevenueRecognitionService::createFromInvoice(Invoice $invoice, int $periods, Account $deferred, Account $revenue): RevenueSchedule` — creates one `RevenueSchedule` (total = invoice `total_amount`, start = invoice `invoice_date`, `team_id` from the invoice) with `periods` `RevenueScheduleEntry` rows. Straight-line: `per = round(total / periods, 2)` for periods 1..N-1, last entry `= total - per*(N-1)` so the entries sum to the exact total. `recognition_date` of period `n` = `start_date + (n-1) months`. Throws `InvalidArgumentException` if `periods < 1` or a schedule already exists for the invoice.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/RevenueRecognition/CreateScheduleTest.php
declare(strict_types=1);
namespace Tests\Feature\RevenueRecognition;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\RevenueRecognitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class CreateScheduleTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Invoice,1:Account,2:Account} */
    private function fixtures(float $total): array
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => $total, 'invoice_date' => '2026-01-15']);
        $deferred = Account::create(['account_number' => 2400, 'account_name' => 'Deferred', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        $revenue = Account::create(['account_number' => 4000, 'account_name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);

        return [$invoice, $deferred, $revenue];
    }

    public function test_generates_straight_line_entries_summing_to_total(): void
    {
        [$invoice, $deferred, $revenue] = $this->fixtures(1000.00); // 1000 / 3 = 333.33, last = 333.34
        $schedule = app(RevenueRecognitionService::class)->createFromInvoice($invoice, 3, $deferred, $revenue);

        $this->assertSame(3, $schedule->entries()->count());
        $amounts = $schedule->entries()->orderBy('period_number')->pluck('amount')->map(fn ($a) => (string) $a)->all();
        $this->assertSame(['333.33', '333.33', '333.34'], $amounts);
        // entries sum to the exact invoice total (no lost/gained cent)
        $this->assertSame('1000.00', number_format((float) $schedule->entries()->sum('amount'), 2, '.', ''));
        // recognition dates step one month from invoice_date
        $dates = $schedule->entries()->orderBy('period_number')->pluck('recognition_date')->map(fn ($d) => $d->toDateString())->all();
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $dates);
        $this->assertSame($invoice->team_id, (int) $schedule->team_id);
    }

    public function test_rejects_zero_periods_and_duplicate_schedule(): void
    {
        [$invoice, $deferred, $revenue] = $this->fixtures(500.00);
        $service = app(RevenueRecognitionService::class);

        $service->createFromInvoice($invoice, 5, $deferred, $revenue);

        $this->expectException(InvalidArgumentException::class);
        $service->createFromInvoice($invoice->fresh(), 5, $deferred, $revenue); // second schedule for same invoice
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=CreateScheduleTest`
Expected: FAIL (`App\Services\RevenueRecognitionService` not found).

- [ ] **Step 3: Implement `createFromInvoice`**

```php
<?php // src/app/Services/RevenueRecognitionService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\RevenueSchedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RevenueRecognitionService
{
    public function createFromInvoice(Invoice $invoice, int $periods, Account $deferred, Account $revenue): RevenueSchedule
    {
        if ($periods < 1) {
            throw new InvalidArgumentException('periods must be at least 1.');
        }
        if (RevenueSchedule::where('invoice_id', $invoice->getKey())->exists()) {
            throw new InvalidArgumentException('A revenue schedule already exists for this invoice.');
        }

        $total = (float) $invoice->total_amount;
        $per = round($total / $periods, 2);
        $start = $invoice->invoice_date->copy();

        return DB::transaction(function () use ($invoice, $periods, $deferred, $revenue, $total, $per, $start): RevenueSchedule {
            $schedule = RevenueSchedule::create([
                'invoice_id' => $invoice->getKey(),
                'total_amount' => $total,
                'start_date' => $start,
                'periods' => $periods,
                'deferred_account_id' => $deferred->getKey(),
                'revenue_account_id' => $revenue->getKey(),
                'status' => 'active',
                'team_id' => $invoice->team_id,
            ]);

            for ($n = 1; $n <= $periods; $n++) {
                $amount = $n < $periods ? $per : round($total - $per * ($periods - 1), 2);
                $schedule->entries()->create([
                    'period_number' => $n,
                    'recognition_date' => $start->copy()->addMonths($n - 1),
                    'amount' => $amount,
                    'recognized' => false,
                ]);
            }

            return $schedule;
        });
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=CreateScheduleTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/RevenueRecognitionService.php tests/Feature/RevenueRecognition/CreateScheduleTest.php
git -C src commit -m "feat(revrec): straight-line schedule from invoice"
```

---

### Task 3: recognizeDue (catch-up GL posting) + revenue:recognize command

**Files:**
- Modify: `src/app/Services/RevenueRecognitionService.php` (add `recognizeDue`)
- Create: `src/app/Console/Commands/RecognizeRevenue.php`
- Modify: `src/bootstrap/app.php` (schedule `revenue:recognize` daily inside the existing `->withSchedule(...)` closure)
- Test: `src/tests/Feature/RevenueRecognition/RecognizeDueTest.php`
- Test: `src/tests/Feature/RevenueRecognition/RecognizeRevenueCommandTest.php`

**Interfaces:**
- Consumes: `RevenueSchedule`, `RevenueScheduleEntry` (Task 1); `App\Models\JournalEntry` (fillable incl. `entry_number, entry_date, reference_number, memo, entry_type`; `lines()` hasMany; `post(): static` validates `isBalanced()` + updates balances; `user_id`/`team_id` NOT fillable — set via `forceFill`).
- Produces: `RevenueRecognitionService::recognizeDue(RevenueSchedule $schedule): int` — for each entry with `recognized === false` and `recognition_date <= today`, in `period_number` order, post a balanced `JournalEntry` (Dr `deferred_account` / Cr `revenue_account`, amount = entry amount), mark the entry `recognized = true`, `recognized_at = now()`, `journal_entry_id = <posted entry id>`; per-entry `DB::transaction`. When no un-recognized entries remain, set schedule `status = 'completed'`. Returns the count recognized. Returns 0 for a non-`active` schedule. `subscriptions`-style catch-up + idempotent.

- [ ] **Step 1: Write the failing test (recognizeDue)**

```php
<?php // src/tests/Feature/RevenueRecognition/RecognizeDueTest.php
declare(strict_types=1);
namespace Tests\Feature\RevenueRecognition;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\RevenueSchedule;
use App\Models\Team;
use App\Models\User;
use App\Services\RevenueRecognitionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecognizeDueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-20'); // periods 01-15, 02-15, 03-15 are due; 04-15+ not yet
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0:RevenueSchedule,1:Account,2:Account,3:Team} */
    private function schedule(): array
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 1200, 'invoice_date' => '2026-01-15']);
        $deferred = Account::create(['account_number' => 2400, 'account_name' => 'Deferred', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        $revenue = Account::create(['account_number' => 4000, 'account_name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        $schedule = app(RevenueRecognitionService::class)->createFromInvoice($invoice, 12, $deferred, $revenue);

        return [$schedule, $deferred, $revenue, $team];
    }

    public function test_recognizes_only_due_periods_posts_balanced_entries_and_is_idempotent(): void
    {
        [$schedule, $deferred, $revenue, $team] = $this->schedule();

        $count = app(RevenueRecognitionService::class)->recognizeDue($schedule);

        $this->assertSame(3, $count); // 01-15, 02-15, 03-15 <= 2026-03-20
        // three posted journal entries, each balanced, each stamped with the schedule's team + owner
        $entries = JournalEntry::where('team_id', $team->id)->get();
        $this->assertCount(3, $entries);
        foreach ($entries as $je) {
            $this->assertTrue($je->is_posted);
            $this->assertTrue($je->isBalanced());
            $this->assertSame($team->user_id, (int) $je->user_id);
        }
        // revenue recognised = 3 * 100.00 = 300.00; deferred liability drawn down by the same
        $this->assertSame('300.00', number_format((float) $revenue->fresh()->balance, 2, '.', ''));
        $this->assertSame('-300.00', number_format((float) $deferred->fresh()->balance, 2, '.', ''));
        // each recognised entry links its journal entry
        $this->assertSame(3, $schedule->entries()->whereNotNull('journal_entry_id')->where('recognized', true)->count());

        // Idempotent: re-run today generates nothing new.
        $this->assertSame(0, app(RevenueRecognitionService::class)->recognizeDue($schedule->fresh()));
    }

    public function test_full_recognition_marks_schedule_completed(): void
    {
        [$schedule] = $this->schedule();
        Carbon::setTestNow('2027-06-01'); // all 12 periods now due

        app(RevenueRecognitionService::class)->recognizeDue($schedule);

        $this->assertSame('completed', $schedule->fresh()->status);
        $this->assertSame(12, $schedule->entries()->where('recognized', true)->count());
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=RecognizeDueTest`
Expected: FAIL (`recognizeDue()` not defined).

- [ ] **Step 3: Add `recognizeDue` to the service**

Add this method to `src/app/Services/RevenueRecognitionService.php` (and the imports `use App\Models\JournalEntry;`, `use App\Models\RevenueSchedule;`, `use App\Models\RevenueScheduleEntry;` — `RevenueSchedule` is already imported from Task 2; add the other two):

```php
    public function recognizeDue(RevenueSchedule $schedule): int
    {
        if ($schedule->status !== 'active') {
            return 0;
        }

        $today = today();
        $count = 0;

        $due = $schedule->entries()
            ->where('recognized', false)
            ->whereDate('recognition_date', '<=', $today)
            ->orderBy('period_number')
            ->get();

        foreach ($due as $entry) {
            DB::transaction(function () use ($schedule, $entry): void {
                $je = new JournalEntry;
                // team_id + user_id are NOT fillable and there is no auth() in the scheduled
                // command; set them explicitly so the entry is team-scoped (never the default
                // team 1) and satisfies the non-nullable user_id FK (team owner).
                $je->forceFill([
                    'entry_date' => $entry->recognition_date,
                    'entry_type' => 'general',
                    'reference_number' => (string) $schedule->getKey(),
                    'memo' => 'Revenue recognition — schedule #'.$schedule->getKey().' period '.$entry->period_number,
                    'team_id' => $schedule->team_id,
                    'user_id' => $schedule->team->user_id,
                ])->save();

                $je->lines()->create([
                    'account_id' => $schedule->deferred_account_id,
                    'debit_amount' => $entry->amount,
                    'credit_amount' => 0,
                    'description' => 'Deferred revenue recognised',
                ]);
                $je->lines()->create([
                    'account_id' => $schedule->revenue_account_id,
                    'debit_amount' => 0,
                    'credit_amount' => $entry->amount,
                    'description' => 'Revenue recognised',
                ]);

                $je->post();

                $entry->update([
                    'recognized' => true,
                    'recognized_at' => now(),
                    'journal_entry_id' => $je->getKey(),
                ]);
            });
            $count++;
        }

        if ($schedule->entries()->where('recognized', false)->count() === 0) {
            $schedule->update(['status' => 'completed']);
        }

        return $count;
    }
```

- [ ] **Step 4: Run the recognizeDue test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=RecognizeDueTest`
Expected: PASS (2 tests). If `post()` complains about balances, confirm both lines use the entry amount (debit one, credit the other). If an FK error on `user_id`, confirm `$schedule->team->user_id` resolves (the schedule's team is set from the invoice in Task 2).

- [ ] **Step 5: Write the command test**

```php
<?php // src/tests/Feature/RevenueRecognition/RecognizeRevenueCommandTest.php
declare(strict_types=1);
namespace Tests\Feature\RevenueRecognition;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\RevenueRecognitionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecognizeRevenueCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_recognizes_all_active_schedules(): void
    {
        Carbon::setTestNow('2026-02-20'); // periods 01-15, 02-15 due
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 1200, 'invoice_date' => '2026-01-15']);
        $deferred = Account::create(['account_number' => 2400, 'account_name' => 'Deferred', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        $revenue = Account::create(['account_number' => 4000, 'account_name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        app(RevenueRecognitionService::class)->createFromInvoice($invoice, 12, $deferred, $revenue);

        $this->artisan('revenue:recognize')->assertSuccessful();

        $this->assertSame(2, JournalEntry::where('team_id', $team->id)->count());
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 6: Create the command + schedule it**

```php
<?php // src/app/Console/Commands/RecognizeRevenue.php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\RevenueSchedule;
use App\Services\RevenueRecognitionService;
use Illuminate\Console\Command;

class RecognizeRevenue extends Command
{
    #[\Override]
    protected $signature = 'revenue:recognize';
    #[\Override]
    protected $description = 'Post due revenue-recognition entries for all active schedules';

    public function handle(RevenueRecognitionService $service): void
    {
        $total = 0;
        RevenueSchedule::where('status', 'active')->each(function (RevenueSchedule $schedule) use (&$total, $service): void {
            $total += $service->recognizeDue($schedule);
        });
        $this->info("Recognised {$total} revenue period(s).");
    }
}
```

In `src/bootstrap/app.php`, inside the existing `->withSchedule(function (Schedule $schedule): void { ... })` closure (alongside the existing entries — do not remove any), add:

```php
        $schedule->command('revenue:recognize')->daily()->withoutOverlapping();
```

- [ ] **Step 7: Run both tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=RecognizeDueTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=RecognizeRevenueCommandTest`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git -C src add app/Services/RevenueRecognitionService.php app/Console/Commands/RecognizeRevenue.php bootstrap/app.php tests/Feature/RevenueRecognition/RecognizeDueTest.php tests/Feature/RevenueRecognition/RecognizeRevenueCommandTest.php
git -C src commit -m "feat(revrec): catch-up recognition posts to GL"
```

---

### Task 4: Filament RevenueScheduleResource

**Files:**
- Create: `src/app/Filament/App/Resources/RevenueSchedules/RevenueScheduleResource.php` (+ List/Create/Edit pages)
- Test: `src/tests/Feature/RevenueRecognition/RevenueScheduleTenancyTest.php`

**Interfaces:**
- Consumes: `RevenueSchedule`, `RevenueScheduleEntry` (Task 1); `RevenueRecognitionService::createFromInvoice` (Task 2).
- Produces: team-scoped `RevenueScheduleResource`. The **Create page routes through the service** (`handleRecordCreation` calls `createFromInvoice`) so the entries are generated; the form collects `invoice_id`, `periods`, `deferred_account_id`, `revenue_account_id`. Table shows invoice, total, periods, status, recognized-count. `status` is system-managed.

- [ ] **Step 1: Write the failing test (tenancy stamp)**

```php
<?php // src/tests/Feature/RevenueRecognition/RevenueScheduleTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\RevenueRecognition;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\RevenueSchedule;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenueScheduleTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_stamps_actor_team(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 600, 'invoice_date' => '2026-01-01']);
        $deferred = Account::create(['account_number' => 2400, 'account_name' => 'Deferred', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);
        $revenue = Account::create(['account_number' => 4000, 'account_name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $user->id]);

        $schedule = RevenueSchedule::create([
            'invoice_id' => $invoice->id, 'total_amount' => 600, 'start_date' => '2026-01-01',
            'periods' => 6, 'deferred_account_id' => $deferred->id, 'revenue_account_id' => $revenue->id,
            'status' => 'active',
        ]);

        $this->assertSame($team->id, (int) $schedule->team_id);
    }
}
```

- [ ] **Step 2: Run it, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=RevenueScheduleTenancyTest`
Expected: PASS (Task 1's `creating` hook stamps `team_id`).

- [ ] **Step 3: Build the Filament resource**

Read an existing app-panel resource to mirror the exact Filament v5 API + page structure: `src/app/Filament/App/Resources/Subscriptions/SubscriptionResource.php` (for the Resource + `Pages/` layout, `Select` relationship fields, `getPages()`, `#[\Override]`) and its `Pages/CreateSubscription.php`. Also read `src/app/Filament/App/Resources/SalesOrders/Pages/` for a Create page that overrides record creation via a service, if present.

`RevenueScheduleResource` (`$model = RevenueSchedule::class`, tenant-scoped by default — do NOT add a scope override; `RevenueSchedule` uses the standard `team()` relation like `Subscription`):
- **Form:** `invoice_id` (Select relationship `invoice` label `invoice_number`, required, searchable), `periods` (TextInput numeric integer, required, min 1), `deferred_account_id` (Select relationship `deferredAccount` label `account_name`, required), `revenue_account_id` (Select relationship `revenueAccount` label `account_name`, required). (Do NOT put `total_amount`/`start_date`/`status` on the create form — the service derives them from the invoice.)
- **Table:** invoice number, total_amount (money), periods, status (badge), and a recognized-count column, e.g. `TextColumn::make('entries_count')` via `->counts('entries')` OR a computed column `->getStateUsing(fn (RevenueSchedule $r) => $r->entries()->where('recognized', true)->count().' / '.$r->periods)`.
- **Create page** `Pages/CreateRevenueSchedule.php`: override creation to route through the service so entries are generated. Mirror the base `CreateRecord` the other resources use, then override:

```php
    #[\Override]
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        return app(\App\Services\RevenueRecognitionService::class)->createFromInvoice(
            \App\Models\Invoice::findOrFail($data['invoice_id']),
            (int) $data['periods'],
            \App\Models\Account::findOrFail($data['deferred_account_id']),
            \App\Models\Account::findOrFail($data['revenue_account_id']),
        );
    }
```

Create the List/Create/Edit pages mirroring `Subscriptions/Pages/`. (Edit page can be the standard `EditRecord`; the schedule is mostly read-after-create for slice A.)

- [ ] **Step 4: Run all revenue-recognition tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=RevenueRecognition`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/RevenueSchedules tests/Feature/RevenueRecognition/RevenueScheduleTenancyTest.php
git -C src commit -m "feat(revrec): Filament revenue schedule UI"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=RevenueRecognition`.
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline (`--generate-baseline phpstan-baseline.neon`) only if the ONLY remaining errors are the Filament/Eloquent-`mixed` + generic-relation idiom on the new files — verify each before baselining.
- Pint the new files.
- Adversarial review focus: **every recognition JournalEntry carries the schedule's `team_id` (never the default team 1) and a non-null `user_id`**; entries sum to the exact invoice total (no lost cent); catch-up recognizes only due periods, idempotent (no double-post), and marks `completed` exactly once; posted entry is balanced Dr Deferred/Cr Revenue and moves both account balances; only-`active` guard; the create form's service routing generates the entries; that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** RevenueSchedule + entry models ✓ (T1); straight-line split summing to total with remainder-on-last ✓ (T2); monthly recognition dates ✓ (T2); one-schedule-per-invoice + periods≥1 guards ✓ (T2); catch-up GL posting Dr Deferred/Cr Revenue via the engine ✓ (T3); team_id + user_id explicit stamping (no team-1 leak, non-null FK) ✓ (T3 + Global Constraints); idempotency + completed status + only-active guard ✓ (T3); scheduled command ✓ (T3); Filament UI routing creation through the service ✓ (T4); tenancy ✓ (T4 test + T1 hook). Deferred (milestone/proration/defer-on-invoice/subscription-auto/reversal) intentionally absent.
- **Placeholders:** none in T1-T3 (full code). T4 Step 3 gives the exact fields/columns and the verbatim `handleRecordCreation` override, pointing to concrete in-repo resources for the Filament-v5 boilerplate.
- **Type consistency:** `createFromInvoice(Invoice,int,Account,Account): RevenueSchedule` and `recognizeDue(RevenueSchedule): int` identical across T2/T3/T4; column names (`recognition_date`, `recognized`, `journal_entry_id`, `deferred_account_id`, `revenue_account_id`, `period_number`, `status`) consistent T1↔T2↔T3↔T4; JournalEntry line fields (`account_id`, `debit_amount`, `credit_amount`, `description`) match the mapped model.
