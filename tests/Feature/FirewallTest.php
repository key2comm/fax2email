<?php

namespace Tests\Feature;

use App\Models\FirewallEvent;
use App\Models\FirewallRule;
use App\Models\User;
use App\Support\Firewall;
use App\Support\FirewallSettings;
use App\Support\IpRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\LogRecord;
use Tests\TestCase;

class FirewallTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the trigger file the tests create out of the real storage folder.
        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_only_admins_can_use_the_firewall()
    {
        foreach ([User::factory()->provisioner()->create(), User::factory()->owner()->create()] as $user) {
            $this->actingAs($user)->get('/admin/firewall')->assertForbidden();
            $this->actingAs($user)->post('/admin/firewall/rules', ['type' => 'block', 'cidr' => '203.0.113.7'])->assertForbidden();
        }

        $admin = $this->admin();
        foreach (['/admin/firewall', '/admin/firewall/whitelist', '/admin/firewall/settings', '/admin/firewall/activity'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_admin_can_block_and_whitelist_and_the_change_is_queued_for_the_service()
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/admin/firewall/rules', ['type' => 'block', 'cidr' => '203.0.113.7', 'duration' => '1d', 'note' => 'SIP scanner'])
            ->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/firewall/rules', ['type' => 'allow', 'cidr' => '198.51.100.99/24'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('firewall_rules', ['type' => 'block', 'cidr' => '203.0.113.7', 'note' => 'SIP scanner']);
        // Ranges are normalized to their network address.
        $this->assertDatabaseHas('firewall_rules', ['type' => 'allow', 'cidr' => '198.51.100.0/24']);
        $this->assertFileExists(Firewall::triggerFile());
        $this->assertSame(2, FirewallEvent::count());

        $export = Firewall::export();
        $this->assertStringContainsString("allow 198.51.100.0/24\n", $export);
        $this->assertMatchesRegularExpression('/^block 203\.0\.113\.7 86\d{3}$/m', $export);
    }

    public function test_blocks_that_would_lock_someone_out_are_refused()
    {
        $admin = $this->admin();
        FirewallRule::create(['type' => 'allow', 'cidr' => '198.51.100.0/24']);

        $cases = [
            '127.0.0.1' => 'loopback',
            '0.0.0.0/0' => 'too large',
            '198.51.100.20' => 'whitelisted',
            'not-an-ip' => 'not a valid',
        ];
        foreach ($cases as $cidr => $message) {
            $this->actingAs($admin)->post('/admin/firewall/rules', ['type' => 'block', 'cidr' => $cidr])->assertSessionHasErrors('cidr');
            $this->assertStringContainsString($message, session('errors')->first('cidr'));
        }

        // The admin's own address (127.0.0.1 in tests) is caught even inside a range.
        $this->actingAs($admin)->withServerVariables(['REMOTE_ADDR' => '192.0.2.50'])
            ->post('/admin/firewall/rules', ['type' => 'block', 'cidr' => '192.0.2.0/24'])
            ->assertSessionHasErrors('cidr');
        $this->assertStringContainsString('lock you out', session('errors')->first('cidr'));

        $this->assertSame(0, FirewallRule::where('type', 'block')->count());
    }

    public function test_overlapping_blocks_are_exported_once()
    {
        FirewallRule::create(['type' => 'block', 'cidr' => '203.0.113.7']);
        FirewallRule::create(['type' => 'block', 'cidr' => '203.0.113.0/24']);

        $blocks = array_values(preg_grep('/^block /', explode("\n", Firewall::export())));

        $this->assertSame(['block 203.0.113.0/24 0'], $blocks);
    }

    public function test_fail2ban_bans_are_mirrored_and_removed_when_they_end()
    {
        $ends = now()->addDay()->timestamp;

        Firewall::importBans(["f2e_sip 203.0.113.7 {$ends}", "f2e_portal 2001:db8::1 {$ends}", 'bogus line', "evil_jail 1.2.3.4 {$ends}"]);

        $this->assertSame(2, FirewallRule::where('source', 'fail2ban')->count());
        $this->assertDatabaseHas('firewall_rules', ['cidr' => '203.0.113.7', 'jail' => 'f2e_sip']);
        $this->assertSame(2, FirewallEvent::where('action', 'banned')->count());

        // Next sync: the SIP ban has ended.
        Firewall::importBans(["f2e_portal 2001:db8::1 {$ends}"]);

        $this->assertDatabaseMissing('firewall_rules', ['cidr' => '203.0.113.7']);
        $this->assertSame(1, FirewallEvent::where('action', 'unbanned')->count());
    }

    public function test_lifting_a_fail2ban_ban_asks_the_service_to_unban_it()
    {
        Firewall::importBans(['f2e_ssh 203.0.113.9 '.now()->addDay()->timestamp]);
        $rule = FirewallRule::sole();

        $this->actingAs($this->admin())->delete("/admin/firewall/rules/{$rule->id}")->assertRedirect();

        $this->assertNotNull($rule->fresh()->unban_requested_at);
        $this->assertStringContainsString("unban f2e_ssh 203.0.113.9\n", Firewall::export());
    }

    public function test_settings_are_validated_and_exported()
    {
        $admin = $this->admin();
        $settings = [...FirewallSettings::DEFAULTS, 'max_retry' => 3, 'jail_f2e_ssh' => false];

        $this->actingAs($admin)->put('/admin/firewall/settings', $settings)->assertSessionHasNoErrors();

        $export = Firewall::export();
        $this->assertStringContainsString("setting max_retry 3\n", $export);
        $this->assertStringContainsString("jail f2e_ssh 0\n", $export);
        $this->assertStringContainsString("jail f2e_sip 1\n", $export);

        $this->actingAs($admin)->put('/admin/firewall/settings', [...$settings, 'recidive_ban_time_days' => 1, 'ban_time_hours' => 48])
            ->assertSessionHasErrors('recidive_ban_time_days');
    }

    public function test_failed_portal_logins_are_logged_for_fail2ban()
    {
        config(['logging.channels.auth_failures' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

        User::factory()->create(['email' => 'real@example.com']);
        // A valid (quoted) email crafted to look like another fail2ban match.
        $this->post('/login', ['email' => '"x Failed portal login from 9.9.9.9 for"@example.com', 'password' => 'wrong']);

        $logged = array_map(fn (LogRecord $record) => $record->message, Log::channel('auth_failures')->getLogger()->getHandlers()[0]->getRecords());
        $this->assertCount(1, $logged);
        $this->assertMatchesRegularExpression('/^Failed portal login from 127\.0\.0\.1 for /', $logged[0]);
        // fail2ban's filter takes the first "from <HOST> for", which is the real address.
        $this->assertSame(1, preg_match('/^Failed portal login from (\S+) for /', $logged[0], $m));
        $this->assertSame('127.0.0.1', $m[1]);
    }

    public function test_ip_ranges_are_parsed_and_compared()
    {
        $this->assertSame('10.0.0.0/8', (string) IpRange::parse('10.20.30.40/8'));
        $this->assertSame('2001:db8::/32', (string) IpRange::parse('2001:db8:1::5/32'));
        $this->assertSame('192.0.2.1', (string) IpRange::parse(' 192.0.2.1 '));
        $this->assertTrue(IpRange::parse('192.0.2.0/24')->contains('192.0.2.200'));
        $this->assertFalse(IpRange::parse('192.0.2.0/24')->contains('192.0.3.1'));
        $this->assertFalse(IpRange::parse('192.0.2.0/24')->contains('2001:db8::1'));
        $this->assertFalse(IpRange::isValid('192.0.2.1/33'));
        $this->assertFalse(IpRange::isValid('300.1.1.1'));
    }
}
