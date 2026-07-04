<?php // src/tests/Feature/Subscription/BillingServiceTest.php
declare(strict_types=1);
namespace Tests\Feature\Subscription;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\SubscriptionBillingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-06-15');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function activeSub(string $status = 'active', string $nextBilling = '2026-03-15'): Subscription
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $customer = Customer::factory()->create(['team_id' => $team->id]);
        $plan = Plan::create(['name' => 'Pro', 'amount' => 50, 'interval' => 'monthly', 'team_id' => $team->id]);

        return Subscription::create([
            'customer_id' => $customer->id, 'plan_id' => $plan->id, 'status' => $status,
            'started_at' => '2026-03-15', 'next_billing_date' => $nextBilling, 'team_id' => $team->id,
        ]);
    }

    public function test_catch_up_generates_a_draft_invoice_per_missed_cycle(): void
    {
        $sub = $this->activeSub(); // next_billing 2026-03-15, monthly, today 2026-06-15

        // Cycles due: 03-15, 04-15, 05-15, 06-15 (<= today) = 4.
        $count = app(SubscriptionBillingService::class)->generateDueInvoices($sub);

        $this->assertSame(4, $count);
        $invoices = Invoice::where('customer_id', $sub->customer_id)->get();
        $this->assertCount(4, $invoices);
        $first = $invoices->first();
        $this->assertSame('pending', $first->payment_status);
        $this->assertSame($sub->team_id, (int) $first->team_id);
        $this->assertSame('50.00', (string) $first->total_amount);
        $this->assertCount(1, $first->items);
        $this->assertSame('Pro', $first->items->first()->description);

        // Idempotent: re-run generates nothing new.
        $this->assertSame(0, app(SubscriptionBillingService::class)->generateDueInvoices($sub->fresh()));
    }

    public function test_paused_or_cancelled_subscription_bills_nothing(): void
    {
        $paused = $this->activeSub('paused');
        $cancelled = $this->activeSub('cancelled');

        $this->assertSame(0, app(SubscriptionBillingService::class)->generateDueInvoices($paused));
        $this->assertSame(0, app(SubscriptionBillingService::class)->generateDueInvoices($cancelled));
    }

    public function test_safety_cap_bounds_a_run(): void
    {
        $sub = $this->activeSub('active', '2020-01-15'); // far in the past, daily-ish overflow of cycles
        $sub->plan->update(['interval' => 'daily']);

        $count = app(SubscriptionBillingService::class)->generateDueInvoices($sub->fresh());

        $this->assertSame(120, $count); // capped
    }
}
