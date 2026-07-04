# Invoice → GL Posting (Slice B) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Post an invoice to the general ledger as a balanced journal entry — Dr Accounts Receivable / Cr Deferred Revenue (if the invoice has a revenue schedule) or Cr Sales Revenue (otherwise) — closing the upstream half of the revenue-recognition loop.

**Architecture:** Rewrite the dead `InvoicePostingService` into `post(Invoice): JournalEntry`: resolve AR + the credit account from the team's provisioned chart (by `account_number`; deferred account read from the invoice's `RevenueSchedule` when one exists), build a balanced `JournalEntry` via `forceFill` (no auth in the command context), call `->post()`, and link `invoices.journal_entry_id` for idempotency. A `invoices:post {invoice}` command and a "Post to ledger" Filament action are thin wrappers.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / PHPUnit (sqlite `:memory:`). Tests from repo root: `docker compose exec -T php-fpm php artisan test --filter=<Name>` (no host php; php-fpm mounts `./src` at `/var/www`; if "service not running": `docker compose up -d php-fpm`, wait 2s, retry).

## Global Constraints

- `declare(strict_types=1);` on every new PHP file; `#[\Override]` on overrides.
- The posting `JournalEntry`'s `team_id` and `user_id` are **NOT fillable** and there is no reliable `auth()` (command/ops context). Set both via `forceFill([... 'team_id' => $invoice->team_id, 'user_id' => $invoice->team->user_id ...])` before `->post()` — `team_id` DB-defaults to `1` if unset (a cross-team leak) and `user_id` is a non-nullable FK (`$invoice->team->user_id` = the team owner; `Invoice` uses `IsTenantModel`, so `$invoice->team` resolves). Same trap already handled in `RevenueRecognitionService::recognizeDue`.
- Posted entry is **exactly two lines**: Dr AR / Cr {Deferred|Sales} for the invoice's `total_amount` (**pre-tax** — `total_amount` is the sum of `InvoiceItem.amount`; per-line `tax_amount` is NOT posted this slice). `JournalEntry::post()` validates `isBalanced()` and updates account balances; call it once.
- Accounts resolved by `(team_id, account_number)`: **AR = 1100**, **Sales Revenue = 4000** (the P2-6 provisioned chart). The credit account for a scheduled invoice is the schedule's `deferred_account_id` (read directly). Missing account → throw `\RuntimeException` ("provision the chart first").
- **Idempotent:** if `invoice.journal_entry_id` is already set, return the linked entry and post nothing. The whole build+post+link runs in one `DB::transaction`.
- Tests: sqlite `:memory:` ENFORCES FKs; **never disable FK enforcement / never edit phpunit.xml / never weaken a guard**. No `TeamFactory` — `Team::forceCreate(['user_id'=>$u->id,'name'=>'X','personal_team'=>false])`. `Invoice::factory()`/`Customer::factory()` exist (factory does NOT set `team_id` — pass it explicitly). Seed the chart with `app(TenantProvisioningService::class)->provisionChartOfAccounts($team)`.
- Commit after each task; Conventional-Commits subject ≤50 chars.

---

### Task 1: invoices.journal_entry_id migration + Invoice relation

**Files:**
- Create: `src/database/migrations/2026_07_11_100001_add_journal_entry_id_to_invoices_table.php`
- Modify: `src/app/Models/Invoice.php` (add `journal_entry_id` to `$fillable`; add `journalEntry()` relation)
- Test: `src/tests/Feature/InvoicePosting/InvoiceJournalLinkTest.php`

**Interfaces:**
- Produces: `invoices.journal_entry_id` (nullable FK → `journal_entries`, `nullOnDelete`); `Invoice::journalEntry(): BelongsTo` and `journal_entry_id` in `$fillable`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\InvoicePosting;

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceJournalLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_links_to_its_journal_entry(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'total_amount' => 100]);
        $entry = JournalEntry::create(['entry_date' => '2026-06-01', 'entry_type' => 'general']);

        $invoice->update(['journal_entry_id' => $entry->id]);

        $this->assertTrue($invoice->fresh()->journalEntry->is($entry));
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=InvoiceJournalLinkTest`
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
        Schema::table('invoices', function (Blueprint $t): void {
            $t->foreignId('journal_entry_id')->nullable()->after('id')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
```

- [ ] **Step 4: Wire the model**

In `src/app/Models/Invoice.php`: add `'journal_entry_id'` to the `$fillable` array, and add this relation method (near the other relations, e.g. next to `items()`):

```php
    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
```

(Ensure `use App\Models\JournalEntry;` is present, or reference it fully-qualified as above. If the file already imports models without the `App\Models\` prefix because it's in that namespace, just use `JournalEntry::class` — it's the same namespace.)

- [ ] **Step 5: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=InvoiceJournalLinkTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git -C src add database/migrations/2026_07_11_100001_add_journal_entry_id_to_invoices_table.php app/Models/Invoice.php tests/Feature/InvoicePosting/InvoiceJournalLinkTest.php
git -C src commit -m "feat(invoice-post): link invoice to journal entry"
```

---

### Task 2: InvoicePostingService rewrite (defer-aware)

**Files:**
- Modify: `src/app/Services/InvoicePostingService.php` (full rewrite of `post()`)
- Modify: `src/tests/Feature/InvoiceLineItemsTest.php` (update the one posting test to the new signature)
- Test: `src/tests/Feature/InvoicePosting/PostInvoiceTest.php`

**Interfaces:**
- Consumes: `Invoice` (`total_amount`, `invoice_date`, `invoice_number`, `team_id`, `team->user_id`, `journal_entry_id`, `journalEntry()`) from Task 1; `App\Models\RevenueSchedule` (`invoice_id` unique, `deferred_account_id`); `App\Models\Account` (`(team_id, account_number)`); `App\Models\JournalEntry` (`forceFill`, `lines()->create`, `post()`); `App\Services\TenantProvisioningService` (test setup only).
- Produces: `InvoicePostingService::post(Invoice $invoice): JournalEntry` — idempotent (returns the linked entry if already posted); otherwise builds+posts a balanced 2-line entry (Dr AR 1100 / Cr Deferred-from-schedule-or-Sales 4000 for `total_amount`), stamps `team_id`+`user_id`, links `invoice.journal_entry_id`; throws `\RuntimeException` if a required account is absent.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\InvoicePosting;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\InvoicePostingService;
use App\Services\RevenueRecognitionService;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PostInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:Team,1:Invoice} */
    private function provisionedInvoice(float $total = 500): array
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create([
            'team_id' => $team->id, 'customer_id' => $customer->id,
            'invoice_date' => '2026-06-01', 'total_amount' => $total,
        ]);

        return [$team, $invoice];
    }

    private function account(Team $team, int $number): Account
    {
        return Account::where('team_id', $team->id)->where('account_number', $number)->firstOrFail();
    }

    public function test_non_scheduled_invoice_posts_dr_ar_cr_sales(): void
    {
        [$team, $invoice] = $this->provisionedInvoice(500);

        $entry = app(InvoicePostingService::class)->post($invoice);

        $this->assertTrue($entry->is_posted);
        $this->assertTrue($entry->isBalanced());
        $this->assertSame($team->id, (int) $entry->team_id);
        $this->assertSame($team->user_id, (int) $entry->user_id);
        $this->assertSame(2, $entry->lines()->count());
        // AR (1100, asset) debited 500; Sales (4000, revenue) credited 500
        $this->assertSame('500.00', number_format((float) $this->account($team, 1100)->balance, 2, '.', ''));
        $this->assertSame('500.00', number_format((float) $this->account($team, 4000)->balance, 2, '.', ''));
        // invoice linked
        $this->assertSame($entry->id, $invoice->fresh()->journal_entry_id);
    }

    public function test_scheduled_invoice_credits_deferred_revenue(): void
    {
        [$team, $invoice] = $this->provisionedInvoice(1200);
        $deferred = $this->account($team, 2400);
        $sales = $this->account($team, 4000);
        app(RevenueRecognitionService::class)->createFromInvoice($invoice, 12, $deferred, $sales);

        $entry = app(InvoicePostingService::class)->post($invoice->fresh());

        // credit lands on Deferred Revenue (2400, liability), NOT Sales
        $creditLine = $entry->lines()->where('credit_amount', '>', 0)->first();
        $this->assertSame($deferred->id, (int) $creditLine->account_id);
        $this->assertSame('1200.00', number_format((float) $deferred->fresh()->balance, 2, '.', ''));
    }

    public function test_is_idempotent(): void
    {
        [$team, $invoice] = $this->provisionedInvoice(300);

        $first = app(InvoicePostingService::class)->post($invoice);
        $second = app(InvoicePostingService::class)->post($invoice->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, JournalEntry::where('team_id', $team->id)->count());
    }

    public function test_throws_when_chart_not_provisioned(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Bare', 'personal_team' => false]);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 100]);

        $this->expectException(RuntimeException::class);
        app(InvoicePostingService::class)->post($invoice);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostInvoiceTest`
Expected: FAIL (old `post()` signature takes `(Invoice, Account)` / no chart resolution).

- [ ] **Step 3: Rewrite the service**

```php
<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\RevenueSchedule;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer invoice to the general ledger as a balanced journal entry:
 * Dr Accounts Receivable / Cr Deferred Revenue (when the invoice has a revenue
 * schedule) or Cr Sales Revenue (otherwise), for the pre-tax total.
 *
 * ponytail: per-line tax_amount -> Sales Tax Payable (2200) is deferred to a
 * later slice; this posts total_amount only (balanced pre-tax).
 */
class InvoicePostingService
{
    public function post(Invoice $invoice): JournalEntry
    {
        if ($invoice->journal_entry_id !== null) {
            return $invoice->journalEntry; // idempotent — already posted
        }

        $teamId = (int) $invoice->team_id;
        $receivable = $this->resolveByNumber($teamId, 1100);

        $schedule = RevenueSchedule::where('invoice_id', $invoice->getKey())->first();
        $credit = $schedule instanceof RevenueSchedule
            ? $this->resolveById((int) $schedule->deferred_account_id)
            : $this->resolveByNumber($teamId, 4000);

        $amount = $invoice->total_amount;

        return DB::transaction(function () use ($invoice, $receivable, $credit, $amount, $teamId, $schedule): JournalEntry {
            $entry = new JournalEntry;
            // team_id + user_id are NOT fillable and there is no auth() here; set
            // them explicitly so the entry is team-scoped (never default team 1)
            // and satisfies the non-nullable user_id FK (team owner).
            $entry->forceFill([
                'entry_date' => $invoice->invoice_date,
                'entry_type' => 'general',
                'reference_number' => (string) $invoice->getKey(),
                'memo' => 'Invoice '.$invoice->invoice_number,
                'team_id' => $teamId,
                'user_id' => $invoice->team->user_id,
            ])->save();

            $entry->lines()->create([
                'account_id' => $receivable->getKey(),
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'description' => 'Accounts Receivable',
            ]);
            $entry->lines()->create([
                'account_id' => $credit->getKey(),
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'description' => $schedule instanceof RevenueSchedule ? 'Deferred revenue' : 'Sales revenue',
            ]);

            $entry->post();

            $invoice->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            return $entry;
        });
    }

    private function resolveByNumber(int $teamId, int $number): Account
    {
        $account = Account::where('team_id', $teamId)->where('account_number', $number)->first();
        if (! $account instanceof Account) {
            throw new RuntimeException("Account {$number} not found for team {$teamId}. Provision the chart of accounts first (tenants:provision-chart).");
        }

        return $account;
    }

    private function resolveById(int $id): Account
    {
        $account = Account::find($id);
        if (! $account instanceof Account) {
            throw new RuntimeException("Revenue-schedule account {$id} not found.");
        }

        return $account;
    }
}
```

- [ ] **Step 4: Update the existing posting test**

`src/tests/Feature/InvoiceLineItemsTest.php` has `test_invoice_posts_balanced_journal_entry` calling the OLD `post($invoice, $receivable)` (2-arg, credits each item's account). Replace ONLY that one method (leave the other four tests — total rollup, auto-calc, tax — untouched) with the new single-arg, chart-resolved behavior:

```php
    public function test_invoice_posts_balanced_journal_entry(): void
    {
        $user = \App\Models\User::factory()->create();
        $team = \App\Models\Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(\App\Services\TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = \App\Models\Customer::factory()->create(['team_id' => $team->id]);

        $invoice = \App\Models\Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 0]);
        $invoice->items()->create(['description' => 'Service A', 'quantity' => 2, 'unit_price' => 100, 'amount' => 200]);
        $invoice->items()->create(['description' => 'Service B', 'quantity' => 1, 'unit_price' => 50, 'amount' => 50]);

        $entry = app(InvoicePostingService::class)->post($invoice->fresh());

        $this->assertTrue($entry->isBalanced());
        $this->assertTrue($entry->is_posted);
        $this->assertEquals(250.00, (float) $entry->total_debits);
        $this->assertEquals(250.00, (float) $entry->total_credits);
        $this->assertSame(2, $entry->lines()->count()); // Dr AR + Cr Sales
    }
```

If `InvoiceLineItemsTest`'s imports don't already include the models/services used above, either add the `use` statements at the top or keep the fully-qualified names shown here. Do not change the other test methods.

- [ ] **Step 5: Run both test files, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostInvoiceTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=InvoiceLineItemsTest`
Expected: PASS (4 + 5 tests). If a line-item's `amount` is recomputed by the `InvoiceItem` saved hook, note the values above already equal `quantity * unit_price` (200, 50), so `total_amount` reconciles to 250 either way.

- [ ] **Step 6: Commit**

```bash
git -C src add app/Services/InvoicePostingService.php tests/Feature/InvoicePosting/PostInvoiceTest.php tests/Feature/InvoiceLineItemsTest.php
git -C src commit -m "feat(invoice-post): defer-aware GL posting"
```

---

### Task 3: invoices:post command

**Files:**
- Create: `src/app/Console/Commands/PostInvoice.php`
- Test: `src/tests/Feature/InvoicePosting/PostInvoiceCommandTest.php`

**Interfaces:**
- Consumes: `InvoicePostingService::post(Invoice): JournalEntry` (Task 2); `App\Models\Invoice`.
- Produces: `invoices:post {invoice}` — resolves the invoice by id, posts it, prints the journal-entry id (or "already posted"); unknown id → non-zero exit; missing chart (`RuntimeException`) → caught, printed as an error, non-zero exit.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\InvoicePosting;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostInvoiceCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_posts_the_invoice_by_id(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 400]);

        $this->artisan('invoices:post', ['invoice' => $invoice->id])->assertSuccessful();

        $this->assertNotNull($invoice->fresh()->journal_entry_id);
        $this->assertSame(1, JournalEntry::where('team_id', $team->id)->count());
    }

    public function test_command_fails_for_unknown_invoice(): void
    {
        $this->artisan('invoices:post', ['invoice' => 999999])->assertFailed();
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostInvoiceCommandTest`
Expected: FAIL (command `invoices:post` not defined).

- [ ] **Step 3: Create the command**

```php
<?php
declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\InvoicePostingService;
use Illuminate\Console\Command;
use RuntimeException;

class PostInvoice extends Command
{
    #[\Override]
    protected $signature = 'invoices:post {invoice : Invoice ID}';

    #[\Override]
    protected $description = 'Post an invoice to the general ledger';

    public function handle(InvoicePostingService $service): int
    {
        $invoice = Invoice::find($this->argument('invoice'));
        if (! $invoice instanceof Invoice) {
            $this->error("Invoice {$this->argument('invoice')} not found.");

            return self::FAILURE;
        }

        if ($invoice->journal_entry_id !== null) {
            $this->info("Invoice {$invoice->id} already posted (entry {$invoice->journal_entry_id}); skipped.");

            return self::SUCCESS;
        }

        try {
            $entry = $service->post($invoice);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Posted invoice {$invoice->id} to ledger (entry {$entry->id}).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostInvoiceCommandTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git -C src add app/Console/Commands/PostInvoice.php tests/Feature/InvoicePosting/PostInvoiceCommandTest.php
git -C src commit -m "feat(invoice-post): invoices:post command"
```

---

### Task 4: Filament "Post to ledger" action

**Files:**
- Modify: `src/app/Filament/App/Resources/Invoices/InvoiceResource.php` (add a row action to the table)
- Test: `src/tests/Feature/InvoicePosting/PostInvoiceActionTest.php`

**Interfaces:**
- Consumes: `InvoicePostingService::post(Invoice): JournalEntry` (Task 2); the Invoice record.
- Produces: a `postToLedger` table row action that posts the record, shows a notification, and hides once the invoice is posted (`journal_entry_id` set).

- [ ] **Step 1: Write the failing test**

Mirror `src/tests/Feature/Approval/ApprovalRuleResourceTest.php` for the tenant/panel setup and Filament table-action calling convention. The test:

```php
<?php
declare(strict_types=1);

namespace Tests\Feature\InvoicePosting;

use App\Filament\App\Resources\Invoices\Pages\ListInvoices;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use App\Services\TenantProvisioningService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PostInvoiceActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_posts_the_invoice_to_the_ledger(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->teams()->attach($team);
        $user->forceFill(['current_team_id' => $team->id])->save();
        app(TenantProvisioningService::class)->provisionChartOfAccounts($team);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $invoice = Invoice::factory()->create(['team_id' => $team->id, 'customer_id' => $customer->id, 'total_amount' => 250]);

        Filament::setTenant($team);
        $this->actingAs($user);

        Livewire::test(ListInvoices::class)
            ->callTableAction('postToLedger', $invoice);

        $this->assertNotNull($invoice->fresh()->journal_entry_id);
    }
}
```

- [ ] **Step 2: Run it, verify it fails**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostInvoiceActionTest`
Expected: FAIL (table action `postToLedger` not registered).

If the failure is on tenant/panel setup rather than the missing action, copy the exact membership + `Filament::setTenant` (after `actingAs`) sequence from `tests/Feature/Approval/ApprovalRuleResourceTest.php`. Do NOT add Livewire retries/timeouts; if the panel setup fights back after mirroring that file, report it rather than flailing.

- [ ] **Step 3: Add the row action**

Read `src/app/Filament/App/Resources/Invoices/InvoiceResource.php` and find the table's row-actions array (Filament v5 — the `->recordActions([...])` / actions array in the `table()` method, alongside the existing `EditAction`/etc). Mirror the row-action pattern used in `src/app/Filament/App/Resources/SalesOrders/SalesOrderResource.php` (its `Action::make('convertToInvoice')->requiresConfirmation()->action(fn (SalesOrder $record) => ...)`). Add:

```php
                Action::make('postToLedger')
                    ->label('Post to ledger')
                    ->icon('heroicon-o-book-open')
                    ->requiresConfirmation()
                    ->visible(fn (Invoice $record): bool => $record->journal_entry_id === null)
                    ->action(function (Invoice $record): void {
                        try {
                            $entry = app(\App\Services\InvoicePostingService::class)->post($record);
                            \Filament\Notifications\Notification::make()
                                ->title("Posted to ledger (entry {$entry->id}).")
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Cannot post invoice')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
```

Ensure `use Filament\Actions\Action;` (or the table-action `Action` class the file already uses for row actions — match the existing import in this file; SalesOrderResource uses `Filament\Actions\Action`) and `use App\Models\Invoice;` are imported. Do not remove or alter the existing actions.

- [ ] **Step 4: Run the test + the whole feature group, verify they pass**

Run: `docker compose exec -T php-fpm php artisan test --filter=PostInvoiceActionTest`
Run: `docker compose exec -T php-fpm php artisan test --filter=InvoicePosting`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git -C src add app/Filament/App/Resources/Invoices/InvoiceResource.php tests/Feature/InvoicePosting/PostInvoiceActionTest.php
git -C src commit -m "feat(invoice-post): post-to-ledger Filament action"
```

---

## Integration (after all tasks)

- Full suite sqlite: `docker compose exec -T php-fpm php artisan test`.
- MySQL masking check: `docker compose exec -T -e DB_CONNECTION=mysql -e DB_HOST=mysql -e DB_PORT=3306 -e DB_DATABASE=accounting_test -e DB_USERNAME=homestead -e DB_PASSWORD=secret php-fpm php artisan test --filter="InvoicePosting|InvoiceLineItems"`. (New migration adds an FK — run it: MySQL enforces FK column-type match + the auto index name length.)
- PHPStan: `docker compose exec -T php-fpm ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G`; regenerate the frozen baseline (`--generate-baseline phpstan-baseline.neon`) only if the ONLY remaining errors are the Filament/Eloquent-`mixed` idiom on the new files — verify each before baselining.
- Pint the new/changed files.
- Adversarial review focus: every posting entry carries the invoice's `team_id` (never default team 1) + a non-null `user_id`; the entry is balanced Dr AR / Cr {Deferred|Sales} and `post()` moved both account balances; a scheduled invoice credits the schedule's **Deferred** account (loop with rev-rec: this credits Deferred, recognizeDue draws it down — no double count); idempotent (`journal_entry_id` guard prevents a 2nd entry, even via command+action both firing); missing-chart throws (not a silent team-1 post); pre-tax `total_amount` used consistently; the books-lock `saving` guard still applies; that **no test disabled FK enforcement or edited phpunit.xml**.

## Self-Review

- **Spec coverage:** `invoices.journal_entry_id` + relation ✓ (T1); defer-aware post (Dr AR / Cr Deferred-or-Sales) ✓ (T2); account resolution by number from chart + throw-if-missing ✓ (T2); team_id/user_id via forceFill ✓ (T2 + Global Constraints); idempotent link ✓ (T2); pre-tax total ✓ (T2); command ✓ (T3); Filament action ✓ (T4); existing posting test updated ✓ (T2 Step 4). Deferred (tax-payable, auto-post, reversal) intentionally absent.
- **Placeholders:** none in T1–T3 (full code). T4 gives the exact action code + imports, pointing at the concrete in-repo resource to mirror (`SalesOrderResource` row action) and a known-working panel test (`ApprovalRuleResourceTest`).
- **Type consistency:** `post(Invoice): JournalEntry` identical across T2/T3/T4; `journal_entry_id`/`journalEntry()` consistent T1↔T2↔T3↔T4; account numbers 1100/2400/4000 match the chart; JournalEntry line fields (`account_id, debit_amount, credit_amount, description`) match the model; `RevenueSchedule::deferred_account_id` matches the P2-2 model.
