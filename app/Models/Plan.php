<?php // src/app/Models/Plan.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = ['name', 'amount', 'currency', 'interval', 'team_id'];

    #[\Override]
    protected $casts = ['amount' => 'decimal:2'];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (Plan $plan): void {
            if (empty($plan->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $plan->team_id = $team->getKey();
            }
        });
    }

    public function subscriptions(): HasMany { return $this->hasMany(Subscription::class); }
}
