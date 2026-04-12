<?php declare(strict_types=1);

/**
 * LSP protocol compliance tests.
 *
 * Every test drives the Server through the Transport layer (end-to-end over
 * in-memory streams), verifying that the server honours the LSP specification
 * contracts rather than testing individual provider methods.
 */

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Transport;
use Attitude\PHPX\LanguageServer\Server;

// ── Helpers ─────────────────────────────────────────────────────────────────

/**
 * Encode a Message into raw LSP wire bytes (Content-Length header + JSON body).
 */
function contractEncode(Message $message): string
{
    $json = json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
}

/**
 * Run a sequence of client messages through a fresh Server and return all
 * response messages the server wrote to its output stream.
 *
 * @param  Message[] $messages
 * @return Message[]
 */
function runSession(array $messages): array
{
    $inputData = '';
    foreach ($messages as $msg) {
        $inputData .= contractEncode($msg);
    }

    $input  = fopen('php://memory', 'r+');
    fwrite($input, $inputData);
    rewind($input);

    $output = fopen('php://memory', 'r+');

    $transport = new Transport($input, $output);
    $server    = new Server($transport);
    $server->run();

    // Read back all responses
    rewind($output);
    $readTransport = new Transport($output, fopen('php://memory', 'r+'));
    $responses = [];

    while (($msg = $readTransport->read()) !== null) {
        $responses[] = $msg;
    }

    fclose($input);
    fclose($output);

    return $responses;
}

// ── Tests ───────────────────────────────────────────────────────────────────

describe('LSP Contracts', function () {

    // ── Shutdown / Exit lifecycle ────────────────────────────────────────────

    describe('Shutdown / Exit lifecycle', function () {

        it('keeps the server alive after shutdown so it can receive exit', function () {
            // After shutdown the server MUST still process messages (at least
            // the exit notification).  We verify by sending a request after
            // shutdown — the server should respond with an error (not silence).
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                // This request arrives after shutdown — server must reject it
                Message::request(3, 'textDocument/completion', [
                    'textDocument' => ['uri' => 'file:///test.phpx'],
                    'position' => ['line' => 0, 'character' => 0],
                ]),
                Message::notification('exit'),
            ]);

            // We should have: initialize response, shutdown response, error response for completion
            $ids = array_map(fn($r) => $r->id, $responses);
            expect($ids)->toContain(1);  // initialize
            expect($ids)->toContain(2);  // shutdown
            expect($ids)->toContain(3);  // rejected completion
        });

        it('rejects requests with error code -32600 after shutdown', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::request(3, 'textDocument/hover', [
                    'textDocument' => ['uri' => 'file:///test.phpx'],
                    'position' => ['line' => 0, 'character' => 0],
                ]),
                Message::notification('exit'),
            ]);

            // Find the response with id 3
            $rejected = null;
            foreach ($responses as $r) {
                if ($r->id === 3) {
                    $rejected = $r;
                    break;
                }
            }

            expect($rejected)->not->toBeNull();
            expect($rejected->error)->not->toBeNull();
            expect($rejected->error['code'])->toBe(-32600);
        });

        it('still processes notifications after shutdown', function () {
            // The exit notification after shutdown must be processed (it stops
            // the server run-loop).  If notifications were ignored, the server
            // would hang waiting for more input.  Because run() returns, the
            // test completes — that alone proves exit was processed.
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            // Shutdown response must be present
            $shutdownResponse = null;
            foreach ($responses as $r) {
                if ($r->id === 2) {
                    $shutdownResponse = $r;
                    break;
                }
            }

            expect($shutdownResponse)->not->toBeNull();
            expect($shutdownResponse->result)->toBeNull();
        });

        it('terminates the run-loop when exit follows shutdown', function () {
            // This test verifies that the server actually stops after exit.
            // If it did not, run() would block forever (or until stream EOF).
            // We prove it by adding a message after exit — it must NOT be
            // processed (no response for id 99).
            $inputData = '';
            $messages = [
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
                // This should never be read
                Message::request(99, 'textDocument/hover', [
                    'textDocument' => ['uri' => 'file:///test.phpx'],
                    'position' => ['line' => 0, 'character' => 0],
                ]),
            ];

            foreach ($messages as $msg) {
                $inputData .= contractEncode($msg);
            }

            $input = fopen('php://memory', 'r+');
            fwrite($input, $inputData);
            rewind($input);

            $output = fopen('php://memory', 'r+');

            $transport = new Transport($input, $output);
            $server = new Server($transport);
            $server->run();

            rewind($output);
            $readTransport = new Transport($output, fopen('php://memory', 'r+'));
            $responses = [];
            while (($msg = $readTransport->read()) !== null) {
                $responses[] = $msg;
            }

            $ids = array_map(fn($r) => $r->id, $responses);
            expect($ids)->not->toContain(99);

            fclose($input);
            fclose($output);
        });

        it('terminates the run-loop on exit without prior shutdown', function () {
            // exit without shutdown should also stop the server
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::notification('exit'),
            ]);

            // The server should have returned the initialize response and stopped
            expect(count($responses))->toBeGreaterThanOrEqual(1);
            expect($responses[0]->id)->toBe(1);
        });
    });

    // ── Position encoding negotiation ───────────────────────────────────────

    describe('Position encoding negotiation', function () {

        it('negotiates utf-8 when client supports both utf-8 and utf-16', function () {
            $responses = runSession([
                Message::request(1, 'initialize', [
                    'capabilities' => [
                        'general' => [
                            'positionEncodings' => ['utf-8', 'utf-16'],
                        ],
                    ],
                ]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $initResponse = $responses[0];
            expect($initResponse->id)->toBe(1);
            expect($initResponse->result['capabilities']['positionEncoding'])->toBe('utf-8');
        });

        it('omits positionEncoding when client sends no positionEncodings', function () {
            $responses = runSession([
                Message::request(1, 'initialize', [
                    'capabilities' => [],
                ]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $initResponse = $responses[0];
            // Server doesn't advertise utf-16 because it can't actually do
            // UTF-16 code unit conversion — omitting lets LSP default apply
            expect($initResponse->result['capabilities'])->not->toHaveKey('positionEncoding');
        });

        it('omits positionEncoding when client only supports utf-16', function () {
            $responses = runSession([
                Message::request(1, 'initialize', [
                    'capabilities' => [
                        'general' => [
                            'positionEncodings' => ['utf-16'],
                        ],
                    ],
                ]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $initResponse = $responses[0];
            // Server can't do utf-16 conversion, so it doesn't claim to
            expect($initResponse->result['capabilities'])->not->toHaveKey('positionEncoding');
        });
    });

    // ── Error responses ─────────────────────────────────────────────────────

    describe('Error responses', function () {

        it('returns error -32601 with method name for unknown methods', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'custom/nonExistentMethod'),
                Message::request(3, 'shutdown'),
                Message::notification('exit'),
            ]);

            $errorResponse = null;
            foreach ($responses as $r) {
                if ($r->id === 2) {
                    $errorResponse = $r;
                    break;
                }
            }

            expect($errorResponse)->not->toBeNull();
            expect($errorResponse->error)->not->toBeNull();
            expect($errorResponse->error['code'])->toBe(-32601);
            expect($errorResponse->error['message'])->toContain('custom/nonExistentMethod');
        });

        it('returns empty array for completion on unknown document', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'textDocument/completion', [
                    'textDocument' => ['uri' => 'file:///does-not-exist.phpx'],
                    'position' => ['line' => 0, 'character' => 0],
                ]),
                Message::request(3, 'shutdown'),
                Message::notification('exit'),
            ]);

            $completionResponse = null;
            foreach ($responses as $r) {
                if ($r->id === 2) {
                    $completionResponse = $r;
                    break;
                }
            }

            expect($completionResponse)->not->toBeNull();
            expect($completionResponse->error)->toBeNull();
            expect($completionResponse->result)->toBe([]);
        });

        it('returns null result for hover on unknown document', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'textDocument/hover', [
                    'textDocument' => ['uri' => 'file:///does-not-exist.phpx'],
                    'position' => ['line' => 0, 'character' => 0],
                ]),
                Message::request(3, 'shutdown'),
                Message::notification('exit'),
            ]);

            $hoverResponse = null;
            foreach ($responses as $r) {
                if ($r->id === 2) {
                    $hoverResponse = $r;
                    break;
                }
            }

            expect($hoverResponse)->not->toBeNull();
            expect($hoverResponse->error)->toBeNull();
            expect($hoverResponse->result)->toBeNull();
        });
    });

    // ── Capabilities contract ───────────────────────────────────────────────

    describe('Capabilities contract', function () {

        it('includes textDocumentSync with openClose and change mode', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $caps = $responses[0]->result['capabilities'];

            expect($caps['textDocumentSync'])->toBeArray();
            expect($caps['textDocumentSync']['openClose'])->toBeTrue();
            expect($caps['textDocumentSync']['change'])->toBe(1); // Full sync
        });

        it('includes completionProvider with triggerCharacters', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $caps = $responses[0]->result['capabilities'];

            expect($caps['completionProvider'])->toBeArray();
            expect($caps['completionProvider'])->toHaveKey('triggerCharacters');
            expect($caps['completionProvider']['triggerCharacters'])->toBeArray();
            expect($caps['completionProvider']['triggerCharacters'])->not->toBeEmpty();
        });

        it('includes hoverProvider set to true', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $caps = $responses[0]->result['capabilities'];

            expect($caps['hoverProvider'])->toBeTrue();
        });

        it('includes renameProvider with prepareProvider', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $caps = $responses[0]->result['capabilities'];

            expect($caps['renameProvider'])->toBeArray();
            expect($caps['renameProvider']['prepareProvider'])->toBeTrue();
        });

        it('includes serverInfo with name and version', function () {
            $responses = runSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $serverInfo = $responses[0]->result['serverInfo'];

            expect($serverInfo)->toBeArray();
            expect($serverInfo)->toHaveKey('name');
            expect($serverInfo)->toHaveKey('version');
            expect($serverInfo['name'])->toBe('phpx-language-server');
            expect($serverInfo['version'])->toBeString();
            expect($serverInfo['version'])->not->toBeEmpty();
        });
    });
});
