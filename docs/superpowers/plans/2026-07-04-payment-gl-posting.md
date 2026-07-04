# Payment → GL Posting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post a customer payment to the general ledger as a balanced journal entry — Dr Cash / Cr Accounts Receivable — and update the invoice's payment status, closing the AR lifecycle.

**Architecture:** A `PaymentPostingService::post(Payment): JournalEntry` mirrors the existing `InvoicePostingService`: resolve Cash (1000) + AR (1100) from the team's provisioned chart, build a balanced `JournalEntry` via `forceFill`, `post()` it, link `payments.journal_entry_id` (idempotent, `lockForUpdate`-guarded), then recompute the invoice's `payment_status` from the sum of its payments. A `payments:post {payment}` command and a "Post to ledger" Filament action are thin wrappers.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- The posting `JournalEntry`'s `team_id`/`user_id` are NOT fillable and there is no reliable `auth()` (command/ops context). Set both via `forceFill([... 'team_id' => $payment->team_id, 'user_id' => $payment->team?->user_id ...])` before `->post()` — `team_id` DB-defaults to `1` (a cross-team leak) and `user_id` is a non-nullable FK. `Payment` uses `IsTenantModel`, so `$payment->team` resolves. Same trap already handled in `InvoicePostingService`.
- Posted entry is **exactly two lines**: **Dr Cash / Cr Accounts Receivable** for `payment_amount`. `JournalEntry::post()` validates `isBalanced()` and updates account balances; call it once.
- Accounts resolved by `(team_id, account_number)`: **Cash = 1000**, **AR = 1100** (the P2-6 provisioned chart). Missing account → throw `\RuntimeException` ("provision the chart first").
- **Idempotent:** if `payment.journal_entry_id` is already set, return the linked entry and post nothing. The whole build+post+link+status-update runs in one `DB::transaction`, with a `lockForUpdate` re-check inside to close the double-post race.
- **Payment status:** after linking, recompute the invoice's `payment_status` from `sum(invoice.payments.payment_amount)` vs `total_amount`: `>= total (and total > 0)` → `'paid'`, `> 0` → `'partial'` (mirrors `App\Models\BillPayment`'s AP logic). **Overpayment posts the full amount** (AR may go negative — a customer-credit state); no validation.
- `Payment`'s primary key is `payment_id` (not `id`). `Payment::find($id)` / `$payment->getKey()` use it.
- Tests: sqlite `:memory:` ENFORCES FKs (`payments.team_id` + `accounts.team_id`/`user_id` are real FKs; `payments.invoice_id` is NOT an FK). **Never disable FK enforcement / never edit phpunit.xml / never weaken a guard.** No `TeamFactory` — `Team::forceCreate([...])`. No `PaymentFactory` — build via `Payment::create([...])`. Seed the chart with `app(TenantProvisioningService::class)->provisionChartOfAccounts($team)`.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: payments.journal_entry_id migration + relations & casts

**Files:**
- Create: `src/database/migrations/2026_07_12_100001_add_journal_entry_id_to_payments_table.php`
- Modify: `src/app/Models/Payment.php` (add `journal_entry_id` to fillable; add casts; add `journalEntry()`)
- Modify: `src/app/Models/Invoice.php` (add `payments()` hasMany)
- Test: `src/tests/Feature/PaymentPosting/PaymentModelTest.php`

**Interfaces:**
- Produces: `payments.journal_entry_id` (nullable FK → `journal_entries`, `nullOnDelete`); `Payment::journalEntry(): BelongsTo`, `journal_entry_id` in fillable, casts `payment_amount => decimal:2` + `payment_date => date`; `Invoice::payments(): HasMany` (FK `invoice_id`).

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PaymentPosting;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_links_journal_entry_and_invoice_has_payments(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 100]);
        $payment = Payment::create([
            'invoice_id' => $invoice->id, 'payment_amount' => 60, 'payment_date' => '2026-06-05', 'team_id' => $team->id,
        ]);
        $this->actingAs($user);
        $entry = JournalEntry::create(['entry_date' => '2026-06-05', 'entry_type' => 'general']);

        $payment->update(['journal_entry_id' => $entry->id]);

        $this->assertTrue($payment->fresh()->journalEntry->is($entry));
        $this->assertTrue($invoice->payments()->first()->is($payment));
        $this->assertSame('60.00', (string) $payment->fresh()->payment_amount);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PaymentModelTest`
Expected: FAIL (unknown column `journal_entry_id` / relation not defined).

- [ ] **Step 3: Create the migration**

```php
<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->foreignId('journal_entry_id')->nullable()->after('payment_id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
```

- [ ] **Step 4: Wire the models**

In `src/app/Models/Payment.php`: add `'journal_entry_id'` to the `$fillable` array; add a `$casts` (the model currently has none) and the relation:

```php
    protected $casts = [
        'payment_amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
```

(Ensure `use App\Models\JournalEntry;` is present, or reference `\App\Models\JournalEntry::class` — the model is in the same `App\Models` namespace, so `JournalEntry::class` resolves.)

In `src/app/Models/Invoice.php`: add a `payments()` relation (near `items()`):

```php
    public function payments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }
```

(Ensure `use App\Models\Payment;` is present or use `\App\Models\Payment::class` — same namespace, so `Payment::class` resolves.)

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=PaymentModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add database/migrations/2026_07_12_100001_add_journal_entry_id_to_payments_table.php app/Models/Payment.php app/Models/Invoice.php tests/Feature/PaymentPosting/PaymentModelTest.php
git -C src commit -m "feat(payment-post): link payment to journal entry"
```

---

### Task 2: PaymentPostingService (Dr Cash / Cr AR) + status recompute

**Files:**
- Create: `src/app/Services/PaymentPostingService.php`
- Test: `src/tests/Feature/PaymentPosting/PostPaymentTest.php`

**Interfaces:**
- Consumes: `Payment` (`payment_amount`, `payment_date`, `team_id`, `team->user_id`, `journal_entry_id`, `journalEntry()`, `invoice()`) from Task 1; `Invoice` (`total_amount`, `payments()`) from Task 1; `Account` (`(team_id, account_number)`); `JournalEntry` (`forceFill`, `lines()->create`, `post()`); `TenantProvisioningService` (test setup).
- Produces: `PaymentPostingService::post(Payment $payment): JournalEntry` — idempotent (returns the linked entry if already posted); otherwise builds+posts a balanced 2-line entry (Dr Cash 1000 / Cr AR 1100 for `payment_amount`), stamps team_id/user_id, links `payment.journal_entry_id`, then recomputes the invoice's `payment_status`; throws `\RuntimeException` if a required account is absent.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PaymentPosting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentPostingService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PostPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Team,1:Invoice} */
    private function provisioned(float $invoiceTotal = 500): array
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'total_amount' => $invoiceTotal, 'payment_status' => 'pending']);

        return [$team, $invoice];
    }

    private function payment(Team $team, Invoice $invoice, float $amount): Payment
    {
        return Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => $amount, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);
    }

    private function account(Team $team, int $number): Account
    {
        return Account::where('team_id', $team->id)->where('account_number', $number)->firstOrFail();
    }

    public function test_posts_dr_cash_cr_ar_and_marks_invoice_paid(): void
    {
        [$team, $invoice] = $this->provisioned(500);
        $payment = $this->payment($team, $invoice, 500);

        $entry = app(PaymentPostingService::class)->post($payment);

        $this->assertTrue($entry->is_posted);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame($team->id, (int) $entry->team_id);
        $this->assertSame($team->user_id, (int) $entry->user_id);
        $this->assertSame(2, $entry->lines()->count());
        // Cash (1000, asset) debited 500; AR (1100, asset) credited 500
        $this->assertSame('500.00', number_format((float) $this->account($team, 1000)->balance, 2, '.', ''));
        $this->assertSame('-500.00', number_format((float) $this->account($team, 1100)->balance, 2, '.', ''));
        $this->assertSame($entry->id, $payment->fresh()->journal_entry_id);
        // invoice fully paid
        $this->assertSame('paid', $invoice->fresh()->payment_status);
    }

    public function test_partial_payment_marks_invoice_partial(): void
    {
        [$team, $invoice] = $this->provisioned(500);
        $payment = $this->payment($team, $invoice, 200);

        app(PaymentPostingService::class)->post($payment);

        $this->assertSame('partial', $invoice->fresh()->payment_status);
    }

    public function test_is_idempotent(): void
    {
        [$team, $invoice] = $this->provisioned(300);
        $payment = $this->payment($team, $invoice, 300);

        $first = app(PaymentPostingService::class)->post($payment);
        $second = app(PaymentPostingService::class)->post($payment->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('team_id', $team->id)->count());
    }

    public function test_overpayment_posts_full_amount_and_marks_paid(): void
    {
        [$team, $invoice] = $this->provisioned(100);
        $payment = $this->payment($team, $invoice, 150);

        app(PaymentPostingService::class)->post($payment);

        $this->assertSame('150.00', number_format((float) $this->account($team, 1000)->balance, 2, '.', ''));
        $this->assertSame('-150.00', number_format((float) $this->account($team, 1100)->balance, 2, '.', ''));
        $this->assertSame('paid', $invoice->fresh()->payment_status);
    }

    public function test_throws_when_chart_not_provisioned(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Bare', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 100]);
        $payment = $this->payment($team, $invoice, 100);

        $this->expectException(RuntimeException::class);
        app(PaymentPostingService::class)->post($payment);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentTest`
Expected: FAIL (`App\Services\PaymentPostingService` not found).

- [ ] **Step 3: Implement the service**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer payment to the general ledger as a balanced journal entry:
 * Dr Cash (1000) / Cr Accounts Receivable (1100) for the payment amount, then
 * recomputes the invoice's payment_status from the sum of its payments.
 *
 * ponytail: overpayment posts the full amount (AR can go negative — a customer
 * credit); allocation across multiple invoices + reversal are later slices.
 */
class PaymentPostingService
{
    public function post(Payment $payment): JournalEntry
    {
        if ($payment->journal_entry_id !== null) {
            $existing = $payment->journalEntry;

            return $existing instanceof JournalEntry ? $existing : throw new RuntimeException('Payment linked to a missing journal entry.');
        }

        $teamId = (int) $payment->team_id;
        $cash = $this->resolveByNumber($teamId, 1000);
        $receivable = $this->resolveByNumber($teamId, 1100);
        $amount = $payment->payment_amount;

        return DB::transaction(function () use ($payment, $cash, $receivable, $amount, $teamId): JournalEntry {
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();
            if ($locked instanceof Payment && $locked->journal_entry_id !== null) {
                $existing = $locked->journalEntry;

                return $existing instanceof JournalEntry ? $existing : throw new RuntimeException('Payment linked to a missing journal entry.');
            }

            $entry = new JournalEntry;
            $entry->forceFill([
                'entry_date' => $payment->payment_date,
                'entry_type' => 'general',
                'reference_number' => (string) $payment->getKey(),
                'memo' => 'Payment '.$payment->getKey(),
                'team_id' => $teamId,
                'user_id' => $payment->team?->user_id,
            ])->save();

            $entry->lines()->create([
                'account_id' => $cash->getKey(),
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'description' => 'Cash',
            ]);
            $entry->lines()->create([
                'account_id' => $receivable->getKey(),
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'description' => 'Accounts Receivable',
            ]);

            $entry->post();

            $payment->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            $this->updateInvoiceStatus($payment);

            return $entry;
        });
    }

    private function updateInvoiceStatus(Payment $payment): void
    {
        $invoice = $payment->invoice;
        if (! $invoice instanceof Invoice) {
            return;
        }

        $paid = (float) $invoice->payments()->sum('payment_amount');
        $total = (float) $invoice->total_amount;

        if ($total > 0.0 && $paid >= $total) {
            $invoice->payment_status = 'paid';
        } elseif ($paid > 0.0) {
            $invoice->payment_status = 'partial';
        }

        $invoice->save();
    }

    private function resolveByNumber(int $teamId, int $number): Account
    {
        $account = Account::where('team_id', $teamId)->where('account_number', $number)->first();
        if (! $account instanceof Account) {
            throw new RuntimeException("Account {$number} not found for team {$teamId}. Provision the chart of accounts first (tenants:provision-chart).");
        }

        return $account;
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentTest`
Expected: PASS (5 tests). Cash is a debit-normal asset so its balance rises by the debit; AR is a debit-normal asset so crediting it drives the balance negative (the receivable is being cleared / overpaid).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/PaymentPostingService.php tests/Feature/PaymentPosting/PostPaymentTest.php
git -C src commit -m "feat(payment-post): dr cash / cr AR posting"
```

---

### Task 3: payments:post command

**Files:**
- Create: `src/app/Console/Commands/PostPayment.php`
- Test: `src/tests/Feature/PaymentPosting/PostPaymentCommandTest.php`

**Interfaces:**
- Consumes: `PaymentPostingService::post(Payment): JournalEntry` (Task 2); `App\Models\Payment`.
- Produces: `payments:post {payment}` — resolves the payment by id (the `payment_id` PK), posts it, prints the entry id (or "already posted"); unknown id → non-zero exit; missing chart (`RuntimeException`) → caught, printed, non-zero exit.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PaymentPosting;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostPaymentCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_posts_the_payment_by_id(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 400]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 400, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);

        $this->artisan('payments:post', ['payment' => $payment->payment_id])->assertSuccessful();

        $this->assertNotNull($payment->fresh()->journal_entry_id);
        $this->assertSame(1, JournalEntry::where('team_id', $team->id)->count());
    }

    public function test_command_fails_for_unknown_payment(): void
    {
        $this->artisan('payments:post', ['payment' => 999999])->assertFailed();
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentCommandTest`
Expected: FAIL (command `payments:post` not defined).

- [ ] **Step 3: Create the command**

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PaymentPostingService;
use Illuminate\Console\Command;
use RuntimeException;

class PostPayment extends Command
{
    #[\Override]
    protected $signature = 'payments:post {payment : Payment ID}';

    #[\Override]
    protected $description = 'Post a payment to the general ledger (Dr Cash / Cr AR)';

    public function handle(PaymentPostingService $service): int
    {
        $payment = Payment::find($this->argument('payment'));
        if (! $payment instanceof Payment) {
            $this->error("Payment {$this->argument('payment')} not found.");

            return self::FAILURE;
        }

        if ($payment->journal_entry_id !== null) {
            $this->info("Payment {$payment->getKey()} already posted (entry {$payment->journal_entry_id}); skipped.");

            return self::SUCCESS;
        }

        try {
            $entry = $service->post($payment);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Posted payment {$payment->getKey()} to ledger (entry {$entry->id}).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentCommandTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Console/Commands/PostPayment.php tests/Feature/PaymentPosting/PostPaymentCommandTest.php
git -C src commit -m "feat(payment-post): payments:post command"
```

---

### Task 4: Filament "Post to ledger" action

**Files:**
- Modify: `src/app/Filament/App/Resources/Payments/PaymentResource.php` (add a row action)
- Test: `src/tests/Feature/PaymentPosting/PostPaymentActionTest.php`

**Interfaces:**
- Consumes: `PaymentPostingService::post(Payment): JournalEntry` (Task 2); the Payment record.
- Produces: a `postToLedger` table row action that posts the record, shows a notification, and hides once posted (`journal_entry_id` set).

- [ ] **Step 1: Write the failing test**

Mirror `src/tests/Feature/Approval/ApprovalRuleResourceTest.php` for the tenant/panel setup + Filament table-action calling convention. The test:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PaymentPosting;

use App\Filament\App\Resources\Payments\Pages\ListPayments;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PostPaymentActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_posts_the_payment_to_the_ledger(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->teams()->attach($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 250]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 250, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);

        Filament::setTenant($team);
        $this->actingAs($user);

        Livewire::test(ListPayments::class)
            ->callTableAction('postToLedger', $payment);

        $this->assertNotNull($payment->fresh()->journal_entry_id);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentActionTest`
Expected: FAIL (table action `postToLedger` not registered).

If the failure is on tenant/panel setup rather than the missing action, copy the exact membership + `actingAs` (BEFORE `Filament::setTenant`) sequence from `tests/Feature/Approval/ApprovalRuleResourceTest.php`. Do NOT add Livewire retries/timeouts; if the panel setup fights back after mirroring that file, report BLOCKED rather than flailing.

- [ ] **Step 3: Add the row action**

Read `src/app/Filament/App/Resources/Payments/PaymentResource.php` — its table has `->recordActions([EditAction::make(), ...])`. Mirror the exact `postToLedger` action from `src/app/Filament/App/Resources/Invoices/InvoiceResource.php` (the invoice-posting sibling), adapted to `Payment`. Add to the `recordActions` array:

```php
                Action::make('postToLedger')
                    ->label('Post to ledger')
                    ->icon('heroicon-o-book-open')
                    ->requiresConfirmation()
                    ->visible(fn (Payment $record): bool => $record->journal_entry_id === null)
                    ->action(function (Payment $record): void {
                        try {
                            $entry = app(\App\Services\PaymentPostingService::class)->post($record);
                            \Filament\Notifications\Notification::make()
                                ->title("Posted to ledger (entry {$entry->id}).")
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Cannot post payment')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
```

Ensure `use Filament\Actions\Action;` and `use App\Models\Payment;` are imported (the file already imports `Filament\Actions\EditAction`; add `Action` + `Payment` if missing). Do NOT remove or alter the existing actions.

- [ ] **Step 4: Run the test + the whole feature group, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentActionTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=PaymentPosting`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/Payments/PaymentResource.php tests/Feature/PaymentPosting/PostPaymentActionTest.php
git -C src commit -m "feat(payment-post): post-to-ledger Filament action"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`. (Confirm the new `Payment` casts didn't disturb `tests/Feature/Api/QboPaymentSyncTest.php` — it reads `qbo_id` + uses `assertDatabaseHas`, both cast-independent, so it should stay green.)
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter=PaymentPosting`. (New FK migration — MySQL enforces FK column-type match + the auto index name length.)
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline (`--generate-baseline phpstan-baseline.neon`) only if the ONLY remaining errors are the Filament/Eloquent-`mixed` idiom on the new files — verify each before baselining.
- Pint the new/changed files.
- Adversarial review focus: every posting entry carries the payment's `team_id` (never default team 1) + a non-null `user_id`; the entry is balanced Dr Cash / Cr AR and `post()` moved both account balances; idempotent (`journal_entry_id` guard + `lockForUpdate` re-check prevents a 2nd entry, even command+action racing); missing-chart throws (no silent team-1 post); `payment_status` recompute uses `sum(payments)` vs `total_amount` correctly (paid/partial, no divide-by-zero on a zero-total invoice); overpayment posts the full amount (AR negative allowed, marked paid); the books-lock `saving` guard still applies; that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** `payments.journal_entry_id` + relations/casts + `Invoice::payments()` ✓ (T1); Dr Cash / Cr AR posting ✓ (T2); account resolution by number + throw-if-missing ✓ (T2); team_id/user_id via forceFill ✓ (T2 + Global Constraints); idempotent + lockForUpdate ✓ (T2); payment_status recompute (paid/partial) ✓ (T2); overpayment full-post ✓ (T2 test); command ✓ (T3); Filament action ✓ (T4). Deferred (reversal, allocation, payment methods) intentionally absent.
- **Placeholders:** none in T1–T3 (full code). T4 gives the exact action code + imports, pointing at the concrete in-repo sibling (`InvoiceResource` `postToLedger`) and a known-working panel test (`ApprovalRuleResourceTest`).
- **Type consistency:** `post(Payment): JournalEntry` identical across T2/T3/T4; `journal_entry_id`/`journalEntry()` consistent T1↔T2↔T3↔T4; `Invoice::payments()` used in T2's recompute matches T1's definition; account numbers 1000/1100 match the chart; `payment_amount`/`payment_id` field names consistent; JournalEntry line fields (`account_id, debit_amount, credit_amount, description`) match the model.
