<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimezoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_see_times_in_utc_until_they_choose_a_time_zone()
    {
        $user = User::factory()->create();

        $this->assertSame('UTC', $user->fresh()->timezone);
        $this->actingAs($user)->get('/settings/profile')
            ->assertInertia(fn ($page) => $page->where('auth.user.timezone', 'UTC')->has('timezones'));
    }

    public function test_users_can_choose_their_time_zone()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'timezone' => 'America/Toronto'])
            ->assertSessionHasNoErrors();
        $this->assertSame('America/Toronto', $user->fresh()->timezone);

        $this->actingAs($user)->patch('/settings/profile', ['name' => $user->name, 'email' => $user->email, 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');
        $this->assertSame('America/Toronto', $user->fresh()->timezone);
    }
}
