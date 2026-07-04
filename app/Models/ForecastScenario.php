<?php // src/app/Models/ForecastScenario.php
declare(strict_types=1);
namespace App\Models;
use App\Traits\IsTenantModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForecastScenario extends Model
{
    use HasFactory;
    use IsTenantModel;

    #[\Override]
    protected $fillable = ['name', 'team_id'];

    #[\Override]
    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (ForecastScenario $scenario): void {
            if (empty($scenario->team_id) && ($team = auth()->user()?->currentTeam) !== null) {
                $scenario->team_id = $team->getKey();
            }
        });
    }

    public function lines(): HasMany { return $this->hasMany(ForecastScenarioLine::class); }
}
