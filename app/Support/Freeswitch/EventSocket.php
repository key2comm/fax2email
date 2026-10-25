<?php

namespace App\Support\Freeswitch;

use RuntimeException;

/**
 * Minimal client for FreeSWITCH's event socket (mod_event_socket), enough to
 * run "api" commands. Used from queue jobs and console commands, not from web
 * requests, so a slow or stopped FreeSWITCH never holds up the portal.
 */
class EventSocket
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private ?string $password,
        private int $timeout = 3,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('fax2email.freeswitch.event_socket');

        return new self($config['host'], $config['port'], $config['password'], $config['timeout']);
    }

    /**
     * Run an API command and return its output.
     */
    public function api(string $command): string
    {
        $this->connect();
        fwrite($this->socket, 'api '.str_replace(["\r", "\n"], ' ', $command)."\n\n");

        return $this->readReply()['body'];
    }

    /**
     * Subscribe to events (e.g. "CUSTOM spandsp::rxfaxresult") and call
     * $onEvent with each event's headers. Returns when the connection drops.
     *
     * @param  callable(array<string, string>): void  $onEvent
     */
    public function listen(string $events, callable $onEvent): void
    {
        $this->connect();
        fwrite($this->socket, "event plain {$events}\n\n");
        $this->readReply();
        stream_set_timeout($this->socket, 60);

        while (! feof($this->socket)) {
            try {
                $reply = $this->readReply();
            } catch (RuntimeException) {
                continue; // Nothing happened for a minute; keep waiting.
            }

            if (($reply['headers']['Content-Type'] ?? '') === 'text/disconnect-notice') {
                break;
            }
            if (($reply['headers']['Content-Type'] ?? '') !== 'text/event-plain') {
                continue;
            }

            $event = [];
            foreach (explode("\n", $reply['body']) as $line) {
                if (str_contains($line, ': ')) {
                    [$name, $value] = explode(': ', $line, 2);
                    $event[$name] = urldecode(trim($value));
                }
            }
            $onEvent($event);
        }

        $this->close();
    }

    public function close(): void
    {
        if ($this->socket) {
            @fwrite($this->socket, "exit\n\n");
            fclose($this->socket);
            $this->socket = null;
        }
    }

    private function connect(): void
    {
        if ($this->socket) {
            return;
        }
        if (! $this->password) {
            throw new RuntimeException('FREESWITCH_ESL_PASSWORD is not set.');
        }

        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);
        if (! $socket) {
            throw new RuntimeException("Cannot reach FreeSWITCH at {$this->host}:{$this->port}: {$error}");
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        $this->readReply(); // "Content-Type: auth/request"
        fwrite($this->socket, "auth {$this->password}\n\n");
        $reply = $this->readReply();
        if (! str_starts_with($reply['headers']['Reply-Text'] ?? '', '+OK')) {
            $this->close();
            throw new RuntimeException('FreeSWITCH rejected the event socket password.');
        }
    }

    /**
     * @return array{headers: array<string, string>, body: string}
     */
    private function readReply(): array
    {
        $headers = [];
        while (($line = fgets($this->socket)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if ($headers === []) {
                    continue;
                }
                break;
            }
            [$name, $value] = array_pad(explode(':', $line, 2), 2, '');
            $headers[$name] = trim($value);
        }

        if ($line === false && stream_get_meta_data($this->socket)['timed_out']) {
            throw new RuntimeException('Timed out waiting for FreeSWITCH.');
        }

        $body = '';
        $length = (int) ($headers['Content-Length'] ?? 0);
        while (strlen($body) < $length && ! feof($this->socket)) {
            $chunk = fread($this->socket, $length - strlen($body));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        return ['headers' => $headers, 'body' => $body];
    }
}
