<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotificationPreference extends Model
{
    #[\Override]
    protected $fillable = [
        'user_id',
        'phone',
        'mail_enabled',
        'database_enabled',
        'sms_enabled',
    ];

    #[\Override]
    protected $casts = [
        'mail_enabled' => 'boolean',
        'database_enabled' => 'boolean',
        'sms_enabled' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
