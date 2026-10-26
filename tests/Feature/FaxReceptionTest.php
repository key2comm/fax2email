<?php

namespace Tests\Feature;

use App\Jobs\ProcessReceivedFax;
use App\Mail\FaxReceived;
use App\Models\Account;
use App\Models\Fax;
use App\Models\FaxNumber;
use App\Models\User;
use App\Support\Fax\FaxInbox;
use App\Support\Fax\TiffToPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaxReceptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->useStoragePath(sys_get_temp_dir().'/fax2email-test-storage-'.getmypid());
        config([
            'filesystems.disks.local.root' => storage_path('app/private'),
            'fax2email.freeswitch.fax_dir' => storage_path('app/faxes/incoming'),
            'fax2email.freeswitch.xml_user' => 'freeswitch',
            'fax2email.freeswitch.xml_password' => 'xml-secret',
        ]);
        Storage::forgetDisk('local');
        File::ensureDirectoryExists(config('fax2email.freeswitch.fax_dir'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path());

        parent::tearDown();
    }

    private function number(array $attributes = []): FaxNumber
    {
        return FaxNumber::create(['account_id' => Account::factory()->create()->id, 'number' => '15551234567', ...$attributes]);
    }

    /**
     * Put a real spandsp fax where FreeSWITCH would have saved it.
     */
    private function dropFax(FaxNumber $number, ?string $uuid = null): array
    {
        $uuid ??= (string) Str::uuid();
        $file = config('fax2email.freeswitch.fax_dir').'/2026-10-25-'.$number->id."-{$uuid}.tif";
        copy(base_path('tests/fixtures/received-spandsp.tif'), $file);

        return [$uuid, $file];
    }

    public function test_tiff_faxes_convert_to_pdf_at_their_real_size()
    {
        // Without Ghostscript the CCITT PDF is kept as built.
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $pdf = sys_get_temp_dir().'/fax2email-test-'.getmypid().'.pdf';
        $convert = function (string $fixture) use ($pdf) {
            $this->assertSame(1, (new TiffToPdf)->convert(base_path("tests/fixtures/{$fixture}.tif"), $pdf));

            return file_get_contents($pdf);
        };

        // A real fax from spandsp: 2-D G3, byte-aligned lines, least significant bit first.
        $spandsp = $convert('received-spandsp');
        $this->assertStringStartsWith('%PDF-1.4', $spandsp);
        $this->assertStringContainsString('/Filter /CCITTFaxDecode /DecodeParms << /K 1 /EndOfLine true /Columns 1728', $spandsp);
        // Fill bits are not EncodedByteAlign; declaring it makes browsers' PDF viewers show a blank page.
        $this->assertStringNotContainsString('/EncodedByteAlign', $spandsp);
        // 1728 x 1100 pixels at 204 x 196 dpi = 609.88 x 404.08 points.
        $this->assertStringContainsString('/MediaBox [0 0 609.8824 404.0816]', $spandsp);

        // A 1-D G3 TIFF.
        $this->assertStringContainsString('/DecodeParms << /K 0 ', $convert('sent-g3'));

        unlink($pdf);
    }

    public function test_ghostscript_reencodes_fax_images_as_flate()
    {
        if (! Process::run(['gs', '--version'])->successful()) {
            $this->markTestSkipped('Ghostscript is not installed.');
        }
        $pdf = sys_get_temp_dir().'/fax2email-test-flate-'.getmypid().'.pdf';

        $this->assertSame(1, (new TiffToPdf)->convert(base_path('tests/fixtures/received-spandsp.tif'), $pdf));
        $data = file_get_contents($pdf);
        unlink($pdf);

        // Edge's PDF viewer shows CCITT images as blank pages; Flate bitmaps show everywhere.
        $this->assertStringNotContainsString('CCITTFaxDecode', $data);
        $this->assertStringContainsString('/FlateDecode', $data);
        $this->assertMatchesRegularExpression('#/Width 1728\s*/Height 1100#', $data);
    }

    public function test_numbers_are_matched_with_or_without_the_leading_1()
    {
        $number = $this->number();

        foreach (['15551234567', '+15551234567', '5551234567', 'sip:+1-555-123-4567@provider'] as $dialled) {
            $this->assertTrue($number->is(FaxNumber::findForCall($dialled)), $dialled);
        }
        $this->assertNull(FaxNumber::findForCall('15559999999'));

        $number->update(['enabled' => false]);
        $this->assertNull(FaxNumber::findForCall('15551234567'));
    }

    public function test_freeswitch_receives_known_numbers_and_refuses_others()
    {
        $number = $this->number();
        $post = fn (string $dialled) => $this->withBasicAuth('freeswitch', 'xml-secret')
            ->post('/freeswitch/xml', ['section' => 'dialplan', 'Caller-Context' => 'public', 'Hunt-Destination-Number' => $dialled])
            ->getContent();

        $known = $post('5551234567');
        $this->assertStringContainsString('application="rxfax"', $known);
        $this->assertStringContainsString("fax2email_number_id={$number->id}", $known);
        $this->assertStringContainsString("-{$number->id}-\${uuid}.tif", $known);

        $unknown = $post('15550000000');
        $this->assertStringContainsString('data="UNALLOCATED_NUMBER"', $unknown);
        $this->assertStringNotContainsString('rxfax', $unknown);
    }

    public function test_a_received_fax_is_filed_converted_and_emailed()
    {
        Mail::fake();
        $number = $this->number(['recipients' => ['office@example.com', 'boss@example.com']]);
        [$uuid, $file] = $this->dropFax($number);

        Queue::fake();
        $fax = app(FaxInbox::class)->recordEvent([
            'Unique-ID' => $uuid,
            'fax-success' => '1',
            'fax-document-transferred-pages' => '1',
            'fax-remote-station-id' => 'ACME FAX',
            'fax-result-text' => 'OK',
            'Caller-Caller-ID-Number' => '+14165550000',
            'variable_fax2email_number_id' => (string) $number->id,
            'variable_fax_file' => $file,
        ]);
        Queue::assertPushed(ProcessReceivedFax::class);

        // The same event again (e.g. after a reconnect) does not create a duplicate.
        app(FaxInbox::class)->recordEvent(['Unique-ID' => $uuid, 'fax-success' => '1']);
        $this->assertSame(1, Fax::count());

        app()->call([new ProcessReceivedFax($fax->id), 'handle']);
        $fax->refresh();

        $this->assertSame('delivered', $fax->status);
        $this->assertSame($number->account_id, $fax->account_id);
        $this->assertSame("faxes/{$number->account_id}/".now()->format('Y/m')."/{$fax->id}.pdf", $fax->pdf_path);
        Storage::disk('local')->assertExists([$fax->tiff_path, $fax->pdf_path]);
        $this->assertFileDoesNotExist($file);
        $this->assertNotNull($fax->emailed_at);

        Mail::assertSent(FaxReceived::class, function (FaxReceived $mail) {
            return $mail->hasTo('office@example.com') && $mail->hasTo('boss@example.com')
                && $mail->envelope()->subject === 'Fax from +14165550000 (1 page)'
                && count($mail->attachments()) === 1;
        });
    }

    public function test_faxes_go_to_the_account_owners_when_no_recipients_are_set()
    {
        $number = $this->number();
        User::factory()->owner($number->account)->create(['email' => 'owner1@example.com']);
        User::factory()->owner($number->account)->create(['email' => 'owner2@example.com']);
        User::factory()->member($number->account)->create(['email' => 'member@example.com']);

        $this->assertEqualsCanonicalizing(['owner1@example.com', 'owner2@example.com'], $number->deliveryAddresses());
    }

    public function test_failed_calls_are_recorded_without_keeping_an_empty_file()
    {
        Queue::fake();
        $number = $this->number();
        [$uuid, $file] = $this->dropFax($number);

        $fax = app(FaxInbox::class)->recordEvent([
            'Unique-ID' => $uuid, 'fax-success' => '0', 'fax-document-transferred-pages' => '0',
            'fax-result-text' => 'Far end cannot receive at the resolution of the image',
            'variable_fax2email_number_id' => (string) $number->id, 'variable_fax_file' => $file,
        ]);

        $this->assertSame('failed', $fax->status);
        $this->assertFileDoesNotExist($file);
        Queue::assertNothingPushed();
    }

    public function test_the_sweep_recovers_faxes_whose_event_was_missed()
    {
        Queue::fake();
        $number = $this->number();
        [$uuid, $file] = $this->dropFax($number);

        $this->assertSame(0, app(FaxInbox::class)->sweep(), 'Files still being written are left alone');

        touch($file, time() - 600);
        $this->assertSame(1, app(FaxInbox::class)->sweep());
        $this->assertSame(0, app(FaxInbox::class)->sweep(), 'Each file is only recovered once');

        $fax = Fax::sole();
        $this->assertSame($uuid, $fax->call_uuid);
        $this->assertSame($number->account_id, $fax->account_id);
        Queue::assertPushed(ProcessReceivedFax::class);
    }

    public function test_users_only_see_and_download_their_own_accounts_faxes()
    {
        $number = $this->number();
        $fax = Fax::create(['account_id' => $number->account_id, 'fax_number_id' => $number->id, 'status' => 'delivered', 'pdf_path' => 'faxes/x.pdf']);
        Storage::disk('local')->put('faxes/x.pdf', '%PDF-1.4 test');

        $member = User::factory()->member($number->account)->create();
        $stranger = User::factory()->member()->create();

        $this->actingAs($member)->get('/faxes')->assertInertia(fn ($page) => $page->has('faxes.data', 1));
        $this->actingAs($stranger)->get('/faxes')->assertInertia(fn ($page) => $page->has('faxes.data', 0));
        $this->actingAs($member)->get("/faxes/{$fax->id}/pdf")->assertOk()->assertDownload();
        $this->actingAs($stranger)->get("/faxes/{$fax->id}/pdf")->assertForbidden();

        // Account Users can read faxes but not resend them; owners can.
        $this->actingAs($member)->post("/faxes/{$fax->id}/resend")->assertForbidden();
    }

    public function test_staff_manage_numbers_and_owners_can_only_change_label_and_recipients()
    {
        $account = Account::factory()->create();
        $provisioner = User::factory()->provisioner()->create();

        $this->actingAs($provisioner)->post('/numbers', ['number' => '(555) 123-4567', 'account_id' => $account->id, 'enabled' => true, 'recipients' => "a@example.com\nb@example.com"])
            ->assertSessionHasNoErrors();
        $number = FaxNumber::sole();
        $this->assertSame('15551234567', $number->number);
        $this->assertSame(['a@example.com', 'b@example.com'], $number->recipients);

        $this->actingAs($provisioner)->post('/numbers', ['number' => '+1 555 123 4567', 'account_id' => $account->id, 'enabled' => true])
            ->assertSessionHasErrors('number');

        $owner = User::factory()->owner($account)->create();
        $this->actingAs($owner)->put("/numbers/{$number->id}", ['label' => 'Front desk', 'recipients' => 'front@example.com', 'number' => '19999999999', 'account_id' => 999])
            ->assertSessionHasNoErrors();
        $number->refresh();
        $this->assertSame('Front desk', $number->label);
        $this->assertSame(['front@example.com'], $number->recipients);
        $this->assertSame('15551234567', $number->number, 'Owners cannot change the number itself');
        $this->assertSame($account->id, $number->account_id);

        $this->actingAs($owner)->put("/numbers/{$number->id}", ['recipients' => 'not-an-email'])->assertSessionHasErrors('recipients');
        $this->actingAs($owner)->delete("/numbers/{$number->id}")->assertForbidden();
        $this->actingAs($owner)->post('/numbers', ['number' => '15550001111', 'account_id' => $account->id, 'enabled' => true])->assertForbidden();
        $this->actingAs(User::factory()->owner()->create())->put("/numbers/{$number->id}", ['label' => 'x'])->assertForbidden();
        $this->actingAs(User::factory()->member($account)->create())->get('/numbers')->assertForbidden();
    }

    public function test_old_faxes_and_their_files_are_pruned()
    {
        $number = $this->number();
        $old = Fax::create(['account_id' => $number->account_id, 'status' => 'delivered', 'pdf_path' => 'faxes/old.pdf', 'tiff_path' => 'faxes/old.tif']);
        $old->forceFill(['created_at' => now()->subDays(Fax::KEEP_DAYS + 1)])->save();
        Storage::disk('local')->put('faxes/old.pdf', 'x');
        Storage::disk('local')->put('faxes/old.tif', 'x');
        $recent = Fax::create(['account_id' => $number->account_id, 'status' => 'delivered']);

        $this->artisan('model:prune', ['--model' => Fax::class]);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        Storage::disk('local')->assertMissing(['faxes/old.pdf', 'faxes/old.tif']);
    }
}
