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

        $this->actingAs($user);
        Filament::setTenant($team);

        Livewire::test(ListPayments::class)
            ->callTableAction('unpost', $payment);

        $this->assertNull($payment->fresh()->journal_entry_id);
    }
}
