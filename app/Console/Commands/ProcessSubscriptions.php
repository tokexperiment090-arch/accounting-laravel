<?php // src/app/Console/Commands/ProcessSubscriptions.php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\Subscription;
use App\Services\SubscriptionBillingService;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    #[\Override]
    protected $signature = 'subscriptions:process';
    #[\Override]
    protected $description = 'Generate draft invoices for all active subscriptions with due billing cycles';

    public function handle(SubscriptionBillingService $service): void
    {
        $total = 0;
        Subscription::where('status', 'active')->each(function (Subscription $subscription) use (&$total, $service): void {
            $total += $service->generateDueInvoices($subscription);
        });
        $this->info("Generated {$total} subscription invoice(s).");
    }
}
