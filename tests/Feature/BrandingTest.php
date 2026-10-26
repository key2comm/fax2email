<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
        config(['filesystems.disks.local.root' => storage_path('app/private')]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    public function test_only_admins_can_change_branding()
    {
        $this->actingAs(User::factory()->provisioner()->create())->get('/admin/branding')->assertForbidden();
        $this->actingAs(User::factory()->provisioner()->create())->post('/admin/branding', ['name' => 'X'])->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/admin/branding')->assertOk();
    }

    public function test_name_logo_favicon_and_colour_are_saved_and_shared_with_every_page()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/admin/branding', [
            'name' => 'Acme Fax',
            'color' => '#AA3300',
            'logo' => UploadedFile::fake()->image('logo.jpg', 1200, 300),
            'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertSessionHasNoErrors();

        // Uploads are re-encoded as PNG and scaled to fit.
        $logo = Storage::disk('local')->get('branding/logo.png');
        $this->assertStringStartsWith("\x89PNG", $logo);
        $this->assertSame([600, 150], array_slice(getimagesizefromstring($logo), 0, 2));

        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page
            ->where('branding.name', 'Acme Fax')
            ->where('branding.color', '#aa3300')
            ->where('branding.logo', fn ($url) => str_contains($url, '/branding/logo?v='))
            ->where('branding.logo_dark', null));

        // The brand colour and favicon are in the page itself, so they apply before the app loads.
        $html = $this->actingAs($admin)->get('/dashboard')->getContent();
        $this->assertStringContainsString('--tblr-primary: #aa3300', $html);
        $this->assertStringContainsString('/branding/favicon?v=', $html);
        // Branding is for the website only: emails keep the server's app name.
        $this->assertNotSame('Acme Fax', config('app.name'));
    }

    public function test_logos_are_public_but_only_once_uploaded()
    {
        $this->get('/branding/logo')->assertNotFound();

        $this->actingAs(User::factory()->admin()->create())->post('/admin/branding', ['logo' => UploadedFile::fake()->image('logo.png', 200, 50)]);
        auth()->logout();

        $this->get('/branding/logo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/branding/evil')->assertNotFound();
    }

    public function test_svg_and_non_images_are_refused()
    {
        $admin = User::factory()->admin()->create();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($admin)->post('/admin/branding', ['logo' => $svg])->assertSessionHasErrors('logo');
        $this->actingAs($admin)->post('/admin/branding', ['color' => 'red'])->assertSessionHasErrors('color');
        $this->assertSame([], Branding::get()['assets']);
    }

    public function test_images_can_be_removed_and_the_defaults_return()
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->post('/admin/branding', ['name' => 'Acme Fax', 'logo' => UploadedFile::fake()->image('logo.png', 200, 50)]);

        $this->actingAs($admin)->post('/admin/branding', ['name' => '', 'remove' => ['logo']])->assertSessionHasNoErrors();

        Storage::disk('local')->assertMissing('branding/logo.png');
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($page) => $page->where('branding.name', 'Fax2Email')->where('branding.logo', null));
    }
}
