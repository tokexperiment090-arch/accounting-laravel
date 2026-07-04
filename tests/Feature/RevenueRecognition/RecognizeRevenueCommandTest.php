<?php
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

    public function test_a_locked_schedule_does_not_starve_later_schedules(): void
    {
        Carbon::setTestNow('2026-03-01'); // 01-15, 02-15 due
        // Team A (created first, lower id): books locked after its due periods -> posting throws.
        $userA = User::factory()->create();
        $teamA = Team::forceCreate(['user_id' => $userA->id, 'name' => 'A', 'personal_team' => false, 'books_locked_before' => '2026-06-01']);
        $invA = Invoice::factory()->create(['team_id' => $teamA->id, 'total_amount' => 1200, 'invoice_date' => '2026-01-15']);
        $defA = Account::create(['account_number' => 2401, 'account_name' => 'Def A', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $userA->id]);
        $revA = Account::create(['account_number' => 4001, 'account_name' => 'Rev A', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $userA->id]);
        app(RevenueRecognitionService::class)->createFromInvoice($invA, 12, $defA, $revA);

        // Team B (clean): should get recognized despite A throwing.
        $userB = User::factory()->create();
        $teamB = Team::forceCreate(['user_id' => $userB->id, 'name' => 'B', 'personal_team' => false]);
        $invB = Invoice::factory()->create(['team_id' => $teamB->id, 'total_amount' => 1200, 'invoice_date' => '2026-01-15']);
        $defB = Account::create(['account_number' => 2402, 'account_name' => 'Def B', 'account_type' => 'liability', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $userB->id]);
        $revB = Account::create(['account_number' => 4002, 'account_name' => 'Rev B', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'balance' => 0, 'opening_balance' => 0, 'is_active' => true, 'allow_manual_entry' => true, 'user_id' => $userB->id]);
        app(RevenueRecognitionService::class)->createFromInvoice($invB, 12, $defB, $revB);

        $this->artisan('revenue:recognize')->assertSuccessful();

        $this->assertSame(0, JournalEntry::where('team_id', $teamA->id)->count()); // locked -> rolled back
        $this->assertSame(2, JournalEntry::where('team_id', $teamB->id)->count()); // NOT starved
        Carbon::setTestNow();
    }
}
