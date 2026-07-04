# GL Posting Reversal (Unpost) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unpost a posted invoice or payment — reverse its journal entry (undoing the account balances), clear the source's `journal_entry_id` link, and reset the invoice's derived payment status — completing the post↔unpost lifecycle.

**Architecture:** `JournalEntry::reverse()` already un-posts an entry in place (subtracts its balance effect, flips `is_posted` → false); its balance update is the exact inverse of `post()`, so a post→reverse round-trip returns every account to its pre-post balance. A `PostingReversalService` wraps that: guard the source is safe to unpost, call `reverse()`, null the source's `journal_entry_id` (so it's re-postable), and (for payments) recompute the invoice's `payment_status`. A shared `Invoice::recomputePaymentStatus()` handles paid/partial/**pending** in both directions. Commands + Filament "Unpost" actions mirror the shipped posting counterparts.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- Reversal MUTATES the original entry (un-posts it via `JournalEntry::reverse()`); it does NOT create an audit-trail reversing entry (deferred). After reversal the source's `journal_entry_id` is `null`, making it re-postable; the now-unposted original entry row lingers (accepted for this slice).
- `JournalEntry::reverse()` throws a bare `\Exception` if the entry isn't posted, and its `save()` routes through the books-lock `saving()` guard (throws `\DomainException` if the entry date is before the team's `books_locked_before`). The service's own guards throw `\RuntimeException`. Command + Filament callers therefore catch **`\Throwable`** and surface the message (any reversal failure → clean error, never a 500).
- **Invoice reversal guards** (all throw `\RuntimeException`, checked BEFORE any write): not posted (`journal_entry_id === null`); has **posted payments** (`payments()->whereNotNull('journal_entry_id')->exists()` — reverse those first); has **recognized revenue** (`RevenueSchedule::where('invoice_id',$id)->whereHas('entries', fn($q)=>$q->where('recognized',true))->exists()`). **Payment reversal** guards only not-posted.
- `Invoice::recomputePaymentStatus()`: `$paid = sum(posted payments)`, `$total = total_amount`; `$total>0 && $paid>=$total` → `'paid'`, `$paid>0` → `'partial'`, else → `'pending'`. Both `PaymentPostingService` (post) and `PostingReversalService` (reverse) use it — this replaces `PaymentPostingService`'s private one-directional `updateInvoiceStatus`.
- `invoices.journal_entry_id`/`payments.journal_entry_id` are fillable — `$model->journal_entry_id = null; $model->save();` is valid.
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml / never weaken a guard**. Reuse the posting tests' setup: `Team::forceCreate`, `provisionChartOfAccounts`, `Customer::factory`, `Invoice::factory`, `Payment::create`. `Payment` PK is `payment_id`.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: Invoice::recomputePaymentStatus() + refactor posting service

**Files:**
- Modify: `src/app/Models/Invoice.php` (add `recomputePaymentStatus()`)
- Modify: `src/app/Services/PaymentPostingService.php` (delegate `updateInvoiceStatus` to it)
- Test: `src/tests/Feature/PostingReversal/RecomputeStatusTest.php`

**Interfaces:**
- Produces: `Invoice::recomputePaymentStatus(): void` — sets `payment_status` to `'paid'`/`'partial'`/`'pending'` from the sum of **posted** payments (`payments()->whereNotNull('journal_entry_id')->sum('payment_amount')`) vs `total_amount`, and saves. `PaymentPostingService::updateInvoiceStatus(Payment)` now just calls `$payment->invoice?->recomputePaymentStatus()`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PostingReversal;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecomputeStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_recompute_reflects_posted_payments_in_both_directions(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $this->actingAs($user);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 500, 'payment_status' => 'pending']);
        // A posted payment (journal_entry_id set to any non-null id) counts; an unposted one does not.
        $entry = \App\Models\JournalEntry::create(['entry_date' => '2026-06-05', 'entry_type' => 'general']);
        $posted = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 500, 'payment_date' => '2026-06-05', 'team_id' => $team->id, 'journal_entry_id' => $entry->id]);

        $invoice->recomputePaymentStatus();
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        // Unpost it (clear the link) and recompute — must drop back to 'pending'.
        $posted->update(['journal_entry_id' => null]);
        $invoice->recomputePaymentStatus();
        $this->assertSame('pending', $invoice->fresh()->payment_status);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=RecomputeStatusTest`
Expected: FAIL (`recomputePaymentStatus` not defined).

- [ ] **Step 3: Add the method + refactor**

In `src/app/Models/Invoice.php` (near `payments()`):

```php
    public function recomputePaymentStatus(): void
    {
        $paid = (float) $this->payments()->whereNotNull('journal_entry_id')->sum('payment_amount');
        $total = (float) $this->total_amount;

        if ($total > 0.0 && $paid >= $total) {
            $this->payment_status = 'paid';
        } elseif ($paid > 0.0) {
            $this->payment_status = 'partial';
        } else {
            $this->payment_status = 'pending';
        }

        $this->save();
    }
```

In `src/app/Services/PaymentPostingService.php`, replace the body of `updateInvoiceStatus` with a delegation (keep the method + its single call site in `post()`):

```php
    private function updateInvoiceStatus(Payment $payment): void
    {
        $payment->invoice?->recomputePaymentStatus();
    }
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=RecomputeStatusTest`
Then confirm the posting side still passes: `docker compose exec -T php-fpm php artisan test --filter=PostPaymentTest`
Expected: PASS (both). The existing PaymentPosting tests still assert paid/partial — now served by the shared method (the `'pending'` else-branch only changes the previously-uncovered down direction).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Models/Invoice.php app/Services/PaymentPostingService.php tests/Feature/PostingReversal/RecomputeStatusTest.php
git -C src commit -m "refactor(posting): shared invoice status recompute"
```

---

### Task 2: PostingReversalService (reverse invoice + payment)

**Files:**
- Create: `src/app/Services/PostingReversalService.php`
- Test: `src/tests/Feature/PostingReversal/ReverseInvoiceTest.php`
- Test: `src/tests/Feature/PostingReversal/ReversePaymentTest.php`

**Interfaces:**
- Consumes: `Invoice` (`journal_entry_id`, `journalEntry()`, `payments()`, `recomputePaymentStatus()` from Task 1), `Payment`, `JournalEntry::reverse()`, `RevenueSchedule`; `InvoicePostingService`/`PaymentPostingService` (test setup only).
- Produces: `PostingReversalService::reverseInvoice(Invoice $invoice): void` and `reversePayment(Payment $payment): void` — reverse the linked journal entry, clear the source's `journal_entry_id`; `reverseInvoice` guards not-posted / posted-payments / recognized-revenue; `reversePayment` guards not-posted and recomputes the invoice status. Both throw `\RuntimeException` on a failed guard.

- [ ] **Step 1: Write the failing tests**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PostingReversal;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\InvoicePostingService;
use App\Services\PaymentPostingService;
use App\Services\PostingReversalService;
use App\Services\RevenueRecognitionService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReverseInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Team,1:Invoice} */
    private function postedInvoice(float $total = 500): array
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'invoice_date' => '2026-06-01', 'total_amount' => $total]);
        app(InvoicePostingService::class)->post($invoice);

        return [$team, $invoice->fresh()];
    }

    private function account(Team $team, int $number): Account
    {
        return Account::where('team_id', $team->id)->where('account_number', $number)->firstOrFail();
    }

    public function test_reverse_undoes_balances_and_clears_link(): void
    {
        [$team, $invoice] = $this->postedInvoice(500);
        // after posting: AR 1100 = +500, Sales 4000 = +500
        $this->assertSame('500.00', number_format((float) $this->account($team, 1100)->balance, 2, '.', ''));

        app(PostingReversalService::class)->reverseInvoice($invoice);

        $this->assertSame('0.00', number_format((float) $this->account($team, 1100)->balance, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $this->account($team, 4000)->balance, 2, '.', ''));
        $this->assertNull($invoice->fresh()->journal_entry_id);
        // re-postable
        $entry = app(InvoicePostingService::class)->post($invoice->fresh());
        $this->assertNotNull($entry->id);
    }

    public function test_throws_when_not_posted(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 100]);

        $this->expectException(RuntimeException::class);
        app(PostingReversalService::class)->reverseInvoice($invoice);
    }

    public function test_blocked_when_a_posted_payment_exists(): void
    {
        [$team, $invoice] = $this->postedInvoice(500);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 500, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);
        app(PaymentPostingService::class)->post($payment);

        $this->expectException(RuntimeException::class);
        app(PostingReversalService::class)->reverseInvoice($invoice->fresh());
    }

    public function test_blocked_when_revenue_recognized(): void
    {
        [$team, $invoice] = $this->postedInvoice(1200);
        $deferred = $this->account($team, 2400);
        $sales = $this->account($team, 4000);
        $schedule = app(RevenueRecognitionService::class)->createFromInvoice($invoice->fresh(), 12, $deferred, $sales);
        // Recognizing needs the invoice posted (it is). Recognize the due periods.
        app(RevenueRecognitionService::class)->recognizeDue($schedule->fresh());

        $this->expectException(RuntimeException::class);
        app(PostingReversalService::class)->reverseInvoice($invoice->fresh());
    }
}
```

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PostingReversal;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentPostingService;
use App\Services\PostingReversalService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ReversePaymentTest extends TestCase
{
    use RefreshDatabase;

    private function account(Team $team, int $number): Account
    {
        return Account::where('team_id', $team->id)->where('account_number', $number)->firstOrFail();
    }

    public function test_reverse_payment_undoes_balances_clears_link_and_resets_status(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 500, 'payment_status' => 'pending']);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 500, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);
        app(PaymentPostingService::class)->post($payment);
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        app(PostingReversalService::class)->reversePayment($payment->fresh());

        $this->assertSame('0.00', number_format((float) $this->account($team, 1000)->balance, 2, '.', '')); // Cash back to 0
        $this->assertSame('0.00', number_format((float) $this->account($team, 1100)->balance, 2, '.', '')); // AR back to 0
        $this->assertNull($payment->fresh()->journal_entry_id);
        $this->assertSame('pending', $invoice->fresh()->payment_status);
    }

    public function test_throws_when_payment_not_posted(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 100]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 100, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);

        $this->expectException(RuntimeException::class);
        app(PostingReversalService::class)->reversePayment($payment);
    }
}
```

- [ ] **Step 2: Run them, verify they fail**

Run: `docker compose exec -T php-fpm php artisan test --filter=ReverseInvoiceTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=ReversePaymentTest`
Expected: FAIL (`App\Services\PostingReversalService` not found).

- [ ] **Step 3: Implement the service**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\RevenueSchedule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Un-posts a posted invoice or payment: reverses its journal entry (undoing the
 * account balances via JournalEntry::reverse()), clears the source's
 * journal_entry_id so it can be re-posted, and resets derived status.
 *
 * ponytail: mutate-unpost, not an audit-trail reversing entry — the now-unposted
 * original entry lingers. Reversing recognized revenue / cascade are later slices.
 */
class PostingReversalService
{
    public function reverseInvoice(Invoice $invoice): void
    {
        if ($invoice->journal_entry_id === null) {
            throw new RuntimeException("Invoice {$invoice->getKey()} is not posted.");
        }
        if ($invoice->payments()->whereNotNull('journal_entry_id')->exists()) {
            throw new RuntimeException('Reverse the invoice\'s posted payments before unposting the invoice.');
        }
        if (RevenueSchedule::where('invoice_id', $invoice->getKey())
            ->whereHas('entries', fn ($q) => $q->where('recognized', true))
            ->exists()) {
            throw new RuntimeException('Cannot unpost: revenue has already been recognized against this invoice.');
        }

        DB::transaction(function () use ($invoice): void {
            $entry = $invoice->journalEntry;
            if ($entry instanceof JournalEntry) {
                $entry->reverse();
            }
            $invoice->journal_entry_id = null;
            $invoice->save();
        });
    }

    public function reversePayment(Payment $payment): void
    {
        if ($payment->journal_entry_id === null) {
            throw new RuntimeException("Payment {$payment->getKey()} is not posted.");
        }

        DB::transaction(function () use ($payment): void {
            $entry = $payment->journalEntry;
            if ($entry instanceof JournalEntry) {
                $entry->reverse();
            }
            $payment->journal_entry_id = null;
            $payment->save();
            $payment->invoice?->recomputePaymentStatus();
        });
    }
}
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=ReverseInvoiceTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=ReversePaymentTest`
Expected: PASS. `JournalEntry::reverse()` wraps its own balance update in a transaction (nested here = a savepoint, fine) and subtracts exactly what `post()` added, so the accounts return to `0.00`.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Services/PostingReversalService.php tests/Feature/PostingReversal/ReverseInvoiceTest.php tests/Feature/PostingReversal/ReversePaymentTest.php
git -C src commit -m "feat(reversal): unpost invoice + payment"
```

---

### Task 3: invoices:unpost + payments:unpost commands

**Files:**
- Create: `src/app/Console/Commands/UnpostInvoice.php`
- Create: `src/app/Console/Commands/UnpostPayment.php`
- Test: `src/tests/Feature/PostingReversal/UnpostCommandsTest.php`

**Interfaces:**
- Consumes: `PostingReversalService::reverseInvoice`/`reversePayment` (Task 2); `Invoice`, `Payment`.
- Produces: `invoices:unpost {invoice}` and `payments:unpost {payment}` — resolve by id, skip (`SUCCESS`) if not posted (`journal_entry_id === null`), else reverse; unknown id → `FAILURE`; any reversal failure (`\Throwable`) → caught, printed, `FAILURE`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PostingReversal;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\InvoicePostingService;
use App\Services\PaymentPostingService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnpostCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_commands_unpost_invoice_and_payment(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 300]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 300, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);
        app(PaymentPostingService::class)->post($payment);

        // Unpost the payment first (invoice can't be unposted while a posted payment exists).
        $this->artisan('payments:unpost', ['payment' => $payment->payment_id])->assertSuccessful();
        $this->assertNull($payment->fresh()->journal_entry_id);

        app(InvoicePostingService::class)->post($invoice->fresh());
        $this->artisan('invoices:unpost', ['invoice' => $invoice->id])->assertSuccessful();
        $this->assertNull($invoice->fresh()->journal_entry_id);
    }

    public function test_unknown_ids_fail(): void
    {
        $this->artisan('invoices:unpost', ['invoice' => 999999])->assertFailed();
        $this->artisan('payments:unpost', ['payment' => 999999])->assertFailed();
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=UnpostCommandsTest`
Expected: FAIL (commands not defined).

- [ ] **Step 3: Create the commands**

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\PostingReversalService;
use Illuminate\Console\Command;
use Throwable;

class UnpostInvoice extends Command
{
    #[\Override]
    protected $signature = 'invoices:unpost {invoice : Invoice ID}';

    #[\Override]
    protected $description = 'Unpost (reverse) an invoice\'s general-ledger entry';

    public function handle(PostingReversalService $service): int
    {
        $invoice = Invoice::find($this->argument('invoice'));
        if (! $invoice instanceof Invoice) {
            $this->error("Invoice {$this->argument('invoice')} not found.");

            return self::FAILURE;
        }

        if ($invoice->journal_entry_id === null) {
            $this->info("Invoice {$invoice->id} is not posted; skipped.");

            return self::SUCCESS;
        }

        try {
            $service->reverseInvoice($invoice);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Unposted invoice {$invoice->id}.");

        return self::SUCCESS;
    }
}
```

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PostingReversalService;
use Illuminate\Console\Command;
use Throwable;

class UnpostPayment extends Command
{
    #[\Override]
    protected $signature = 'payments:unpost {payment : Payment ID}';

    #[\Override]
    protected $description = 'Unpost (reverse) a payment\'s general-ledger entry';

    public function handle(PostingReversalService $service): int
    {
        $payment = Payment::find($this->argument('payment'));
        if (! $payment instanceof Payment) {
            $this->error("Payment {$this->argument('payment')} not found.");

            return self::FAILURE;
        }

        $id = (int) $payment->getKey();

        if ($payment->journal_entry_id === null) {
            $this->info("Payment {$id} is not posted; skipped.");

            return self::SUCCESS;
        }

        try {
            $service->reversePayment($payment);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Unposted payment {$id}.");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=UnpostCommandsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Console/Commands/UnpostInvoice.php app/Console/Commands/UnpostPayment.php tests/Feature/PostingReversal/UnpostCommandsTest.php
git -C src commit -m "feat(reversal): unpost commands"
```

---

### Task 4: Filament "Unpost" actions

**Files:**
- Modify: `src/app/Filament/App/Resources/Invoices/InvoiceResource.php` (add an "Unpost" row action)
- Modify: `src/app/Filament/App/Resources/Payments/PaymentResource.php` (add an "Unpost" row action)
- Test: `src/tests/Feature/PostingReversal/UnpostActionTest.php`

**Interfaces:**
- Consumes: `PostingReversalService::reverseInvoice`/`reversePayment` (Task 2); the record.
- Produces: an `unpost` row action on each resource, `visible` only when the record IS posted (`journal_entry_id !== null`), posting-service failures caught (`\Throwable`) → danger notification.

- [ ] **Step 1: Write the failing test**

Mirror the existing `PostInvoiceActionTest`/`PostPaymentActionTest` (tenant setup: membership + `actingAs` BEFORE `Filament::setTenant`). The test:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\PostingReversal;

use App\Filament\App\Resources\Payments\Pages\ListPayments;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Team;
use App\Models\User;
use App\Services\PaymentPostingService;
use App\Services\TenantProvisioningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnpostActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_unpost_action_reverses(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->teams()->attach($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 250]);
        $payment = Payment::create(['invoice_id' => $invoice->id, 'payment_amount' => 250, 'payment_date' => '2026-06-05', 'team_id' => $team->id]);
        app(PaymentPostingService::class)->post($payment);

        Filament::setTenant($team);
        $this->actingAs($user);

        Livewire::test(ListPayments::class)
            ->callTableAction('unpost', $payment);

        $this->assertNull($payment->fresh()->journal_entry_id);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=UnpostActionTest`
Expected: FAIL (action `unpost` not registered).

If the failure is on tenant/panel setup, copy the exact sequence from `tests/Feature/PaymentPosting/PostPaymentActionTest.php` (known-working). Do NOT add Livewire retries/timeouts; if the panel setup fights back after mirroring that file, report BLOCKED rather than flailing.

- [ ] **Step 3: Add the row actions**

In `src/app/Filament/App/Resources/Payments/PaymentResource.php` `recordActions([...])`, alongside the existing `postToLedger`, add (mirror its shape):

```php
                Action::make('unpost')
                    ->label('Unpost')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Payment $record): bool => $record->journal_entry_id !== null)
                    ->action(function (Payment $record): void {
                        try {
                            app(\App\Services\PostingReversalService::class)->reversePayment($record);
                            \Filament\Notifications\Notification::make()->title('Unposted from ledger.')->success()->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()->title('Cannot unpost payment')->body($e->getMessage())->danger()->send();
                        }
                    }),
```

In `src/app/Filament/App/Resources/Invoices/InvoiceResource.php` `recordActions([...])`, alongside `postToLedger`, add:

```php
                Action::make('unpost')
                    ->label('Unpost')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Invoice $record): bool => $record->journal_entry_id !== null)
                    ->action(function (Invoice $record): void {
                        try {
                            app(\App\Services\PostingReversalService::class)->reverseInvoice($record);
                            \Filament\Notifications\Notification::make()->title('Unposted from ledger.')->success()->send();
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()->title('Cannot unpost invoice')->body($e->getMessage())->danger()->send();
                        }
                    }),
```

Both files already import `Filament\Actions\Action` + the model + `Filament\Notifications\Notification` (they host the `postToLedger` action). Do not remove/alter existing actions.

- [ ] **Step 4: Run the test + the whole feature group, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=UnpostActionTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=PostingReversal`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/Invoices/InvoiceResource.php app/Filament/App/Resources/Payments/PaymentResource.php tests/Feature/PostingReversal/UnpostActionTest.php
git -C src commit -m "feat(reversal): unpost Filament actions"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter="PostingReversal|PostPayment|PostInvoice"`. (No new migration, but confirm the reversal + posting round-trips on MySQL.)
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline (`--generate-baseline phpstan-baseline.neon`) only if the ONLY remaining errors are the Filament/Eloquent-`mixed` idiom on the new files — verify each before baselining.
- Pint the new/changed files.
- Adversarial review focus: reverse() subtracts exactly what post() added (balances round-trip to pre-post, no drift); the source `journal_entry_id` is cleared so it's re-postable and re-reversal is a no-op/guarded; invoice reversal is blocked when a posted payment or recognized revenue exists (no orphaned/negative balances); payment reversal recomputes the invoice down to 'pending'/'partial' correctly (the shared method's else-branch); the books-lock guard still fires (a locked-period entry can't be reversed); command/action catch `\Throwable` (books-lock `\DomainException` + reverse's `\Exception` surface as clean errors, not 500s); no test disabled FK enforcement or edited phpunit.xml.

## Self-Review

- **Spec coverage:** shared `Invoice::recomputePaymentStatus()` + posting-service refactor ✓ (T1); `reverseInvoice`/`reversePayment` via `reverse()` with guards + link-clear ✓ (T2); down-reset of payment_status ✓ (T1 method + T2 reversePayment test); commands ✓ (T3); Filament actions ✓ (T4). Deferred (audit-trail reversing entry, reversing recognized revenue, cascade) intentionally absent.
- **Placeholders:** none — full code for every file; T4 gives the exact action code, mirroring the in-repo `postToLedger` siblings.
- **Type consistency:** `reverseInvoice(Invoice): void` / `reversePayment(Payment): void` identical across T2/T3/T4; `recomputePaymentStatus(): void` defined T1, used in T1 (posting) + T2 (`reversePayment`); guard queries (`payments()->whereNotNull('journal_entry_id')`, `RevenueSchedule::where('invoice_id',…)->whereHas('entries', recognized)`) use the real relations/columns from the explore; `journal_entry_id`/`journalEntry()` consistent throughout; account numbers 1000/1100/2400/4000 match the chart.
