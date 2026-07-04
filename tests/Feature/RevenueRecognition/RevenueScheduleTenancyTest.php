<?php
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
