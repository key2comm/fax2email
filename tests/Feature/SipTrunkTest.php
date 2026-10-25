<?php

namespace Tests\Feature;

use App\Jobs\ReloadFreeswitchTrunks;
use App\Models\FirewallRule;
use App\Models\SipTrunk;
use App\Models\User;
use App\Support\Firewall;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SipTrunkTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<string>> */
    private array $dns = [
        'sip.provider.com' => ['203.0.113.10', '203.0.113.11'],
        'sip2.provider.com' => ['198.51.100.20'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
        Queue::fake();

        $test = $this;
        $this->app->instance(HostResolver::class, new class($test) extends HostResolver
        {
            public function __construct(private SipTrunkTest $test) {}

            public function resolve(string $address): array
            {
                return $this->test->lookup($address) ?? parent::resolve($address);
            }
        });
        config([
            'fax2email.freeswitch.xml_user' => 'freeswitch',
            'fax2email.freeswitch.xml_password' => 'xml-secret',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    /**
     * @return list<string>|null
     */
    public function lookup(string $address): ?array
    {
        if (HostResolver::isHostname($address)) {
            return $this->dns[$address] ?? [];
        }

        return null;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function trunkData(array $overrides = []): array
    {
        return [
            'name' => 'Provider A',
            'enabled' => true,
            'auth_type' => 'ip',
            'transport' => 'udp',
            'host' => 'sip.provider.com',
            'port' => 5060,
            'username' => '',
            'password' => '',
            'extra_addresses' => "sip2.provider.com\n192.0.2.0/24",
            ...$overrides,
        ];
    }

    public function test_only_admins_can_manage_trunks()
    {
        $provisioner = User::factory()->provisioner()->create();

        $this->actingAs($provisioner)->get('/admin/trunks')->assertForbidden();
        $this->actingAs($provisioner)->post('/admin/trunks', $this->trunkData())->assertForbidden();
        $this->actingAs($this->admin())->get('/admin/trunks')->assertOk();
        $this->actingAs($this->admin())->get('/admin/trunks/create')->assertOk();
    }

    public function test_adding_a_trunk_whitelists_its_addresses_and_reloads_freeswitch()
    {
        $this->actingAs($this->admin())->post('/admin/trunks', $this->trunkData())->assertSessionHasNoErrors();

        $trunk = SipTrunk::sole();
        $this->assertSame(['192.0.2.0/24', '198.51.100.20', '203.0.113.10', '203.0.113.11'], $trunk->resolved_addresses);

        $rules = FirewallRule::where('source', 'trunk')->orderBy('cidr')->get();
        $this->assertSame(['192.0.2.0/24', '198.51.100.20', '203.0.113.10', '203.0.113.11'], $rules->pluck('cidr')->all());
        $this->assertSame('Provider trunk: Provider A', $rules->first()->note);
        $this->assertTrue($rules->every(fn ($rule) => $rule->type === 'allow' && $rule->sip_trunk_id === $trunk->id));

        $export = Firewall::export();
        $this->assertStringContainsString("allow 203.0.113.10\n", $export);
        $this->assertStringContainsString("trunk 203.0.113.10\n", $export);
        $this->assertFileExists(Firewall::triggerFile());

        Queue::assertPushed(ReloadFreeswitchTrunks::class);
    }

    public function test_trunk_whitelist_entries_cannot_be_removed_from_the_whitelist_page()
    {
        $this->actingAs($admin = $this->admin())->post('/admin/trunks', $this->trunkData());
        $rule = FirewallRule::where('source', 'trunk')->first();

        $this->actingAs($admin)->delete("/admin/firewall/rules/{$rule->id}")->assertSessionHasErrors('cidr');
        $this->assertModelExists($rule);

        $this->actingAs($admin)->get('/admin/firewall/whitelist')
            ->assertInertia(fn ($page) => $page->where('rules.0.is_trunk', true)->where('rules.0.source', 'Provider trunk'));
    }

    public function test_disabling_or_deleting_a_trunk_removes_its_whitelist_entries()
    {
        $this->actingAs($admin = $this->admin())->post('/admin/trunks', $this->trunkData());
        $trunk = SipTrunk::sole();

        $this->actingAs($admin)->put("/admin/trunks/{$trunk->id}", $this->trunkData(['enabled' => false]))->assertSessionHasNoErrors();
        $this->assertSame(0, FirewallRule::where('source', 'trunk')->count());

        $this->actingAs($admin)->put("/admin/trunks/{$trunk->id}", $this->trunkData())->assertSessionHasNoErrors();
        $this->assertSame(4, FirewallRule::where('source', 'trunk')->count());

        $this->actingAs($admin)->delete("/admin/trunks/{$trunk->id}")->assertRedirect('/admin/trunks');
        $this->assertModelMissing($trunk);
        $this->assertSame(0, FirewallRule::count());
        Queue::assertPushed(ReloadFreeswitchTrunks::class, fn ($job) => $job->removeGateways === [['profile' => 'fax2email', 'gateway' => "trunk-{$trunk->id}"]]);
    }

    public function test_credential_trunks_need_a_username_and_password_and_keep_the_saved_password()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/trunks', $this->trunkData(['auth_type' => 'credentials']))
            ->assertSessionHasErrors(['username']);
        $this->actingAs($admin)->post('/admin/trunks', $this->trunkData(['auth_type' => 'credentials', 'username' => 'acct1']))
            ->assertSessionHasErrors(['password']);

        $this->actingAs($admin)->post('/admin/trunks', $this->trunkData(['auth_type' => 'credentials', 'transport' => 'tls', 'port' => 5061, 'username' => 'acct1', 'password' => 's3cret']))
            ->assertSessionHasNoErrors();
        $trunk = SipTrunk::sole();

        $this->actingAs($admin)->put("/admin/trunks/{$trunk->id}", $this->trunkData(['auth_type' => 'credentials', 'transport' => 'tls', 'port' => 5062, 'username' => 'acct1', 'password' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame('s3cret', $trunk->fresh()->password);
        $this->assertSame(5062, $trunk->fresh()->port);
        $this->assertSame('fax2email-tls', $trunk->fresh()->profileName());
    }

    public function test_addresses_are_validated()
    {
        $admin = $this->admin();
        $cases = [
            [['host' => 'nowhere.invalid'], 'host'],
            [['host' => '203.0.113.0/24'], 'host'],
            [['host' => 'not a host!'], 'host'],
            [['extra_addresses' => '10.0.0.0/8'], 'extra_addresses'],
            [['extra_addresses' => '127.0.0.1'], 'extra_addresses'],
            [['port' => 70000], 'port'],
            [['transport' => 'sctp'], 'transport'],
        ];

        foreach ($cases as [$override, $field]) {
            $this->actingAs($admin)->post('/admin/trunks', $this->trunkData($override))->assertSessionHasErrors($field);
        }
        $this->assertSame(0, SipTrunk::count());
    }

    public function test_hostname_changes_are_picked_up_by_the_resolve_command()
    {
        $this->actingAs($this->admin())->post('/admin/trunks', $this->trunkData(['extra_addresses' => '']));
        Queue::fake();

        $this->dns['sip.provider.com'] = ['203.0.113.10', '203.0.113.99'];
        $this->artisan('fax2email:trunk-resolve')->assertSuccessful();

        $this->assertSame(['203.0.113.10', '203.0.113.99'], FirewallRule::where('source', 'trunk')->orderBy('cidr')->pluck('cidr')->all());
        Queue::assertPushed(ReloadFreeswitchTrunks::class);

        // If DNS fails, the last known addresses stay so the provider is not locked out.
        $this->dns['sip.provider.com'] = [];
        $this->artisan('fax2email:trunk-resolve')->assertSuccessful();
        $this->assertSame(2, FirewallRule::where('source', 'trunk')->count());
        $this->assertStringContainsString('sip.provider.com', SipTrunk::sole()->resolve_error);
    }

    public function test_freeswitch_xml_endpoint_requires_local_access_and_credentials()
    {
        $this->post('/freeswitch/xml', ['section' => 'configuration', 'key_value' => 'sofia.conf'])->assertForbidden();
        $this->withBasicAuth('freeswitch', 'wrong')->post('/freeswitch/xml', ['section' => 'configuration', 'key_value' => 'sofia.conf'])->assertForbidden();
        $this->withBasicAuth('freeswitch', 'xml-secret')->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->post('/freeswitch/xml', ['section' => 'configuration', 'key_value' => 'sofia.conf'])->assertForbidden();

        $this->withBasicAuth('freeswitch', 'xml-secret')->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->post('/freeswitch/xml', ['section' => 'configuration', 'key_value' => 'switch.conf'])
            ->assertOk()->assertSee('status="not found"', false);
    }

    public function test_freeswitch_gets_gateways_acl_and_fax_dialplan()
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/trunks', $this->trunkData(['extra_addresses' => '']));
        $this->actingAs($admin)->post('/admin/trunks', $this->trunkData([
            'name' => 'Provider B', 'auth_type' => 'credentials', 'transport' => 'tcp', 'host' => 'sip2.provider.com',
            'username' => 'acct<1>', 'password' => 'p&"ss', 'extra_addresses' => '',
        ]));
        [$ipTrunk, $regTrunk] = SipTrunk::orderBy('id')->get();
        auth()->logout();

        $sofia = $this->withBasicAuth('freeswitch', 'xml-secret')->post('/freeswitch/xml', ['section' => 'configuration', 'key_value' => 'sofia.conf'])->assertOk()->getContent();
        $xml = simplexml_load_string($sofia);
        $this->assertNotFalse($xml, 'sofia.conf must be valid XML even with special characters in credentials');

        $gateway = fn (string $name) => $xml->xpath("//gateway[@name='{$name}']")[0];
        $param = fn ($gw, string $name) => (string) $gw->xpath("param[@name='{$name}']/@value")[0];

        $this->assertSame('false', $param($gateway("trunk-{$ipTrunk->id}"), 'register'));
        $this->assertSame('true', $param($gateway("trunk-{$regTrunk->id}"), 'register'));
        $this->assertSame('acct<1>', $param($gateway("trunk-{$regTrunk->id}"), 'username'));
        $this->assertSame('p&"ss', $param($gateway("trunk-{$regTrunk->id}"), 'password'));
        $this->assertSame('tcp', $param($gateway("trunk-{$regTrunk->id}"), 'register-transport'));
        $this->assertSame('fax2email_trunks', (string) $xml->xpath("//profile[@name='fax2email']/settings/param[@name='apply-inbound-acl']/@value")[0]);
        $this->assertSame('true', (string) $xml->xpath("//profile[@name='fax2email-tls']/settings/param[@name='tls-only']/@value")[0]);

        $acl = $this->withBasicAuth('freeswitch', 'xml-secret')->post('/freeswitch/xml', ['section' => 'configuration', 'key_value' => 'acl.conf'])->getContent();
        $nodes = array_map('strval', simplexml_load_string($acl)->xpath("//list[@name='fax2email_trunks']/node/@cidr"));
        $this->assertSame(['198.51.100.20/32', '203.0.113.10/32', '203.0.113.11/32'], $nodes);

        $dialplan = $this->withBasicAuth('freeswitch', 'xml-secret')->post('/freeswitch/xml', ['section' => 'dialplan', 'Caller-Context' => 'public'])->getContent();
        // No fax number is set up for this call, so it is refused (see FaxReceptionTest for answered calls).
        $this->assertStringContainsString('data="UNALLOCATED_NUMBER"', $dialplan);
    }
}
