<?php declare(strict_types=1);

/**
 * Transport robustness tests.
 *
 * Two classes of error handling:
 *
 * - Fatal framing errors (missing, invalid, or zero Content-Length): the
 *   stream is desynchronised and recovery is impossible, so read() returns
 *   null and the server stops. The client is expected to restart the server.
 *
 * - Recoverable message errors (valid Content-Length but invalid JSON body):
 *   the body has been fully consumed so the next frame boundary is known,
 *   the bad message is logged to stderr, and read() loops to the next message.
 */

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Transport;

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Encode a Message into raw LSP wire bytes.
 */
function resilienceEncode(Message $message): string
{
    $json = json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
}

/**
 * Write raw bytes to a memory stream and return a Transport that reads from it.
 *
 * @return array{Transport, resource} The transport and the output stream
 */
function transportFromRaw(string $rawData): array
{
    $input = fopen('php://memory', 'r+');
    fwrite($input, $rawData);
    rewind($input);

    $output = fopen('php://memory', 'r+');

    return [new Transport($input, $output), $output];
}

/**
 * Read all messages from a Transport until EOF (null).
 *
 * @return Message[]
 */
function readAll(Transport $transport): array
{
    $messages = [];
    while (($msg = $transport->read()) !== null) {
        $messages[] = $msg;
    }
    return $messages;
}

// ── Tests ───────────────────────────────────────────────────────────────────

describe('Transport Resilience', function () {

    it('treats a message with missing Content-Length as a fatal transport error', function () {
        $valid = Message::request(1, 'initialize', ['capabilities' => []]);

        // First message: has a header but no Content-Length, followed by a blank
        // line (header terminator). Missing Content-Length is now a fatal error
        // — Transport returns null and stops reading.
        $raw = "X-Custom-Header: something\r\n\r\n"
             . resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(0);
    });

    it('skips a message with invalid JSON body and reads the next valid one', function () {
        $valid = Message::request(2, 'shutdown');

        // First message: valid Content-Length but body is not valid JSON
        $badBody = '{not valid json!!!}';
        $raw = "Content-Length: " . strlen($badBody) . "\r\n\r\n" . $badBody
             . resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(1);
        expect($messages[0]->id)->toBe(2);
        expect($messages[0]->method)->toBe('shutdown');
    });

    it('reads a valid message that follows an invalid one correctly', function () {
        $valid = Message::request(42, 'textDocument/hover', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 5],
        ]);

        // Bad: valid length header but garbled JSON
        $garbled = 'this is not json at all';
        $raw = "Content-Length: " . strlen($garbled) . "\r\n\r\n" . $garbled
             . resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(1);
        expect($messages[0]->id)->toBe(42);
        expect($messages[0]->method)->toBe('textDocument/hover');
        expect($messages[0]->params['textDocument']['uri'])->toBe('file:///test.phpx');
    });

    it('treats an empty body (Content-Length: 0) as a fatal transport error', function () {
        $valid = Message::notification('initialized');

        // Content-Length 0 means an empty body — this is now treated as a fatal
        // transport error, returning null and stopping further reads.
        $raw = "Content-Length: 0\r\n\r\n"
             . resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(0);
    });

    it('survives a write error from invalid UTF-8 and continues functioning', function () {
        $input  = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'r+');
        $transport = new Transport($input, $output);

        // Write a message containing invalid UTF-8 — triggers JsonException
        // via JSON_THROW_ON_ERROR in Transport::write(). The transport must
        // silently skip this message (log to stderr) and remain operational.
        $badMsg = Message::response(1, ['data' => "\xB1\x31"]);  // invalid UTF-8 byte sequence
        $transport->write($badMsg);  // should not throw

        // Output should be empty (the bad message was skipped)
        rewind($output);
        $written = stream_get_contents($output);
        expect($written)->toBe('');

        // Now write a valid message — transport must still work
        rewind($output);
        ftruncate($output, 0);
        $goodMsg = Message::response(2, ['status' => 'ok']);
        $transport->write($goodMsg);

        // Read it back to confirm transport is functional
        rewind($output);
        $readTransport = new Transport($output, fopen('php://memory', 'r+'));
        $received = $readTransport->read();

        expect($received)->not->toBeNull();
        expect($received->id)->toBe(2);
        expect($received->result)->toBe(['status' => 'ok']);

        fclose($input);
        fclose($output);
    });

    it('reads multiple messages in sequence from a single stream', function () {
        $msg1 = Message::request(1, 'initialize', ['capabilities' => []]);
        $msg2 = Message::notification('initialized');
        $msg3 = Message::request(2, 'textDocument/completion', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 0],
        ]);
        $msg4 = Message::request(3, 'shutdown');
        $msg5 = Message::notification('exit');

        $raw = resilienceEncode($msg1)
             . resilienceEncode($msg2)
             . resilienceEncode($msg3)
             . resilienceEncode($msg4)
             . resilienceEncode($msg5);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(5);
        expect($messages[0]->id)->toBe(1);
        expect($messages[0]->method)->toBe('initialize');
        expect($messages[1]->method)->toBe('initialized');
        expect($messages[2]->id)->toBe(2);
        expect($messages[2]->method)->toBe('textDocument/completion');
        expect($messages[3]->id)->toBe(3);
        expect($messages[3]->method)->toBe('shutdown');
        expect($messages[4]->method)->toBe('exit');
    });

    it('returns null on EOF (empty stream)', function () {
        $input  = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'r+');
        $transport = new Transport($input, $output);

        $msg = $transport->read();

        expect($msg)->toBeNull();

        fclose($input);
        fclose($output);
    });

    it('returns null on EOF (stream closed after valid messages)', function () {
        $valid = Message::request(1, 'initialize', ['capabilities' => []]);
        $raw = resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);

        // First read succeeds
        $first = $transport->read();
        expect($first)->not->toBeNull();
        expect($first->id)->toBe(1);

        // Second read hits EOF
        $second = $transport->read();
        expect($second)->toBeNull();
    });

    it('handles a mix of invalid and valid messages in sequence', function () {
        $valid1 = Message::request(1, 'initialize', ['capabilities' => []]);

        // Invalid JSON (with valid Content-Length) is recoverable — Transport
        // skips it and reads the next message. But Content-Length: 0 and missing
        // Content-Length are fatal — Transport returns null and stops.
        $badJson = '{"broken json';
        $raw = "Content-Length: " . strlen($badJson) . "\r\n\r\n" . $badJson   // bad JSON (skippable)
             . resilienceEncode($valid1);                                        // valid

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(1);
        expect($messages[0]->id)->toBe(1);
        expect($messages[0]->method)->toBe('initialize');
    });

    it('treats a message with non-numeric Content-Length as a fatal transport error', function () {
        $valid = Message::request(1, 'shutdown');

        $raw = "Content-Length: abc\r\n\r\n"
             . resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(0);
    });

    it('treats a message with negative Content-Length as a fatal transport error', function () {
        $valid = Message::request(1, 'shutdown');

        $raw = "Content-Length: -1\r\n\r\n"
             . resilienceEncode($valid);

        [$transport] = transportFromRaw($raw);
        $messages = readAll($transport);

        expect($messages)->toHaveCount(0);
    });

    it('handles partial writes gracefully via writeAll loop', function () {
        // Verify that write produces a complete, readable LSP frame
        $input  = fopen('php://memory', 'r+');
        $output = fopen('php://memory', 'r+');
        $transport = new Transport($input, $output);

        // Write a message with a large-ish payload
        $msg = Message::response(1, ['data' => str_repeat('x', 10000)]);
        $transport->write($msg);

        // Read it back — if writeAll works correctly, the full message is readable
        rewind($output);
        $readTransport = new Transport($output, fopen('php://memory', 'r+'));
        $received = $readTransport->read();

        expect($received)->not->toBeNull();
        expect($received->id)->toBe(1);
        expect(strlen($received->result['data']))->toBe(10000);

        fclose($input);
        fclose($output);
    });
});
