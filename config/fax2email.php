<?php

return [

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH
    |--------------------------------------------------------------------------
    |
    | The installer generates these secrets and writes them both here (.env)
    | and to /etc/freeswitch/vars.xml.
    |
    | event_socket: FreeSWITCH's control port, used to reload trunks and read
    |   registration status. Only reachable from this server.
    | xml_*: credentials FreeSWITCH uses when it fetches its SIP and dialplan
    |   configuration from this app (mod_xml_curl).
    |
    */

    'freeswitch' => [
        'event_socket' => [
            'host' => env('FREESWITCH_ESL_HOST', '127.0.0.1'),
            'port' => (int) env('FREESWITCH_ESL_PORT', 8021),
            'password' => env('FREESWITCH_ESL_PASSWORD'),
            'timeout' => 3,
        ],
        'xml_user' => env('FREESWITCH_XML_USER', 'freeswitch'),
        'xml_password' => env('FREESWITCH_XML_PASSWORD'),
        'sip_port' => 5060,
        'tls_port' => 5061,
        'fax_dir' => storage_path('app/faxes/incoming'),
        'tls_cert_dir' => '/etc/freeswitch/tls',
        // The public IP to advertise in SIP, if the server is behind NAT. Blank = the server's own IP.
        'external_ip' => env('FREESWITCH_EXTERNAL_IP'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Email to fax
    |--------------------------------------------------------------------------
    |
    | domain: faxes are sent by emailing PDFs to <number>@<domain>. Blank turns
    |   email-to-fax off. The installer sets up Postfix for this domain.
    | authserv_id: a secret name OpenDKIM puts in its Authentication-Results
    |   header, so a forged header from a sender can never be mistaken for ours.
    | spool: where Postfix drops incoming messages (a Maildir).
    |
    */

    'mail' => [
        'domain' => strtolower((string) env('FAX_EMAIL_DOMAIN', '')),
        'authserv_id' => env('FAX_EMAIL_AUTHSERV_ID'),
        'spool' => env('FAX_EMAIL_SPOOL', '/var/spool/fax2email/Maildir'),
    ],

];
