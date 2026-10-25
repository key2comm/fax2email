<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MailSettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $settings = [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'starttls',
        'username' => 'fax@example.com',
        'password' => 'secret-password',
        'from_address' => 'fax@example.com',
        'from_name' => 'Fax Service',
    ];

    public function test_only_admins_can_manage_mail_settings()
    {
        foreach ([User::factory()->provisioner()->create(), User::factory()->owner()->create()] as $user) {
            $this->actingAs($user)->get('/admin/mail')->assertForbidden();
            $this->actingAs($user)->put('/admin/mail', $this->settings)->assertForbidden();
        }

        $this->actingAs(User::factory()->admin()->create())->get('/admin/mail')->assertOk();
    }

    public function test_settings_are_saved_encrypted()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->put('/admin/mail', $this->settings)
            ->assertSessionHasNoErrors();

        $this->assertSame('secret-password', MailSettings::stored()['password']);
        $this->assertStringNotContainsString('secret-password', DB::table('settings')->value('value'));
    }

    public function test_blank_password_keeps_the_saved_one()
    {
        Setting::write(MailSettings::KEY, $this->settings);

        $this->actingAs(User::factory()->admin()->create())
            ->put('/admin/mail', [...$this->settings, 'host' => 'mail.other.com', 'password' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('mail.other.com', MailSettings::stored()['host']);
        $this->assertSame('secret-password', MailSettings::stored()['password']);
    }

    public function test_saved_settings_are_used_when_sending_mail()
    {
        Setting::write(MailSettings::KEY, [...$this->settings, 'encryption' => 'ssl', 'port' => 465]);

        $this->app->forgetInstance('mail.manager');
        app('mail.manager');

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('smtp.example.com', config('mail.mailers.smtp.host'));
        $this->assertSame('fax@example.com', config('mail.from.address'));
    }

    public function test_failed_test_email_reports_the_error()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post('/admin/mail/test', [...$this->settings, 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none'])
            ->assertSessionHasErrors('test');
    }
}
