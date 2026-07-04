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
