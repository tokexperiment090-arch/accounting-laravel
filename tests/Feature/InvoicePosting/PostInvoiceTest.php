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
