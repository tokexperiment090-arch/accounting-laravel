<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PostingReversalService;
use Illuminate\Console\Command;
use Throwable;

class UnpostPayment extends Command
{
    #[\Override]
    protected $signature = 'payments:unpost {payment : Payment ID}';

    #[\Override]
    protected $description = 'Unpost (reverse) a payment\'s general-ledger entry';

    public function handle(PostingReversalService $service): int
    {
        $payment = Payment::find($this->argument('payment'));
        if (! $payment instanceof Payment) {
            $this->error("Payment {$this->argument('payment')} not found.");

            return self::FAILURE;
        }

        $id = (int) $payment->getKey();

        if ($payment->journal_entry_id === null) {
            $this->info("Payment {$id} is not posted; skipped.");

            return self::SUCCESS;
        }

        try {
            $service->reversePayment($payment);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Unposted payment {$id}.");

        return self::SUCCESS;
    }
}
