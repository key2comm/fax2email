<?php

namespace App\Http\Controllers;

use App\Support\Freeswitch\XmlConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Answers FreeSWITCH's mod_xml_curl requests for its SIP and dialplan
 * configuration. Only FreeSWITCH on this server may call it: requests must
 * come from the loopback address and carry the shared credentials.
 */
class FreeswitchXmlController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $password = config('fax2email.freeswitch.xml_password');
        $local = in_array($request->ip(), ['127.0.0.1', '::1'], true);

        abort_unless(
            $local && $password
                && hash_equals((string) config('fax2email.freeswitch.xml_user'), (string) $request->getUser())
                && hash_equals($password, (string) $request->getPassword()),
            403,
        );

        $section = $request->input('section');
        $xml = match (true) {
            $section === 'configuration' && $request->input('key_value') === 'sofia.conf' => (new XmlConfig)->sofia(),
            $section === 'configuration' && $request->input('key_value') === 'acl.conf' => (new XmlConfig)->acl(),
            // Calls from trunks arrive in the "public" context.
            $section === 'dialplan' && $request->input('Caller-Context', 'public') === 'public' => (new XmlConfig)->dialplan(),
            default => XmlConfig::notFound(),
        };

        return response($xml, 200, ['Content-Type' => 'text/xml']);
    }
}
