<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Account;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\UserInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_any_role()
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/invitations', ['email' => 'new-admin@example.com', 'role' => 'admin'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)
            ->post('/invitations', ['email' => 'prov@example.com', 'role' => 'provisioner'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Invitation::count());
        Notification::assertSentOnDemand(UserInvited::class);
    }

    public function test_provisioner_cannot_invite_staff()
    {
        $provisioner = User::factory()->provisioner()->create();

        foreach (['admin', 'provisioner'] as $role) {
            $this->actingAs($provisioner)
                ->post('/invitations', ['email' => "{$role}@example.com", 'role' => $role])
                ->assertForbidden();
        }
    }

    public function test_provisioner_can_invite_owners_and_users_to_any_account()
    {
        Notification::fake();
        $provisioner = User::factory()->provisioner()->create();
        $account = Account::factory()->create();

        $this->actingAs($provisioner)
            ->post('/invitations', ['email' => 'owner@example.com', 'role' => 'account_owner', 'account_id' => $account->id])
            ->assertSessionHasNoErrors();

        $this->actingAs($provisioner)
            ->post('/invitations', ['email' => 'user@example.com', 'role' => 'account_user', 'account_id' => $account->id])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, $account->invitations()->count());
    }

    public function test_provisioner_cannot_delete_admins_or_provisioners()
    {
        $provisioner = User::factory()->provisioner()->create();

        $this->actingAs($provisioner)->delete('/users/'.User::factory()->admin()->create()->id)->assertForbidden();
        $this->actingAs($provisioner)->delete('/users/'.User::factory()->provisioner()->create()->id)->assertForbidden();
    }

    public function test_provisioner_cannot_change_an_admins_role()
    {
        $provisioner = User::factory()->provisioner()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($provisioner)
            ->patch("/users/{$admin->id}/role", ['role' => 'provisioner'])
            ->assertForbidden();

        $this->assertSame(Role::Admin, $admin->fresh()->role);
    }

    public function test_admin_can_delete_provisioners_and_other_admins_but_not_themselves()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete('/users/'.User::factory()->provisioner()->create()->id)->assertRedirect();
        $this->actingAs($admin)->delete('/users/'.User::factory()->admin()->create()->id)->assertRedirect();
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertForbidden();
    }

    public function test_owner_can_invite_owners_and_users_only_to_their_own_account()
    {
        Notification::fake();
        $owner = User::factory()->owner()->create();
        $otherAccount = Account::factory()->create();

        $this->actingAs($owner)
            ->post('/invitations', ['email' => 'a@example.com', 'role' => 'account_user', 'account_id' => $owner->account_id])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post('/invitations', ['email' => 'b@example.com', 'role' => 'account_owner', 'account_id' => $owner->account_id])
            ->assertSessionHasNoErrors();

        $this->actingAs($owner)
            ->post('/invitations', ['email' => 'c@example.com', 'role' => 'account_user', 'account_id' => $otherAccount->id])
            ->assertForbidden();

        $this->actingAs($owner)
            ->post('/invitations', ['email' => 'd@example.com', 'role' => 'provisioner'])
            ->assertForbidden();
    }

    public function test_owner_can_promote_users_but_cannot_demote_or_delete_other_owners()
    {
        $account = Account::factory()->create();
        $owner = User::factory()->owner($account)->create();
        $otherOwner = User::factory()->owner($account)->create();
        $member = User::factory()->member($account)->create();

        $this->actingAs($owner)->patch("/users/{$member->id}/role", ['role' => 'account_owner'])->assertRedirect();
        $this->assertSame(Role::AccountOwner, $member->fresh()->role);

        $this->actingAs($owner)->patch("/users/{$otherOwner->id}/role", ['role' => 'account_user'])->assertForbidden();
        $this->actingAs($owner)->delete("/users/{$otherOwner->id}")->assertForbidden();
    }

    public function test_owner_cannot_touch_users_in_other_accounts()
    {
        $owner = User::factory()->owner()->create();
        $stranger = User::factory()->member()->create();

        $this->actingAs($owner)->delete("/users/{$stranger->id}")->assertForbidden();
        $this->actingAs($owner)->patch("/users/{$stranger->id}/role", ['role' => 'account_owner'])->assertForbidden();
    }

    public function test_owner_only_sees_their_own_account_users()
    {
        $owner = User::factory()->owner()->create();
        User::factory()->member($owner->account)->create();
        User::factory()->member()->create();

        $this->actingAs($owner)
            ->get('/users?account='.Account::factory()->create()->id)
            ->assertInertia(fn ($page) => $page
                ->component('users/Index')
                ->has('users', 2)
                ->where('account.id', $owner->account_id));
    }

    public function test_account_users_cannot_manage_anyone()
    {
        $member = User::factory()->member()->create();

        $this->actingAs($member)->get('/users')->assertForbidden();
        $this->actingAs($member)->get('/accounts')->assertForbidden();
    }

    public function test_last_owner_of_an_account_cannot_be_removed_or_demoted()
    {
        $provisioner = User::factory()->provisioner()->create();
        $owner = User::factory()->owner()->create();

        $this->actingAs($provisioner)->delete("/users/{$owner->id}")->assertForbidden();
        $this->actingAs($provisioner)->patch("/users/{$owner->id}/role", ['role' => 'account_user'])->assertForbidden();
    }

    public function test_staff_and_account_roles_cannot_be_swapped()
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->member()->create();

        $this->actingAs($admin)->patch("/users/{$member->id}/role", ['role' => 'provisioner'])->assertForbidden();
    }

    public function test_only_admins_can_delete_accounts()
    {
        $account = Account::factory()->create();

        $this->actingAs(User::factory()->provisioner()->create())->delete("/accounts/{$account->id}")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->delete("/accounts/{$account->id}")->assertRedirect();

        $this->assertModelMissing($account);
    }

    public function test_staff_can_set_an_accounts_time_zone()
    {
        $provisioner = User::factory()->provisioner()->create();

        $this->actingAs($provisioner)->post('/accounts', ['name' => 'Acme Dental', 'timezone' => 'America/Toronto'])->assertSessionHasNoErrors();
        $account = Account::sole();
        $this->assertSame('America/Toronto', $account->timezone);

        $this->actingAs($provisioner)->patch("/accounts/{$account->id}", ['name' => 'Acme Dental', 'timezone' => 'America/Vancouver', 'is_active' => true])
            ->assertSessionHasNoErrors();
        $this->assertSame('America/Vancouver', $account->fresh()->timezone);

        // Enabling or disabling an account leaves its zone alone.
        $this->actingAs($provisioner)->patch("/accounts/{$account->id}", ['name' => 'Acme Dental', 'is_active' => false])->assertSessionHasNoErrors();
        $this->assertSame('America/Vancouver', $account->fresh()->timezone);

        $this->actingAs($provisioner)->patch("/accounts/{$account->id}", ['name' => 'Acme Dental', 'timezone' => 'Mars/Olympus', 'is_active' => true])
            ->assertSessionHasErrors('timezone');
        $this->actingAs(User::factory()->owner($account)->create())
            ->patch("/accounts/{$account->id}", ['name' => 'Acme Dental', 'timezone' => 'UTC', 'is_active' => true])->assertForbidden();
        $this->assertSame('America/Vancouver', $account->fresh()->timezone);
    }

    public function test_invitation_can_be_accepted_once()
    {
        $account = Account::factory()->create();
        [, $token] = Invitation::issue(['email' => 'new@example.com', 'role' => Role::AccountOwner, 'account_id' => $account->id]);

        $this->get("/invitations/{$token}")->assertOk();

        $this->post("/invitations/{$token}", [
            'name' => 'New Owner',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame(Role::AccountOwner, $user->role);
        $this->assertSame($account->id, $user->account_id);
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->get("/invitations/{$token}")->assertNotFound();
    }

    public function test_expired_invitations_cannot_be_used()
    {
        [, $token] = Invitation::issue(['email' => 'late@example.com', 'role' => Role::Provisioner]);
        $this->travel(Invitation::EXPIRES_AFTER_DAYS + 1)->days();

        $this->get("/invitations/{$token}")->assertNotFound();
    }

    public function test_public_registration_is_disabled()
    {
        $this->get('/register')->assertNotFound();
    }
}
