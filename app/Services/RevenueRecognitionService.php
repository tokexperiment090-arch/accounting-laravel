<?php // src/app/Services/RevenueRecognitionService.php
declare(strict_types=1);
namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\RevenueSchedule;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RevenueRecognitionService
{
    public function createFromInvoice(Invoice $invoice, int $periods, Account $deferred, Account $revenue): RevenueSchedule
    {
        if ($periods < 1) {
            throw new InvalidArgumentException('periods must be at least 1.');
        }
        if (RevenueSchedule::where('invoice_id', $invoice->getKey())->exists()) {
            throw new InvalidArgumentException('A revenue schedule already exists for this invoice.');
        }

        $total = (float) $invoice->total_amount;
        $per = round($total / $periods, 2);
        $start = $invoice->invoice_date->copy();

        return DB::transaction(function () use ($invoice, $periods, $deferred, $revenue, $total, $per, $start): RevenueSchedule {
            $schedule = RevenueSchedule::create([
                'invoice_id' => $invoice->getKey(),
                'total_amount' => $total,
                'start_date' => $start,
                'periods' => $periods,
                'deferred_account_id' => $deferred->getKey(),
                'revenue_account_id' => $revenue->getKey(),
                'status' => 'active',
                'team_id' => $invoice->team_id,
            ]);

            for ($n = 1; $n <= $periods; $n++) {
                $amount = $n < $periods ? $per : round($total - $per * ($periods - 1), 2);
                $schedule->entries()->create([
                    'period_number' => $n,
                    'recognition_date' => $start->copy()->addMonths($n - 1),
                    'amount' => $amount,
                    'recognized' => false,
                ]);
            }

            return $schedule;
        });
    }
}
