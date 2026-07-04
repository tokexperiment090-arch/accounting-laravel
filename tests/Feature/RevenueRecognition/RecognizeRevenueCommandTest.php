<?php // src/tests/Feature/RevenueRecognition/RecognizeRevenueCommandTest.php
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
}
