<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class Transport
{
    /** @var resource */
    private $input;
    /** @var resource */
    private $output;

    /**
     * @param resource $input  Readable stream (default: STDIN)
     * @param resource $output Writable stream (default: STDOUT)
     */
    public function __construct($input = null, $output = null)
    {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;

        // Ensure blocking mode — the server must wait indefinitely for client messages
        stream_set_blocking($this->input, true);
    }

    /**
     * Read the next valid LSP message from the input stream.
     *
     * Returns null on stream EOF or fatal framing errors (missing, invalid,
     * or zero Content-Length) — these desynchronize the stream and are
     * unrecoverable, so the server should be restarted by the client.
     *
     * Invalid JSON with a valid Content-Length is recoverable (the body was
     * fully consumed) — these are logged to stderr and skipped.
     */
    public function read(): ?Message
    {
        while (true) {
            $headers = $this->readHeaders();

            if ($headers === null) {
                return null; // EOF — stream closed
            }

            $contentLength = $headers['content-length'] ?? null;

            // Missing or invalid Content-Length is a fatal transport error.
            // Without knowing the body size we cannot find the next frame boundary,
            // so the stream is unrecoverable — return null to let the client restart.
            if ($contentLength === null) {
                fwrite(STDERR, "Transport::read: Missing Content-Length header, aborting\n");
                return null;
            }

            if (!ctype_digit($contentLength)) {
                fwrite(STDERR, "Transport::read: Invalid Content-Length '{$contentLength}', aborting\n");
                return null;
            }

            $length = (int) $contentLength;

            if ($length === 0) {
                fwrite(STDERR, "Transport::read: Content-Length is 0, aborting\n");
                return null;
            }

            // 64 MB — far above any realistic LSP message; guards against a corrupted
            // or misbehaving client hanging/OOMing the server with a huge body.
            if ($length > 67_108_864) {
                fwrite(STDERR, "Transport::read: Content-Length {$length} exceeds 64 MB limit, aborting\n");
                return null;
            }

            $body = $this->readBody($length);

            if ($body === null) {
                return null; // EOF mid-read — stream closed
            }

            $data = json_decode($body, true);

            if (!is_array($data)) {
                fwrite(STDERR, "Transport::read: Invalid JSON in message body, skipping\n");
                continue;
            }

            return Message::fromArray($data);
        }
    }

    public function write(Message $message): void
    {
        try {
            $json = json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            fwrite(STDERR, "Transport::write: JSON encode failed: {$e->getMessage()}\n");
            return;
        }

        $payload = "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
        $this->writeAll($payload);
        fflush($this->output);
    }

    /**
     * @return array<string, string>|null
     */
    private function readHeaders(): ?array
    {
        $headers = [];

        while (true) {
            $line = fgets($this->input);

            if ($line === false) {
                return null;
            }

            $line = trim($line);

            if ($line === '') {
                break;
            }

            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $headers;
    }

    private function readBody(int $length): ?string
    {
        $body = '';
        $remaining = $length;

        while ($remaining > 0) {
            $chunk = fread($this->input, $remaining);

            if ($chunk === false || $chunk === '') {
                return null;
            }

            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return $body;
    }

    /**
     * Write all bytes to the output stream, handling partial writes.
     */
    private function writeAll(string $data): void
    {
        $remaining = strlen($data);
        $offset = 0;

        while ($remaining > 0) {
            $written = @fwrite($this->output, substr($data, $offset), $remaining);

            if ($written === false || $written === 0) {
                fwrite(STDERR, "Transport::writeAll: fwrite failed, {$remaining} bytes remaining\n");
                return;
            }

            $offset += $written;
            $remaining -= $written;
        }
    }
}
