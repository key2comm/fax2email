<?php

namespace Tests\Feature;

use App\Jobs\SendFax;
use App\Mail\FaxSendResult;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Fax;
use App\Models\FaxNumber;
use App\Models\SipTrunk;
use App\Models\User;
use App\Support\Fax\FaxOutbox;
use App\Support\Fax\PdfToFax;
use App\Support\Freeswitch\EventSocket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FaxSendingTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> Commands sent to the fake FreeSWITCH. */
    public array $commands = [];

    /** @var list<string> Replies the fake FreeSWITCH gives, in order. */
    public array $replies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
        config(['filesystems.disks.local.root' => storage_path('app/private')]);
        Storage::forgetDisk('local');
        Mail::fake();

        // Stand-ins for Ghostscript (not needed to test the flow) and FreeSWITCH.
        $this->app->instance(PdfToFax::class, new class extends PdfToFax
        {
            public function convert(array $pdfPaths, string $tiffPath): void
            {
                copy(base_path('tests/fixtures/received-spandsp.tif'), $tiffPath);
            }
        });
        $test = $this;
        $this->app->instance(FaxOutbox::class, new FaxOutbox(fn () => new class($test) extends EventSocket
        {
            public function __construct(private FaxSendingTest $test)
            {
                parent::__construct('127.0.0.1', 0, 'x');
            }

            public function api(string $command): string
            {
                $this->test->commands[] = $command;

                return array_shift($this->test->replies) ?? '+OK 11111111-2222-3333-4444-555555555555';
            }

            public function close(): void {}
        }));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    /**
     * @return array{0: User, 1: FaxNumber}
     */
    private function sender(): array
    {
        // A zone without daylight saving, so the header offset doesn't depend on the date.
        $account = Account::factory()->create(['name' => 'Acme Dental', 'timezone' => 'Asia/Kolkata']);
        SipTrunk::forceCreate(['name' => 'Provider', 'enabled' => true, 'auth_type' => 'ip', 'transport' => 'udp', 'host' => '203.0.113.10', 'port' => 5060, 'dial_format' => 'e164_plus']);
        $number = FaxNumber::create(['account_id' => $account->id, 'number' => '15551234567']);

        return [User::factory()->member($account)->create(['email' => 'jane@acme.test']), $number];
    }

    private function pdf(string $name = 'letter.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%%EOF\n");
    }

    public function test_the_header_time_zone_is_the_accounts_current_offset_as_a_posix_string()
    {
        $this->assertSame('UTC0', FaxOutbox::headerTimezone('UTC'));
        $this->assertSame('UTC0', FaxOutbox::headerTimezone(null));
        $this->assertSame('JST-9', FaxOutbox::headerTimezone('Asia/Tokyo'));
        $this->assertSame('IST-5:30', FaxOutbox::headerTimezone('Asia/Kolkata'));
        // Zones whose abbreviation is a number get a placeholder name spandsp accepts.
        $this->assertSame('LOC3', FaxOutbox::headerTimezone('America/Sao_Paulo'));
        $this->assertContains(FaxOutbox::headerTimezone('America/Toronto'), ['EST5', 'EDT4']);
    }

    public function test_everyone_in_an_account_shares_the_address_book()
    {
        [$jane, $number] = $this->sender();
        $owner = User::factory()->owner($jane->account)->create();

        $this->actingAs($jane)->post('/contacts', ['name' => 'Dr. Smith', 'company' => 'Clinic', 'fax_number' => '(416) 555-0100'])->assertSessionHasNoErrors();
        $contact = Contact::sole();
        $this->assertSame('14165550100', $contact->fax_number);

        $this->actingAs($owner)->get('/contacts')->assertInertia(fn ($page) => $page->has('contacts', 1)->where('contacts.0.formatted', '+1 (416) 555-0100'));
        $this->actingAs($owner)->put("/contacts/{$contact->id}", ['name' => 'Dr. J. Smith', 'fax_number' => '4165550100'])->assertSessionHasNoErrors();
        $this->actingAs($jane)->post('/contacts', ['name' => 'Dup', 'fax_number' => '+1 416 555 0100'])->assertSessionHasErrors('fax_number');

        // Other accounts and staff can't see or touch it.
        $stranger = User::factory()->owner()->create();
        $this->actingAs($stranger)->get('/contacts')->assertInertia(fn ($page) => $page->has('contacts', 0));
        $this->actingAs($stranger)->delete("/contacts/{$contact->id}")->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/contacts')->assertForbidden();

        $this->actingAs($jane)->delete("/contacts/{$contact->id}")->assertRedirect();
        $this->assertModelMissing($contact);
    }

    public function test_sending_from_the_portal_queues_one_fax_from_all_pdfs()
    {
        Queue::fake();
        [$jane, $number] = $this->sender();
        Contact::create(['account_id' => $jane->account_id, 'name' => 'Dr. Smith', 'fax_number' => '14165550100']);

        $this->actingAs($jane)->post('/send', [
            'to' => '416-555-0100',
            'from_number_id' => $number->id,
            'files' => [$this->pdf('page1.pdf'), $this->pdf('page2.pdf')],
        ])->assertRedirect('/faxes?direction=outbound');

        $fax = Fax::sole();
        $this->assertSame(['outbound', 'portal', 'queued', '14165550100', 'Dr. Smith', 'jane@acme.test'],
            [$fax->direction, $fax->source, $fax->status, $fax->destination_number, $fax->destination_name, $fax->sender_email]);
        Storage::disk('local')->assertExists(["faxes/uploads/{$fax->id}/00.pdf", "faxes/uploads/{$fax->id}/01.pdf"]);
        Queue::assertPushed(SendFax::class, fn ($job) => $job->faxId === $fax->id);
    }

    public function test_only_pdfs_from_your_own_numbers_can_be_sent()
    {
        [$jane, $number] = $this->sender();
        $otherNumber = FaxNumber::create(['account_id' => Account::factory()->create()->id, 'number' => '15559990000']);

        $this->actingAs($jane)->post('/send', ['to' => '4165550100', 'from_number_id' => $otherNumber->id, 'files' => [$this->pdf()]])
            ->assertSessionHasErrors('from_number_id');
        $this->actingAs($jane)->post('/send', ['to' => '12', 'from_number_id' => $number->id, 'files' => [$this->pdf()]])
            ->assertSessionHasErrors('to');
        $this->actingAs($jane)->post('/send', ['to' => '4165550100', 'from_number_id' => $number->id, 'files' => [UploadedFile::fake()->create('virus.exe', 10)]])
            ->assertSessionHasErrors('files.0');

        // Staff have no account and no number to send from.
        $this->actingAs(User::factory()->admin()->create())->get('/send')->assertForbidden();
        $this->assertSame(0, Fax::count());
    }

    public function test_a_successful_send_dials_through_the_trunk_and_confirms_to_the_sender()
    {
        [$jane, $number] = $this->sender();
        Queue::fake();
        $fax = app(FaxOutbox::class)->queue($number, '14165550100', 'Dr. Smith', [], $jane, $jane->email, 'portal');
        Storage::disk('local')->put("faxes/uploads/{$fax->id}/00.pdf", '%PDF-1.4');

        app(FaxOutbox::class)->attempt($fax->fresh());
        $fax->refresh();

        $this->assertSame('sending', $fax->status);
        $this->assertSame(1, $fax->attempts);
        $this->assertSame(1, $fax->pages);
        $this->assertCount(1, $this->commands);
        $this->assertStringContainsString('sofia/gateway/trunk-1/+14165550100 &txfax(', $this->commands[0]);
        $this->assertStringContainsString("fax2email_fax_id={$fax->id}", $this->commands[0]);
        $this->assertStringContainsString('origination_caller_id_number=+15551234567', $this->commands[0]);
        $this->assertStringContainsString("fax_header='Acme Dental'", $this->commands[0], 'Values with spaces are quoted');
        $this->assertStringContainsString('fax_timezone=IST-5:30', $this->commands[0], "The header shows the account's local time");
        Storage::disk('local')->assertMissing("faxes/uploads/{$fax->id}/00.pdf");

        app(FaxOutbox::class)->recordHangup([
            'Event-Name' => 'CHANNEL_HANGUP_COMPLETE', 'Hangup-Cause' => 'NORMAL_CLEARING',
            'variable_fax2email_fax_id' => (string) $fax->id, 'variable_fax_success' => '1',
            'variable_fax_document_transferred_pages' => '1', 'variable_fax_remote_station_id' => 'SMITH CLINIC',
        ]);
        $fax->refresh();

        $this->assertSame('sent', $fax->status);
        $this->assertSame('SMITH CLINIC', $fax->remote_station_id);
        $this->assertNull($fax->tiff_path, 'The TIFF is only kept until the call is done');
        Storage::disk('local')->assertExists($fax->pdf_path);
        Mail::assertSent(FaxSendResult::class, fn (FaxSendResult $mail) => $mail->hasTo('jane@acme.test')
            && $mail->envelope()->subject === 'Fax sent to Dr. Smith (1 page)');
    }

    public function test_busy_lines_are_retried_and_then_reported_as_failed()
    {
        [$jane, $number] = $this->sender();
        Queue::fake();
        $fax = app(FaxOutbox::class)->queue($number, '14165550100', null, [], $jane, $jane->email, 'portal');
        Storage::disk('local')->put("faxes/uploads/{$fax->id}/00.pdf", '%PDF-1.4');
        $this->replies = ['-ERR USER_BUSY', '-ERR NO_ANSWER', '-ERR USER_BUSY'];

        for ($i = 1; $i <= Fax::MAX_ATTEMPTS; $i++) {
            app(FaxOutbox::class)->attempt($fax->fresh());
            $fax->refresh();
            if ($i < Fax::MAX_ATTEMPTS) {
                $this->assertSame('queued', $fax->status);
                $this->assertNotNull($fax->next_attempt_at);
            }
        }

        $this->assertSame('failed', $fax->status);
        $this->assertSame(3, $fax->attempts);
        $this->assertSame('The line was busy.', $fax->result_text);
        Mail::assertSent(FaxSendResult::class, fn (FaxSendResult $mail) => $mail->envelope()->subject === 'Fax to +1 (416) 555-0100 failed');
    }

    public function test_numbers_that_do_not_exist_fail_without_retrying()
    {
        [$jane, $number] = $this->sender();
        Queue::fake();
        $fax = app(FaxOutbox::class)->queue($number, '14165550100', null, [], $jane, $jane->email, 'portal');
        Storage::disk('local')->put("faxes/uploads/{$fax->id}/00.pdf", '%PDF-1.4');
        $this->replies = ['-ERR UNALLOCATED_NUMBER'];

        app(FaxOutbox::class)->attempt($fax->fresh());

        $this->assertSame('failed', $fax->fresh()->status);
        $this->assertSame('The number is not in service.', $fax->fresh()->result_text);
        $this->assertCount(1, $this->commands);
    }

    public function test_sent_faxes_appear_on_the_sent_tab()
    {
        [$jane, $number] = $this->sender();
        Fax::create(['account_id' => $jane->account_id, 'fax_number_id' => $number->id, 'direction' => 'outbound', 'status' => 'sent', 'destination_number' => '14165550100']);
        Fax::create(['account_id' => $jane->account_id, 'fax_number_id' => $number->id, 'direction' => 'inbound', 'status' => 'delivered']);

        $this->actingAs($jane)->get('/faxes?direction=outbound')->assertInertia(fn ($page) => $page->has('faxes.data', 1)->where('faxes.data.0.to', '+1 (416) 555-0100'));
        $this->actingAs($jane)->get('/faxes')->assertInertia(fn ($page) => $page->has('faxes.data', 1)->where('direction', 'inbound'));
    }
}
