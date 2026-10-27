<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\SenderDomain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SenderDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_and_remove_domains_for_their_own_account()
    {
        $account = Account::factory()->create();
        $owner = User::factory()->owner($account)->create();

        $this->actingAs($owner)
            ->post('/sender-domains', ['account_id' => $account->id, 'domain' => ' @Acme.Test '])
            ->assertSessionHasNoErrors();

        $domain = SenderDomain::sole();
        $this->assertSame(['acme.test', $account->id, $owner->id], [$domain->domain, $domain->account_id, $domain->created_by]);

        $this->actingAs($owner)->delete("/sender-domains/{$domain->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, SenderDomain::count());
    }

    public function test_staff_can_manage_any_accounts_domains()
    {
        $account = Account::factory()->create();

        $this->actingAs(User::factory()->provisioner()->create())
            ->post('/sender-domains', ['account_id' => $account->id, 'domain' => 'acme.test'])
            ->assertSessionHasNoErrors();

        $this->assertSame($account->id, SenderDomain::sole()->account_id);
    }

    public function test_account_users_and_other_owners_cannot_manage_domains()
    {
        $account = Account::factory()->create();
        $domain = SenderDomain::create(['account_id' => $account->id, 'domain' => 'acme.test']);
        $outsider = User::factory()->owner(Account::factory()->create())->create();

        foreach ([User::factory()->member($account)->create(), $outsider] as $user) {
            $this->actingAs($user)->post('/sender-domains', ['account_id' => $account->id, 'domain' => 'other.test'])->assertForbidden();
            $this->actingAs($user)->delete("/sender-domains/{$domain->id}")->assertForbidden();
        }
        $this->assertSame(['acme.test'], SenderDomain::pluck('domain')->all());
    }

    public function test_public_taken_and_invalid_domains_are_refused()
    {
        config(['fax2email.mail.domain' => 'fax.example.com']);
        $account = Account::factory()->create();
        $owner = User::factory()->owner($account)->create();
        SenderDomain::create(['account_id' => Account::factory()->create()->id, 'domain' => 'taken.test']);

        foreach (['gmail.com', 'Outlook.com', 'fax.example.com', 'taken.test', 'not a domain', 'localhost', ''] as $domain) {
            $this->actingAs($owner)
                ->post('/sender-domains', ['account_id' => $account->id, 'domain' => $domain])
                ->assertSessionHasErrors('domain');
        }
        $this->assertSame(0, $account->senderDomains()->count());
    }

    public function test_owners_see_their_domains_on_the_users_page()
    {
        $account = Account::factory()->create();
        SenderDomain::create(['account_id' => $account->id, 'domain' => 'acme.test']);

        $this->actingAs(User::factory()->owner($account)->create())
            ->get('/users')
            ->assertInertia(fn ($page) => $page
                ->where('senderDomains.0.domain', 'acme.test')
                ->where('canManageSenderDomains', true));

        $this->actingAs(User::factory()->member($account)->create())
            ->get('/users')
            ->assertForbidden();
    }
}
