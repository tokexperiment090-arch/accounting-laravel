<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $primaryKey = 'payment_id';

    #[\Override]
    protected $fillable = [
        'invoice_id',
        'payment_date',
        'payment_amount',
        'qbo_id',
        'qbo_sync_token',
        'journal_entry_id',
    ];

    protected $casts = [
        'payment_amount' => 'decimal:2',
        'payment_date' => 'date',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    public function journalEntry(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
