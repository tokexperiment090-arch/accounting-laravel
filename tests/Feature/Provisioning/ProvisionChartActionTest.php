<?php
declare(strict_types=1);

namespace Tests\Feature\Provisioning;

use App\Filament\App\Resources\ChartOfAccounts\Pages\ListChartOfAccounts;
use App\Models\Account;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProvisionChartActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_action_provisions_the_current_tenant(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->teams()->attach($team); // membership so the panel accepts the tenant
        $user->forceFill(['current_team_id' => $team->id])->save();

        $this->actingAs($user);
        Filament::setTenant($team);

        Livewire::test(ListChartOfAccounts::class)
            ->callAction('provisionChart');

        $this->assertSame(18, Account::where('team_id', $team->id)->count());
    }
}
