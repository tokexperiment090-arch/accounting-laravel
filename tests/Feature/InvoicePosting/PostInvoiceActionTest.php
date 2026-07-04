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

        $this->actingAs($user);
        Filament::setTenant($team);

        Livewire::test(ListInvoices::class)
            ->callTableAction('postToLedger', $invoice);

        $this->assertNotNull($invoice->fresh()->journal_entry_id);
    }
}
