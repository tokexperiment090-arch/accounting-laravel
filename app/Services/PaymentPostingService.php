<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Posts a customer payment to the general ledger as a balanced journal entry:
 * Dr Cash (1000) / Cr Accounts Receivable (1100) for the payment amount, then
 * recomputes the invoice's payment_status from the sum of its payments.
 *
 * ponytail: overpayment posts the full amount (AR can go negative — a customer
 * credit); allocation across multiple invoices + reversal are later slices.
 */
class PaymentPostingService
{
    public function post(Payment $payment): JournalEntry
    {
        if ($payment->journal_entry_id !== null) {
            $existing = $payment->journalEntry;

            return $existing instanceof JournalEntry ? $existing : throw new RuntimeException('Payment linked to a missing journal entry.');
        }

        $teamId = (int) $payment->team_id;
        $cash = $this->resolveByNumber($teamId, 1000);
        $receivable = $this->resolveByNumber($teamId, 1100);
        $amount = $payment->payment_amount;

        return DB::transaction(function () use ($payment, $cash, $receivable, $amount, $teamId): JournalEntry {
            $locked = Payment::whereKey($payment->getKey())->lockForUpdate()->first();
            if ($locked instanceof Payment && $locked->journal_entry_id !== null) {
                $existing = $locked->journalEntry;

                return $existing instanceof JournalEntry ? $existing : throw new RuntimeException('Payment linked to a missing journal entry.');
            }

            $entry = new JournalEntry;
            $entry->forceFill([
                'entry_date' => $payment->payment_date,
                'entry_type' => 'general',
                'reference_number' => (string) $payment->getKey(),
                'memo' => 'Payment '.$payment->getKey(),
                'team_id' => $teamId,
                'user_id' => $payment->team?->user_id,
            ])->save();

            $entry->lines()->create([
                'account_id' => $cash->getKey(),
                'debit_amount' => $amount,
                'credit_amount' => 0,
                'description' => 'Cash',
            ]);
            $entry->lines()->create([
                'account_id' => $receivable->getKey(),
                'debit_amount' => 0,
                'credit_amount' => $amount,
                'description' => 'Accounts Receivable',
            ]);

            $entry->post();

            $payment->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            $this->updateInvoiceStatus($payment);

            return $entry;
        });
    }

    private function updateInvoiceStatus(Payment $payment): void
    {
        $invoice = $payment->invoice;
        if (! $invoice instanceof Invoice) {
            return;
        }

        $paid = (float) $invoice->payments()->sum('payment_amount');
        $total = (float) $invoice->total_amount;

        if ($total > 0.0 && $paid >= $total) {
            $invoice->payment_status = 'paid';
        } elseif ($paid > 0.0) {
            $invoice->payment_status = 'partial';
        }

        $invoice->save();
    }

    private function resolveByNumber(int $teamId, int $number): Account
    {
        $account = Account::where('team_id', $teamId)->where('account_number', $number)->first();
        if (! $account instanceof Account) {
            throw new RuntimeException("Account {$number} not found for team {$teamId}. Provision the chart of accounts first (tenants:provision-chart).");
        }

        return $account;
    }
}
