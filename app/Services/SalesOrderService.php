<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\Estimate;
use App\Models\Invoice;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function createFromEstimate(Estimate $estimate): SalesOrder
    {
        if ($estimate->status !== 'accepted') {
            throw new \DomainException('Only an accepted estimate can become a sales order.');
        }
        if ($estimate->salesOrder()->exists()) {
            throw new \DomainException('This estimate already has a sales order.');
        }

        return DB::transaction(function () use ($estimate): SalesOrder {
            $order = SalesOrder::create([
                'customer_id' => $estimate->customer_id,
                'estimate_id' => $estimate->estimate_id,
                'order_date' => today(),
                'status' => 'confirmed',
                'subtotal_amount' => $estimate->subtotal_amount,
                'tax_amount' => $estimate->tax_amount,
                'total_amount' => $estimate->total_amount,
            ]);

            foreach ($estimate->items as $item) {
                $order->items()->create([
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'tax_amount' => $item->tax_amount,
                    'tax_rate_id' => $item->tax_rate_id,
                ]);
            }

            return $order;
        });
    }

    public function convertToInvoice(SalesOrder $order): Invoice
    {
        if (in_array($order->status, ['invoiced', 'cancelled'], true)) {
            throw new \DomainException('This sales order cannot be invoiced.');
        }
        if ($order->invoice()->exists()) {
            throw new \DomainException('This sales order already has an invoice.');
        }

        return DB::transaction(function () use ($order): Invoice {
            $invoice = Invoice::create([
                'customer_id' => $order->customer_id,
                'sales_order_id' => $order->id,
                'invoice_date' => today(),
                'due_date' => today()->addDays(30),
                'total_amount' => $order->total_amount,
                'payment_status' => 'pending',
            ]);

            foreach ($order->items as $item) {
                $invoice->items()->create([
                    'account_id' => $item->account_id,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'amount' => $item->amount,
                    'tax_amount' => $item->tax_amount,
                    'tax_rate_id' => $item->tax_rate_id,
                ]);
            }

            $order->update(['status' => 'invoiced']);

            return $invoice;
        });
    }
}
