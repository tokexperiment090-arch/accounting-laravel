# Subscription Core Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Plans + subscriptions that generate a draft invoice per due billing cycle (catch-up), the first slice of the P2-1 subscription-billing engine.

**Architecture:** `Plan` (price + interval) and `Subscription` (customer + plan + status + next_billing_date). A `SubscriptionBillingService` walks each active subscription's due cycles (mirroring `App\Concerns\Recurring`), generating a draft `Invoice` + `InvoiceItem` per cycle and advancing `next_billing_date`. A `subscriptions:process` command runs it daily. Filament resources manage plans + subscriptions. Proration, upgrades/downgrades, payment providers, and invoice posting/emailing are deferred to later slices.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- `Plan` + `Subscription` use `App\Traits\IsTenantModel` + a `creating` hook stamping `team_id` from `auth()->user()?->currentTeam` when empty.
- `interval` values are exactly `['daily','weekly','monthly','yearly']` (same vocabulary as `App\Concerns\Recurring`).
- Generated invoices are DRAFTS: `payment_status = 'pending'`, no GL post, no email. `invoice_number` is left null so `Invoice`'s `creating` hook fills it. The invoice's `team_id` is copied from the subscription (invoices have a real FK on `team_id`; never rely on the DB default).
- Money columns `decimal(15,2)`. Safety cap = 120 cycles per run.
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml** — create the required real row instead. `Model::unguard()` is global. No `TeamFactory` — `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`. `Customer::factory()` exists. `invoices.team_id` has a real FK to `teams`.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: Plan + Subscription models & migrations

**Files:**
- Create: `src/database/migrations/2026_07_09_100001_create_plans_table.php`
- Create: `src/database/migrations/2026_07_09_100002_create_subscriptions_table.php`
- Create: `src/app/Models/Plan.php`
- Create: `src/app/Models/Subscription.php`
- Test: `src/tests/Feature/Subscription/SubscriptionModelTest.php`

**Interfaces:**
- Produces: `Plan` (PK `id`; fillable `name, amount, currency, interval, team_id`; `amount` cast `decimal:2`; `subscriptions()` hasMany). `Subscription` (PK `id`; fillable `customer_id, plan_id, status, started_at, next_billing_date, last_billed_at, cancelled_at, team_id`; `started_at`/`next_billing_date`/`last_billed_at`/`cancelled_at` cast `date`/`datetime`; `customer()` + `plan()` belongsTo; `pause()`/`resume()`/`cancel()` methods setting `status`).

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Subscription/SubscriptionModelTest.php
declare(strict_types=1);
namespace Tests\Feature\Subscription;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_subscription_links_customer_and_plan_and_can_be_cancelled(): void
    {
        $customer = Customer::factory()->create();
        $plan = Plan::create(['name' => 'Pro', 'amount' => 50, 'interval' => 'monthly']);
        $sub = Subscription::create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id,
            'status' => 'active', 'started_at' => '2026-06-01', 'next_billing_date' => '2026-07-01',
        ]);

        $this->assertTrue($sub->plan->is($plan));
        $this->assertTrue($sub->customer->is($customer));

        $sub->cancel();
        $this->assertSame('cancelled', $sub->fresh()->status);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=SubscriptionModelTest`
Expected: FAIL (`App\Models\Plan` not found).

- [ ] **Step 3: Create the migrations**

```php
<?php // 2026_07_09_100001_create_plans_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('plans', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->decimal('amount', 15, 2)->default(0);
            $t->string('currency')->default('USD');
            $t->string('interval')->default('monthly'); // daily, weekly, monthly, yearly
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('plans'); }
};
```

```php
<?php // 2026_07_09_100002_create_subscriptions_table.php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $t->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $t->string('status')->default('active'); // active, paused, cancelled, expired
            $t->date('started_at')->nullable();
            $t->date('next_billing_date')->nullable();
            $t->date('last_billed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignId('team_id')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('subscriptions'); }
};
```

- [ ] **Step 4: Create the models**

```php
<?php // src/app/Models/Plan.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = ['name', 'amount', 'currency', 'interval', 'team_id'];

    #[\Override]
    protected $casts = ['amount' => 'decimal:2'];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Plan $plan): void {
            if (empty($plan->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $plan->team_id = $team->getKey();
            }
        });
    }

    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
}
```

```php
<?php // src/app/Models/Subscription.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'customer_id', 'plan_id', 'status', 'started_at', 'next_billing_date', 'last_billed_at', 'cancelled_at', 'team_id',
    ];

    #[\Override]
    protected $casts = [
        'started_at' => 'date',
        'next_billing_date' => 'date',
        'last_billed_at' => 'date',
        'cancelled_at' => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Subscription $subscription): void {
            if (empty($subscription->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $subscription->team_id = $team->getKey();
            }
        });
    }

    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function plan(): BelongsTo { return $this->belongsTo(Plan::class); }

    public function pause(): void { $this->update(['status' => 'paused']); }
    public function resume(): void { $this->update(['status' => 'active']); }
    public function cancel(): void { $this->update(['status' => 'cancelled', 'cancelled_at' => now()]); }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=SubscriptionModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Models/Plan.php app/Models/Subscription.php database/migrations/2026_07_09_1000*.php tests/Feature/Subscription/SubscriptionModelTest.php
git -C src commit -m "feat(subscription): plan + subscription models"
```

---

### Task 2: SubscriptionBillingService (catch-up → draft invoice)

**Files:**
- Create: `src/app/Services/SubscriptionBillingService.php`
- Test: `src/tests/Feature/Subscription/BillingServiceTest.php`

**Interfaces:**
- Consumes: `Subscription`, `Plan` (Task 1), `App\Models\Invoice` (fillable incl. `customer_id, invoice_date, due_date, total_amount, payment_status, team_id`; `items()` hasMany InvoiceItem; `creating` hook auto-numbers). `InvoiceItem` fillable: `invoice_id, account_id, description, quantity, unit_price, amount, tax_amount, tax_rate_id`.
- Produces: `SubscriptionBillingService::generateDueInvoices(Subscription $subscription): int` — returns 0 unless `status === 'active'` and `next_billing_date` set; otherwise a catch-up loop (cap 120): while `next_billing_date <= today` → make a draft `Invoice` dated the cycle date (`due_date` = +30 days, `total_amount` = plan amount, `payment_status` = 'pending', `team_id` from the subscription) with one `InvoiceItem` (description = plan name, quantity 1, unit_price/amount = plan amount); advance `next_billing_date` by one interval and set `last_billed_at`, persisting per cycle. Returns the count generated.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Subscription/BillingServiceTest.php
declare(strict_types=1);
namespace Tests\Feature\Subscription;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activeSub(string $status = 'active', string $nextBilling = '2026-03-15'): Subscription
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $plan = Plan::create(['name' => 'Pro', 'amount' => 50, 'interval' => 'monthly', 'team_id' => $team->id]);

        return Subscription::create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id, 'status' => $status,
            'started_at' => '2026-03-15', 'next_billing_date' => $nextBilling, 'team_id' => $team->id,
        ]);
    }

    public function test_catch_up_generates_a_draft_invoice_per_missed_cycle(): void
    {
        $sub = $this->activeSub(); // next_billing 2026-03-15, monthly, today 2026-06-15

        // Cycles due: 03-15, 04-15, 05-15, 06-15 (<= today) = 4.
        $count = app(SubscriptionBillingService::class)->generateDueInvoices($sub);

        $this->assertSame(4, $count);
        $invoices = Invoice::where('customer_id', $sub->customer_id)->get();
        $this->assertCount(4, $invoices);
        $first = $invoices->first();
        $this->assertSame('pending', $first->payment_status);
        $this->assertSame($sub->team_id, (int) $first->team_id);
        $this->assertSame('50.00', (string) $first->total_amount);
        $this->assertCount(1, $first->items);
        $this->assertSame('Pro', $first->items->first()->description);

        // Idempotent: re-run generates nothing new.
        $this->assertSame(0, app(SubscriptionBillingService::class)->generateDueInvoices($sub->fresh()));
    }

    public function test_paused_or_cancelled_subscription_bills_nothing(): void
    {
        $paused = $this->activeSub('paused');
        $cancelled = $this->activeSub('cancelled');

        $this->assertSame(0, app(SubscriptionBillingService::class)->generateDueInvoices($paused));
        $this->assertSame(0, app(SubscriptionBillingService::class)->generateDueInvoices($cancelled));
    }

    public function test_safety_cap_bounds_a_run(): void
    {
        $sub = $this->activeSub('active', '2020-01-15'); // far in the past, daily-ish overflow of cycles
        $sub->plan->update(['interval' => 'daily']);

        $count = app(SubscriptionBillingService::class)->generateDueInvoices($sub->fresh());

        $this->assertSame(120, $count); // capped
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=BillingServiceTest`
Expected: FAIL (`App\Services\SubscriptionBillingService` not found).

- [ ] **Step 3: Implement the service**

```php
<?php // src/app/Services/SubscriptionBillingService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionBillingService
{
    private const SAFETY_CAP = 120;

    public function generateDueInvoices(Subscription $subscription): int
    {
        if ($subscription->status !== 'active' || $subscription->next_billing_date === null) {
            return 0;
        }

        $plan = $subscription->plan;
        if ($plan === null) {
            return 0;
        }

        $today = today();
        $count = 0;

        while ($subscription->next_billing_date->lte($today)) {
            if ($count >= self::SAFETY_CAP) {
                break;
            }

            $cycleDate = $subscription->next_billing_date->copy();

            // One draft invoice + line item + advance, atomic per cycle (crash-safe).
            DB::transaction(function () use ($subscription, $plan, $cycleDate): void {
                $invoice = Invoice::create([
                    'customer_id' => $subscription->customer_id,
                    'invoice_date' => $cycleDate,
                    'due_date' => $cycleDate->copy()->addDays(30),
                    'total_amount' => $plan->amount,
                    'payment_status' => 'pending',
                    'team_id' => $subscription->team_id,
                ]);
                $invoice->items()->create([
                    'description' => $plan->name,
                    'quantity' => 1,
                    'unit_price' => $plan->amount,
                    'amount' => $plan->amount,
                    'tax_amount' => 0,
                ]);

                $subscription->last_billed_at = $cycleDate;
                $subscription->next_billing_date = $this->nextDate($cycleDate, (string) $plan->interval);
                $subscription->save();
            });

            $count++;
        }

        return $count;
    }

    private function nextDate(Carbon $from, string $interval): Carbon
    {
        return match ($interval) {
            'daily' => $from->copy()->addDay(),
            'weekly' => $from->copy()->addWeek(),
            'yearly' => $from->copy()->addYear(),
            default => $from->copy()->addMonth(),
        };
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=BillingServiceTest`
Expected: PASS (3 tests). If `InvoiceItem` requires a column you omitted, read `app/Models/InvoiceItem.php` `$fillable` — it is `invoice_id, account_id, description, quantity, unit_price, amount, tax_amount, tax_rate_id`; the create above sets the needed ones.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/SubscriptionBillingService.php tests/Feature/Subscription/BillingServiceTest.php
git -C src commit -m "feat(subscription): catch-up billing to draft invoice"
```

---

### Task 3: subscriptions:process command

**Files:**
- Create: `src/app/Console/Commands/ProcessSubscriptions.php`
- Modify: `src/bootstrap/app.php` (schedule `subscriptions:process` daily inside the existing `->withSchedule(...)` closure)
- Test: `src/tests/Feature/Subscription/ProcessSubscriptionsTest.php`

**Interfaces:**
- Consumes: `SubscriptionBillingService` (Task 2), `Subscription`.
- Produces: `subscriptions:process` — iterates `active` subscriptions calling `generateDueInvoices`, logs the total generated.

- [ ] **Step 1: Write the failing test**

```php
<?php // src/tests/Feature/Subscription/ProcessSubscriptionsTest.php
declare(strict_types=1);
namespace Tests\Feature\Subscription;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessSubscriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_bills_all_active_due_subscriptions(): void
    {
        Carbon::setTestNow('2026-06-15');
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $plan = Plan::create(['name' => 'Pro', 'amount' => 20, 'interval' => 'monthly', 'team_id' => $team->id]);
        Subscription::create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id, 'status' => 'active',
            'started_at' => '2026-05-15', 'next_billing_date' => '2026-05-15', 'team_id' => $team->id,
        ]);

        $this->artisan('subscriptions:process')->assertSuccessful();

        // Cycles 05-15 and 06-15 due → 2 invoices.
        $this->assertSame(2, Invoice::where('customer_id', $customer->id)->count());
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=ProcessSubscriptionsTest`
Expected: FAIL (command `subscriptions:process` not defined).

- [ ] **Step 3: Create the command**

```php
<?php // src/app/Console/Commands/ProcessSubscriptions.php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\Subscription;
use App\Services\SubscriptionBillingService;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    #[\Override]
    protected $signature = 'subscriptions:process';
    #[\Override]
    protected $description = 'Generate draft invoices for all active subscriptions with due billing cycles';

    public function handle(SubscriptionBillingService $service): void
    {
        $total = 0;
        Subscription::where('status', 'active')->each(function (Subscription $subscription) use (&$total, $service): void {
            $total += $service->generateDueInvoices($subscription);
        });
        $this->info("Generated {$total} subscription invoice(s).");
    }
}
```

- [ ] **Step 4: Schedule it**

In `src/bootstrap/app.php`, inside the existing `->withSchedule(function (Schedule $schedule): void { ... })` closure, add:

```php
        $schedule->command('subscriptions:process')->daily();
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=ProcessSubscriptionsTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Console/Commands/ProcessSubscriptions.php bootstrap/app.php tests/Feature/Subscription/ProcessSubscriptionsTest.php
git -C src commit -m "feat(subscription): scheduled process command"
```

---

### Task 4: Filament Plan + Subscription resources

**Files:**
- Create: `src/app/Filament/App/Resources/Plans/PlanResource.php` (+ List/Create/Edit pages)
- Create: `src/app/Filament/App/Resources/Subscriptions/SubscriptionResource.php` (+ List/Create/Edit pages)
- Test: `src/tests/Feature/Subscription/SubscriptionTenancyTest.php`

**Interfaces:**
- Consumes: `Plan`, `Subscription` (Task 1), `SubscriptionBillingService` (Task 2).
- Produces: team-scoped `PlanResource` (name, amount, currency, interval Select) and `SubscriptionResource` (customer + plan Selects, `next_billing_date`, `status` read-only) with `pause`/`resume`/`cancel` row actions calling the model methods.

- [ ] **Step 1: Write the failing test (tenancy stamp)**

```php
<?php // src/tests/Feature/Subscription/SubscriptionTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_stamps_actor_team(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $plan = Plan::create(['name' => 'Pro', 'amount' => 10, 'interval' => 'monthly']);

        $this->assertSame($team->id, (int) $plan->team_id);
    }
}
```

- [ ] **Step 2: Run it, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=SubscriptionTenancyTest`
Expected: PASS (Task 1's `creating` hook stamps `team_id`).

- [ ] **Step 3: Build the Filament resources**

Read an existing app-panel resource first — `src/app/Filament/App/Resources/ConsolidationGroups/ConsolidationGroupResource.php` (for the Resource + pages shape) and one with a `Select` relationship + row action (e.g. `SalesOrders/SalesOrderResource.php` for the action pattern) — and mirror the exact Filament v5 API (`Filament\Schemas\Schema`, `Filament\Forms\Components\Select`/`TextInput`, table, `getPages`, `recordActions`, `#[\Override]`).

`PlanResource` (`$model = Plan::class`, tenant-scoped by default): form `name` (TextInput required), `amount` (numeric required), `currency` (TextInput default USD), `interval` (Select `['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly']` required). Table: name, amount (money), interval.

`SubscriptionResource` (`$model = Subscription::class`): form `customer_id` (Select relationship `customer`/`customer_name` required), `plan_id` (Select relationship `plan`/`name` required), `next_billing_date` (DatePicker), `status` (Select of active/paused/cancelled/expired, `->disabled()->dehydrated(false)` — system-managed). Table: customer name, plan name, status (badge), next_billing_date. Row actions `pause` / `resume` / `cancel`, each `->requiresConfirmation()->action(fn (Subscription $r) => $r->pause()/resume()/cancel())` with sensible `->visible()` (e.g. pause only when active, resume only when paused). Create the List/Create/Edit pages mirroring an existing resource.

- [ ] **Step 4: Run all subscription tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=Subscription`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/Plans app/Filament/App/Resources/Subscriptions tests/Feature/Subscription/SubscriptionTenancyTest.php
git -C src commit -m "feat(subscription): Filament plan + subscription UI"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=Subscription`.
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline if only Filament/Eloquent-`mixed` idiom errors remain.
- Pint the new files.
- Adversarial review focus: only `active` subscriptions bill; idempotent next_billing_date advance (no double-billing, no skipped period); generated invoice's `team_id` from the subscription (not the DB default); catch-up + safety-cap correctness; that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** Plan + Subscription models ✓ (T1); interval enum ✓ (T1); status lifecycle + pause/resume/cancel ✓ (T1 + T4 actions); catch-up draft-invoice generation with plan line item + team ✓ (T2); safety cap + idempotency ✓ (T2); only-active guard ✓ (T2); scheduled command ✓ (T3); Filament plan + subscription UI ✓ (T4); tenancy ✓ (T4 test + T1 hook). Deferred (proration/upgrades/providers/posting) intentionally absent.
- **Placeholders:** none in T1-T3 (full code). T4 Step 3 gives the fields/options/actions verbatim and points to concrete in-repo resources to mirror for the Filament-v5 boilerplate.
- **Type consistency:** `generateDueInvoices(Subscription): int` identical in T2/T3/T4; `next_billing_date`/`last_billed_at`/`status`/`interval` column + value names consistent T1↔T2↔T3↔T4; `nextDate` interval `match` uses the same four interval strings as the plan column.
