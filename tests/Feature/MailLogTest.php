<?php

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_sent_emails_are_logged()
    {
        config(['mail.default' => 'array']);

        Mail::raw('Hello', fn ($message) => $message->to('someone@example.com')->cc('copy@example.com')->subject('Greetings'));

        $log = MailLog::sole();
        $this->assertSame('sent', $log->status);
        $this->assertSame('someone@example.com', $log->recipients);
        $this->assertSame('copy@example.com', $log->cc);
        $this->assertSame('Greetings', $log->subject);
        $this->assertNotNull($log->message_id);
    }

    public function test_failed_emails_are_logged_with_the_error()
    {
        $this->actingAs(User::factory()->admin()->create())->post('/admin/mail/test', [
            'host' => '127.0.0.1',
            'port' => 1,
            'encryption' => 'none',
            'from_address' => 'fax@example.com',
            'from_name' => 'Fax',
        ])->assertSessionHasErrors('test');

        $log = MailLog::sole();
        $this->assertSame('failed', $log->status);
        $this->assertNotEmpty($log->error);
    }

    public function test_only_admins_can_view_the_mail_log()
    {
        $this->actingAs(User::factory()->provisioner()->create())->get('/admin/mail/log')->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->get('/admin/mail/log')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get('/admin/mail/log')->assertOk();
    }

    public function test_mail_log_can_be_filtered()
    {
        MailLog::create(['recipients' => 'alice@example.com', 'subject' => 'Fax received', 'status' => 'sent']);
        MailLog::create(['recipients' => 'bob@example.com', 'subject' => 'Invitation', 'status' => 'failed']);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/mail/log?search=ALICE')
            ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.recipients', 'alice@example.com'));

        $this->actingAs($admin)->get('/admin/mail/log?status=failed')
            ->assertInertia(fn ($page) => $page->has('logs.data', 1)->where('logs.data.0.recipients', 'bob@example.com'));
    }

    public function test_old_entries_are_pruned()
    {
        $old = MailLog::create(['recipients' => 'old@example.com', 'status' => 'sent']);
        $old->forceFill(['created_at' => now()->subDays(MailLog::KEEP_DAYS + 1)])->save();
        $recent = MailLog::create(['recipients' => 'new@example.com', 'status' => 'sent']);

        $this->artisan('model:prune', ['--model' => MailLog::class]);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }
}
