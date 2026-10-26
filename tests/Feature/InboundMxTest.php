<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\MxCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundMxTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<array{host: string, priority: int}>> */
    private array $mx = [];

    /** @var array<string, list<string>> */
    private array $a = [];

    private array $smtp = ['ok' => true, 'detail' => 'accepts mail'];

    private function checker(): MxCheck
    {
        return new MxCheck(
            fn (string $domain) => $this->mx[$domain] ?? [],
            fn (string $host) => $this->a[$host] ?? [],
            fn () => $this->smtp,
            ['203.0.113.10'],
        );
    }

    /**
     * @return array<string, string>
     */
    private function statuses(string $domain): array
    {
        return collect($this->checker()->run($domain))->pluck('status', 'key')->all();
    }

    public function test_a_correct_setup_passes_every_check()
    {
        $this->mx['fax.example.com'] = [['host' => 'fax.example.com', 'priority' => 10]];
        $this->a['fax.example.com'] = ['203.0.113.10'];

        $this->assertSame(['mx' => 'ok', 'resolve' => 'ok', 'server' => 'ok', 'smtp' => 'ok'], $this->statuses('fax.example.com'));
    }

    public function test_a_missing_mx_record_fails_straight_away()
    {
        $results = $this->checker()->run('fax.example.com');

        $this->assertSame([['mx', 'fail']], collect($results)->map(fn ($r) => [$r['key'], $r['status']])->all());
        $this->assertStringContainsString('No MX record found', $results[0]['detail']);
    }

    public function test_a_mail_host_without_an_address_fails()
    {
        $this->mx['fax.example.com'] = [['host' => 'mail.example.com', 'priority' => 10]];

        $this->assertSame(['mx' => 'ok', 'resolve' => 'fail'], $this->statuses('fax.example.com'));
    }

    public function test_pointing_elsewhere_and_a_blocked_port_are_warnings()
    {
        $this->mx['fax.example.com'] = [['host' => 'fax.example.com', 'priority' => 10]];
        $this->a['fax.example.com'] = ['198.51.100.99'];
        $this->smtp = ['ok' => false, 'detail' => 'Could not connect'];

        $this->assertSame(['mx' => 'ok', 'resolve' => 'ok', 'server' => 'warn', 'smtp' => 'warn'], $this->statuses('fax.example.com'));
    }

    public function test_suggested_records_use_the_portal_host_when_it_already_points_here()
    {
        $this->a['portal.example.com'] = ['203.0.113.10'];

        $viaPortal = $this->checker()->suggestedRecords('fax.example.com', 'portal.example.com');
        $this->assertSame('portal.example.com', $viaPortal['mail_host']);
        $this->assertSame([['type' => 'MX', 'name' => 'fax.example.com', 'value' => 'portal.example.com', 'priority' => 10]], $viaPortal['records']);
        $this->assertTrue($viaPortal['subdomain']);

        $ownHost = $this->checker()->suggestedRecords('faxdomain.com', '203.0.113.10');
        $this->assertSame([
            ['type' => 'A', 'name' => 'faxdomain.com', 'value' => '203.0.113.10', 'priority' => null],
            ['type' => 'MX', 'name' => 'faxdomain.com', 'value' => 'faxdomain.com', 'priority' => 10],
        ], $ownHost['records']);
        $this->assertFalse($ownHost['subdomain']);
    }

    public function test_admins_see_the_records_and_can_run_the_check()
    {
        config(['fax2email.mail.domain' => 'fax.example.com', 'app.url' => 'https://portal.example.com']);
        $this->mx['fax.example.com'] = [['host' => 'fax.example.com', 'priority' => 10]];
        $this->a['fax.example.com'] = ['203.0.113.10'];
        $this->app->instance(MxCheck::class, $this->checker());
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/mail/inbound-mx')
            ->assertInertia(fn ($page) => $page->component('admin/InboundMx')->where('domain', 'fax.example.com')->where('results', null));

        $this->actingAs($admin)->from('/admin/mail/inbound-mx')->post('/admin/mail/inbound-mx/check')->assertRedirect('/admin/mail/inbound-mx');
        $this->actingAs($admin)->get('/admin/mail/inbound-mx')->assertInertia(fn ($page) => $page->has('results.items', 4));

        $this->actingAs(User::factory()->provisioner()->create())->get('/admin/mail/inbound-mx')->assertForbidden();
    }
}
