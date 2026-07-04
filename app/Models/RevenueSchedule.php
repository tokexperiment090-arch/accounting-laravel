<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RevenueSchedule extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = [
        'invoice_id', 'total_amount', 'start_date', 'periods',
        'deferred_account_id', 'revenue_account_id', 'status', 'team_id',
    ];

    #[\Override]
    protected $casts = [
        'total_amount' => 'decimal:2',
        'start_date' => 'date',
        'periods' => 'integer',
    ];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (RevenueSchedule $schedule): void {
            if (empty($schedule->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $schedule->team_id = $team->getKey();
            }
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function deferredAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'deferred_account_id');
    }

    public function revenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(RevenueScheduleEntry::class);
    }
}
