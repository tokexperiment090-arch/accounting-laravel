<?php // src/tests/Feature/Forecast/ForecastTenancyTest.php
declare(strict_types=1);
namespace Tests\Feature\Forecast;

use App\Models\ForecastScenario;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ForecastTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_scenario_stamps_actor_team(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $scenario = ForecastScenario::create(['name' => 'Base case']);

        $this->assertSame($team->id, (int) $scenario->team_id);
    }
}
