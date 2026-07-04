<?php // src/app/Console/Commands/RecognizeRevenue.php
declare(strict_types=1);
namespace App\Console\Commands;
use App\Models\RevenueSchedule;
use App\Services\RevenueRecognitionService;
use Illuminate\Console\Command;

class RecognizeRevenue extends Command
{
    #[\Override]
    protected $signature = 'revenue:recognize';
    #[\Override]
    protected $description = 'Post due revenue-recognition entries for all active schedules';

    public function handle(RevenueRecognitionService $service): void
    {
        $total = 0;
        RevenueSchedule::where('status', 'active')->each(function (RevenueSchedule $schedule) use (&$total, $service): void {
            $total += $service->recognizeDue($schedule);
        });
        $this->info("Recognised {$total} revenue period(s).");
    }
}
