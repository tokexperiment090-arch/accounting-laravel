<?php

// src/tests/Feature/Subscription/SubscriptionTenancyTest.php
declare(strict_types=1);

namespace Tests\Feature\Subscription;

use App\Models\Plan;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_stamps_actor_team(): void
    {
        $user = User::factory()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => 'Acme', 'personal_team' => false]);
        $user->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($user);

        $plan = Plan::create(['name' => 'Pro', 'amount' => 10, 'interval' => 'monthly']);

        $this->assertSame($team->id, (int) $plan->team_id);
    }
}
