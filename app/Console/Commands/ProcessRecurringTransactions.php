<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Bill;
use App\Models\Expense;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessRecurringTransactions extends Command
{
    #[\Override]
    protected $signature = 'recurring:process';

    #[\Override]
    protected $description = 'Generate draft occurrences for all due recurring invoices, bills, and expenses';

    public function handle(): void
    {
        $total = 0;

        foreach ([Invoice::class, Bill::class, Expense::class] as $model) {
            $model::where('is_recurring', true)->each(function ($template) use (&$total): void {
                try {
                    $total += $template->generateDue();
                } catch (\Throwable $e) {
                    // One bad template must not halt the rest.
                    Log::error('Recurring generation failed for template.', [
                        'model' => $template::class,
                        'id' => $template->getKey(),
                        'error' => $e->getMessage(),
                    ]);
                }
            });
        }

        $this->info("Generated {$total} recurring document(s).");
    }
}
