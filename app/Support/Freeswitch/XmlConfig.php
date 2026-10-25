<?php

namespace App\Support\Freeswitch;

use App\Models\FaxNumber;
use App\Models\SipTrunk;
use DOMDocument;
use DOMElement;

/**
 * Builds the configuration FreeSWITCH fetches from the app through mod_xml_curl:
 * the SIP profiles and trunk gateways (sofia.conf), the list of trunk addresses
 * allowed to send calls (acl.conf), and the dialplan for incoming faxes.
 */
class XmlConfig
{
    public const TRUNK_ACL = 'fax2email_trunks';

    private DOMDocument $doc;

    public function __construct()
    {
        $this->doc = new DOMDocument('1.0', 'UTF-8');
        $this->doc->formatOutput = true;
    }

    public static function notFound(): string
    {
        $config = new self;
        $section = $config->section('result');
        $config->el($section, 'result', ['status' => 'not found']);

        return $config->doc->saveXML();
    }

    public function sofia(): string
    {
        $configuration = $this->el($this->section('configuration'), 'configuration', ['name' => 'sofia.conf', 'description' => 'fax2email SIP profiles']);
        $global = $this->el($configuration, 'global_settings');
        $this->params($global, ['log-level' => '0', 'auto-restart' => 'false']);

        $profiles = $this->el($configuration, 'profiles');
        $trunks = SipTrunk::where('enabled', true)->orderBy('id')->get();
        $port = config('fax2email.freeswitch.sip_port');
        $tlsPort = config('fax2email.freeswitch.tls_port');
        // Set when the server is behind NAT, so providers are told the public address.
        $externalIp = config('fax2email.freeswitch.external_ip');

        foreach (['fax2email' => false, 'fax2email-tls' => true] as $name => $tls) {
            $profile = $this->el($profiles, 'profile', ['name' => $name]);
            $gateways = $this->el($profile, 'gateways');
            foreach ($trunks->filter(fn (SipTrunk $trunk) => $trunk->profileName() === $name) as $trunk) {
                $this->gateway($gateways, $trunk);
            }
            $this->el($profile, 'domains');

            $settings = $this->el($profile, 'settings');
            $this->params($settings, [
                'context' => 'public',
                'dialplan' => 'XML',
                // sip-ip/rtp-ip are left out so FreeSWITCH uses the server's own address.
                ...($externalIp ? ['ext-sip-ip' => $externalIp, 'ext-rtp-ip' => $externalIp] : []),
                'sip-port' => (string) ($tls ? $tlsPort : $port),
                // Only provider trunks may send calls; everyone else gets 403.
                'apply-inbound-acl' => self::TRUNK_ACL,
                'auth-calls' => 'false',
                'accept-blind-reg' => 'false',
                'accept-blind-auth' => 'false',
                'inbound-codec-prefs' => 'PCMU,PCMA',
                'outbound-codec-prefs' => 'PCMU,PCMA',
                'inbound-late-negotiation' => 'true',
                'rtp-timer-name' => 'soft',
                'manage-presence' => 'false',
                'disable-register' => 'true',
                'user-agent-string' => 'fax2email',
                'nonce-ttl' => '60',
                'rtp-timeout-sec' => '300',
                'rtp-hold-timeout-sec' => '1800',
                ...($tls ? [
                    'tls' => 'true',
                    'tls-only' => 'true',
                    'tls-sip-port' => (string) $tlsPort,
                    'tls-bind-params' => 'transport=tls',
                    'tls-cert-dir' => config('fax2email.freeswitch.tls_cert_dir'),
                    'tls-version' => 'tlsv1.2,tlsv1.3',
                ] : []),
            ]);
        }

        return $this->doc->saveXML();
    }

    public function acl(): string
    {
        $configuration = $this->el($this->section('configuration'), 'configuration', ['name' => 'acl.conf', 'description' => 'fax2email trunk addresses']);
        $lists = $this->el($configuration, 'network-lists');
        $list = $this->el($lists, 'list', ['name' => self::TRUNK_ACL, 'default' => 'deny']);

        $cidrs = SipTrunk::where('enabled', true)->get()->flatMap(fn (SipTrunk $trunk) => $trunk->resolved_addresses ?? [])->unique()->sort();
        foreach ($cidrs as $cidr) {
            $this->el($list, 'node', ['type' => 'allow', 'cidr' => str_contains($cidr, '/') ? $cidr : $cidr.(str_contains($cidr, ':') ? '/128' : '/32')]);
        }

        return $this->doc->saveXML();
    }

    /**
     * The dialplan for one incoming call. FreeSWITCH asks for it per call, so
     * the dialled number decides what happens: a known fax number is answered
     * as a fax machine (T.38 when the provider offers it, G.711 otherwise) and
     * saved as a TIFF; any other number is refused as unallocated.
     */
    public function dialplan(?FaxNumber $number): string
    {
        $section = $this->section('dialplan', 'fax2email dialplan');
        $context = $this->el($section, 'context', ['name' => 'public']);
        $extension = $this->el($context, 'extension', ['name' => $number ? 'receive_fax' : 'unknown_number']);
        $condition = $this->el($extension, 'condition', ['field' => 'destination_number', 'expression' => '^(.*)$']);

        if ($number === null) {
            $this->el($condition, 'action', ['application' => 'hangup', 'data' => 'UNALLOCATED_NUMBER']);

            return $this->doc->saveXML();
        }

        // The number id is part of the file name, so a fax can still be matched
        // to its account if the "fax received" event is ever missed.
        $faxDir = config('fax2email.freeswitch.fax_dir');
        foreach ([
            ['set', "fax2email_number_id={$number->id}"],
            ['set', 'verbose_events=true'],
            ['set', 'fax_enable_t38=true'],
            ['set', 'fax_enable_t38_request=true'],
            ['set', 'fax_use_ecm=true'],
            ['set', 'fax_ident=fax2email'],
            ['set', "fax_file={$faxDir}/\${strftime(%Y-%m-%d)}-{$number->id}-\${uuid}.tif"],
            ['answer', ''],
            ['playback', 'silence_stream://2000'],
            ['rxfax', '${fax_file}'],
            ['hangup', ''],
        ] as [$app, $data]) {
            $this->el($condition, 'action', array_filter(['application' => $app, 'data' => $data], fn ($v) => $v !== ''));
        }

        return $this->doc->saveXML();
    }

    private function gateway(DOMElement $parent, SipTrunk $trunk): void
    {
        $gateway = $this->el($parent, 'gateway', ['name' => $trunk->gatewayName()]);
        $proxy = "{$trunk->host}:{$trunk->port}";

        $this->params($gateway, [
            'proxy' => $proxy,
            'realm' => $trunk->host,
            'register-proxy' => $proxy,
            'register-transport' => $trunk->transport,
            'context' => 'public',
            'caller-id-in-from' => 'true',
            ...($trunk->registers() ? [
                'register' => 'true',
                'username' => (string) $trunk->username,
                'password' => (string) $trunk->password,
                'expire-seconds' => '600',
                'retry-seconds' => '30',
            ] : [
                // IP-auth trunks don't register; OPTIONS pings show whether the provider is reachable.
                'register' => 'false',
                'username' => 'fax2email',
                'password' => 'unused',
                'ping' => '60',
            ]),
        ]);
    }

    /**
     * @param  array<string, string>  $params
     */
    private function params(DOMElement $parent, array $params): void
    {
        foreach ($params as $name => $value) {
            $this->el($parent, 'param', ['name' => $name, 'value' => $value]);
        }
    }

    private function section(string $name, string $description = 'fax2email'): DOMElement
    {
        $document = $this->doc->appendChild($this->doc->createElement('document'));
        $document->setAttribute('type', 'freeswitch/xml');

        return $this->el($document, 'section', ['name' => $name, 'description' => $description]);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function el(DOMElement $parent, string $name, array $attributes = []): DOMElement
    {
        $element = $parent->appendChild($this->doc->createElement($name));
        foreach ($attributes as $key => $value) {
            // setAttribute escapes values, so trunk names and passwords cannot break the XML.
            $element->setAttribute($key, $value);
        }

        return $element;
    }
}
