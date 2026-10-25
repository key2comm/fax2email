<?php

namespace Tests\Feature;

use App\Jobs\SendFax;
use App\Mail\FaxEmailRejected;
use App\Models\Account;
use App\Models\Fax;
use App\Models\FaxEmail;
use App\Models\FaxNumber;
use App\Models\User;
use App\Support\Fax\EmailToFax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use IPLib\Address\AddressInterface;
use SPFLib\DNS\Resolver;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class EmailToFaxTest extends TestCase
{
    use RefreshDatabase;

    private const AUTHSERV_ID = 'fax2email-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
        config([
            'filesystems.disks.local.root' => storage_path('app/private'),
            'fax2email.mail.domain' => 'fax.example.com',
            'fax2email.mail.authserv_id' => self::AUTHSERV_ID,
            'fax2email.mail.spool' => storage_path('spool/Maildir'),
        ]);
        Storage::forgetDisk('local');
        Mail::fake();
        Queue::fake();

        // acme.test allows mail only from 198.51.100.25.
        $this->app->instance(EmailToFax::class, new EmailToFax(new class implements Resolver
        {
            public function getTXTRecords(string $domain): array
            {
                return $domain === 'acme.test' ? ['v=spf1 ip4:198.51.100.25 -all'] : [];
            }

            public function getIPAddressesFromDomainName(string $domain): array
            {
                return [];
            }

            public function getMXRecords(string $domain): array
            {
                return [];
            }

            public function getPTRRecords(string $domain): array
            {
                return [];
            }

            public function getDomainNameFromIPAddress(AddressInterface $ip): string
            {
                return '';
            }
        }));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    private function jane(): User
    {
        $account = Account::factory()->create();
        FaxNumber::create(['account_id' => $account->id, 'number' => '15551234567']);

        return User::factory()->member($account)->create(['email' => 'Jane@Acme.test']);
    }

    /**
     * A raw message as Postfix (and OpenDKIM) would deliver it.
     *
     * @param  list<string>  $pdfs  File names to attach as PDFs.
     */
    private function message(string $from = 'jane@acme.test', string $to = '14165550100@fax.example.com', string $clientIp = '198.51.100.25', array $pdfs = ['a.pdf', 'b.pdf'], ?string $authResults = null): string
    {
        $email = (new Email)->from($from)->to($to)->subject('Referral')->text('Please see attached.');
        foreach ($pdfs as $name) {
            $email->attach("%PDF-1.4\n% {$name}\n%%EOF\n", $name, 'application/pdf');
        }

        $top = "Return-Path: <{$from}>\r\nDelivered-To: {$to}\r\n";
        if ($authResults) {
            $top .= "Authentication-Results: {$authResults}\r\n";
        }
        $top .= "Received: from mail.acme.test (mail.acme.test [{$clientIp}])\r\n\tby fax.example.com (Postfix) with ESMTPS id ABC123\r\n\tfor <{$to}>; Sun, 25 Oct 2026 21:00:00 +0000\r\n";

        return $top.$email->toString();
    }

    public function test_a_verified_portal_user_can_fax_pdfs_by_email()
    {
        $jane = $this->jane();

        $log = app(EmailToFax::class)->handle($this->message());

        $this->assertSame('accepted', $log->status, (string) $log->reason);
        $this->assertSame('pass', $log->spf);
        $fax = Fax::sole();
        $this->assertSame(['outbound', 'email', 'queued', '14165550100', $jane->id, 'jane@acme.test'],
            [$fax->direction, $fax->source, $fax->status, $fax->destination_number, $fax->user_id, $fax->sender_email]);
        $this->assertCount(2, Storage::disk('local')->files("faxes/uploads/{$fax->id}"));
        Queue::assertPushed(SendFax::class);
        Mail::assertNothingSent();
    }

    public function test_a_forged_sender_is_dropped_without_a_reply()
    {
        $this->jane();

        // Claims to be Jane, but comes from an address acme.test's SPF does not allow.
        $log = app(EmailToFax::class)->handle($this->message(clientIp: '203.0.113.66'));

        $this->assertSame('rejected', $log->status);
        $this->assertSame('fail', $log->spf);
        $this->assertStringContainsString('not verified', $log->reason);
        $this->assertSame(0, Fax::count());
        Mail::assertNothingSent();
    }

    public function test_a_dkim_pass_reported_by_this_servers_opendkim_is_enough()
    {
        $this->jane();
        $ours = self::AUTHSERV_ID.'; dkim=pass (2048-bit key; unprotected) header.d=acme.test header.i=@acme.test header.b=AbCdEf';

        $log = app(EmailToFax::class)->handle($this->message(clientIp: '203.0.113.66', authResults: $ours));

        $this->assertSame('accepted', $log->status, (string) $log->reason);
        $this->assertSame('acme.test', $log->dkim_domain);
    }

    public function test_dkim_results_from_anyone_else_are_ignored()
    {
        $this->jane();
        $forged = 'mx.google.com; dkim=pass header.d=acme.test';

        $log = app(EmailToFax::class)->handle($this->message(clientIp: '203.0.113.66', authResults: $forged));
        $this->assertSame('rejected', $log->status);

        // A passing signature for some other domain does not prove the From address either.
        $otherDomain = self::AUTHSERV_ID.'; dkim=pass header.d=evil.test';
        $log = app(EmailToFax::class)->handle($this->message(clientIp: '203.0.113.66', authResults: $otherDomain));
        $this->assertSame('rejected', $log->status);

        $this->assertSame(0, Fax::count());
    }

    public function test_verified_senders_hear_why_their_fax_was_not_sent()
    {
        $this->jane();

        $noPdf = app(EmailToFax::class)->handle($this->message(pdfs: []));
        $this->assertSame('No PDF was attached.', $noPdf->reason);

        // Verified by SPF for acme.test, but not a portal user.
        $stranger = app(EmailToFax::class)->handle($this->message(from: 'bob@acme.test'));
        $this->assertSame('The sender is not a portal user with a fax number.', $stranger->reason);

        Mail::assertSent(FaxEmailRejected::class, 2);
        Mail::assertSent(FaxEmailRejected::class, fn ($mail) => $mail->hasTo('bob@acme.test'));
        $this->assertSame(0, Fax::count());
    }

    public function test_only_fax_numbers_at_the_fax_domain_are_accepted()
    {
        $this->jane();

        foreach (['14165550100@other.example.com', 'sales@fax.example.com', '123@fax.example.com'] as $to) {
            $log = app(EmailToFax::class)->handle($this->message(to: $to));
            $this->assertSame('rejected', $log->status, $to);
        }
        $this->assertSame(0, Fax::count());
        Mail::assertNothingSent();
    }

    public function test_the_spool_command_processes_and_removes_each_message()
    {
        $this->jane();
        $new = config('fax2email.mail.spool').'/new';
        File::ensureDirectoryExists($new);
        file_put_contents("{$new}/1.msg", $this->message());
        file_put_contents("{$new}/2.msg", $this->message(clientIp: '203.0.113.66'));

        $this->artisan('fax2email:email-in')->assertSuccessful();

        $this->assertSame(['accepted', 'rejected'], FaxEmail::orderBy('id')->pluck('status')->all());
        $this->assertSame([], glob("{$new}/*"));
    }

    public function test_admins_can_see_the_email_to_fax_log()
    {
        $this->jane();
        app(EmailToFax::class)->handle($this->message());

        $this->actingAs(User::factory()->admin()->create())->get('/admin/mail/email-to-fax')
            ->assertInertia(fn ($page) => $page->has('emails.data', 1)->where('domain', 'fax.example.com'));
        $this->actingAs(User::factory()->provisioner()->create())->get('/admin/mail/email-to-fax')->assertForbidden();
    }
}
