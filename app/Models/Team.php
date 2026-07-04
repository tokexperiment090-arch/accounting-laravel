<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    #[\Override]
    protected $fillable = [
        'name',
        'personal_team',
        'books_locked_before',
        'vonage_key',
        'vonage_secret',
        'vonage_from',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    #[\Override]
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
            'books_locked_before' => 'date',
            'vonage_key' => 'encrypted',
            'vonage_secret' => 'encrypted',
        ];
    }

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }
}
