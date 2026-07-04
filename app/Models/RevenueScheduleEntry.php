<?php
declare(strict_types=1);
namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RevenueScheduleEntry extends Model
{
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'revenue_schedule_id', 'period_number', 'recognition_date',
        'amount', 'recognized', 'recognized_at', 'journal_entry_id',
    ];

    #[\Override]
    protected $casts = [
        'recognition_date' => 'date',
        'amount' => 'decimal:2',
        'recognized' => 'boolean',
        'recognized_at' => 'datetime',
    ];

    public function schedule(): BelongsTo { return $this->belongsTo(RevenueSchedule::class, 'revenue_schedule_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
}
