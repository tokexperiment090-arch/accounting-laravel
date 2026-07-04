<?php
declare(strict_types=1);
namespace App\Models;

use App\Concerns\Approvable;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PurchaseRequest extends Model
{
    use Approvable;
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'supplier_id', 'request_number', 'request_date', 'total_amount',
        'status', 'notes', 'team_id',
        'approval_status', 'approved_by', 'approved_at', 'rejection_reason',
    ];

    #[\Override]
    protected $casts = [
        'request_date' => 'date',
        'total_amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (PurchaseRequest $request): void {
            if (empty($request->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $request->team_id = $team->getKey();
            }
            if (empty($request->request_number)) {
                $request->request_number = 'PR-'.str_pad((string) ((int) static::max('id') + 1), 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function approvalAmount(): float
    {
        return (float) $this->total_amount;
    }

    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class, 'supplier_id', 'supplier_id'); }
    public function items(): HasMany { return $this->hasMany(PurchaseRequestItem::class); }
    public function purchaseOrder(): HasOne { return $this->hasOne(PurchaseOrder::class, 'purchase_request_id'); }
}
