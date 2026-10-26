<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\HostResolver;
use App\Support\Https;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HttpsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $address): array
            {
                return $address === 'portal.example.com' ? ['203.0.113.10'] : [];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    /**
     * A self-signed certificate for the given name, made with PHP's OpenSSL.
     *
     * @return array{0: string, 1: string}
     */
    private function certificate(string $name, int $days = 30): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => $name], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, $days, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return [$certPem, $keyPem];
    }

    public function test_only_admins_manage_https()
    {
        $this->actingAs(User::factory()->provisioner()->create())->get('/admin/https')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/https')
            ->assertInertia(fn ($page) => $page->component('admin/Https')->where('status.state', 'off'));
    }

    public function test_requesting_a_lets_encrypt_certificate_queues_it_for_the_https_service()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/https/letsencrypt', ['domain' => 'Portal.Example.com', 'email' => 'admin@example.com', 'agree' => false, 'redirect' => true])
            ->assertSessionHasErrors('agree');
        $this->actingAs($admin)->post('/admin/https/letsencrypt', ['domain' => '203.0.113.10', 'email' => 'admin@example.com', 'agree' => true, 'redirect' => true])
            ->assertSessionHasErrors('domain');
        $this->actingAs($admin)->post('/admin/https/letsencrypt', ['domain' => 'nowhere.example.com', 'email' => 'admin@example.com', 'agree' => true, 'redirect' => true])
            ->assertSessionHasErrors('domain');

        $this->actingAs($admin)->post('/admin/https/letsencrypt', ['domain' => 'Portal.Example.com', 'email' => 'admin@example.com', 'agree' => true, 'redirect' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame("mode letsencrypt\ndomain portal.example.com\nemail admin@example.com\nredirect 1\n", Https::export());
        $this->assertSame('pending', Https::status()['state']);
        $this->assertFileExists(Https::dir().'/request');
    }

    public function test_an_uploaded_certificate_is_checked_before_it_is_used()
    {
        $admin = User::factory()->admin()->create();
        [$cert, $key] = $this->certificate('portal.example.com');
        [, $otherKey] = $this->certificate('portal.example.com');
        [$wrongCert, $wrongCertKey] = $this->certificate('other.example.com');

        $post = fn (array $data) => $this->actingAs($admin)->post('/admin/https/custom', [...['domain' => 'portal.example.com', 'redirect' => true], ...$data]);

        $post(['certificate' => 'garbage', 'private_key' => $key])->assertSessionHasErrors('certificate');
        $post(['certificate' => $cert, 'private_key' => $otherKey])->assertSessionHasErrors(['certificate' => 'The private key does not belong to this certificate.']);
        $post(['certificate' => $wrongCert, 'private_key' => $wrongCertKey])->assertSessionHasErrors('certificate');
        $this->assertFileDoesNotExist(Https::dir().'/custom.key');

        $post(['certificate' => $cert, 'private_key' => $key])->assertSessionHasNoErrors();
        $this->assertSame('custom', Https::settings()['mode']);
        $this->assertFileExists(Https::dir().'/custom.crt');
        $this->assertSame('600', substr(sprintf('%o', fileperms(Https::dir().'/custom.key')), -3));
    }

    public function test_wildcard_certificates_cover_one_level_of_subdomain()
    {
        $this->assertTrue(Https::covers(['*.example.com'], 'portal.example.com'));
        $this->assertFalse(Https::covers(['*.example.com'], 'a.portal.example.com'));
        $this->assertFalse(Https::covers(['*.example.com'], 'example.com'));
    }

    public function test_the_service_reports_its_result_back()
    {
        $this->artisan('fax2email:https', ['action' => 'status', '--state' => 'active', '--domain' => 'portal.example.com', '--issuer' => "Let's Encrypt", '--expires' => '2027-01-20T00:00:00Z'])
            ->assertSuccessful();

        $status = Https::status();
        $this->assertSame(['active', 'portal.example.com', "Let's Encrypt", '2027-01-20T00:00:00Z'], [$status['state'], $status['domain'], $status['issuer'], $status['expires_at']]);
    }

    public function test_turning_https_off()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/https/letsencrypt', ['domain' => 'portal.example.com', 'email' => 'admin@example.com', 'agree' => true, 'redirect' => true]);

        $this->actingAs($admin)->delete('/admin/https')->assertRedirect();

        $this->assertStringStartsWith("mode none\n", Https::export());
        $this->assertSame('off', Https::status()['state']);
    }
}
