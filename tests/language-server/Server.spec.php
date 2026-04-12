<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Server;
use Attitude\PHPX\LanguageServer\Transport;

/**
 * Creates a Server with a Transport that captures output messages.
 *
 * @return array{Server, resource, Transport} The server, the output stream, and the transport
 */
function createTestServer(): array {
    $input = fopen('php://memory', 'r+');
    $output = fopen('php://memory', 'r+');
    $transport = new Transport($input, $output);
    $server = new Server($transport);

    return [$server, $output, $transport];
}

/**
 * Reads all messages written to the output stream.
 *
 * @param resource $output
 * @return Message[]
 */
function readOutputMessages($output): array {
    rewind($output);
    $readTransport = new Transport($output, fopen('php://memory', 'r+'));
    $messages = [];

    while (($msg = $readTransport->read()) !== null) {
        $messages[] = $msg;
    }

    return $messages;
}

describe('Server', function () {
    describe('initialize', function () {
        it('responds to initialize with capabilities', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(1, 'initialize', [
                'capabilities' => [],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->id)->toBe(1);
            expect($messages[0]->isResponse())->toBeTrue();

            $result = $messages[0]->result;
            expect($result)->toHaveKey('capabilities');
            expect($result)->toHaveKey('serverInfo');
            expect($result['serverInfo']['name'])->toBe('phpx-language-server');
            expect($result['capabilities'])->toHaveKey('textDocumentSync');
            expect($result['capabilities'])->toHaveKey('completionProvider');
            expect($result['capabilities'])->toHaveKey('hoverProvider');
        });
    });

    describe('shutdown', function () {
        it('responds to shutdown request', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(1, 'initialize', []));
            $server->handleMessage(Message::request(2, 'shutdown'));

            $messages = readOutputMessages($output);

            // initialize response + shutdown response
            expect($messages)->toHaveCount(2);
            // Shutdown returns null result, serialized as "result": null in the JSON response
            expect($messages[1]->id)->toBe(2);
        });
    });

    describe('textDocument/didOpen', function () {
        it('publishes diagnostics after opening a document', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>Hello</div>',
                ],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->method)->toBe('textDocument/publishDiagnostics');
            expect($messages[0]->params['uri'])->toBe('file:///test.phpx');
            expect($messages[0]->params['diagnostics'])->toBe([]);
        });

        it('publishes diagnostics with errors for invalid PHPX', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>Hello',
                ],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->params['diagnostics'])->not->toBeEmpty();
            expect($messages[0]->params['diagnostics'][0]['severity'])->toBe(1);
            expect($messages[0]->params['diagnostics'][0]['source'])->toBe('phpx');
        });
    });

    describe('textDocument/didChange', function () {
        it('publishes diagnostics after a change', function () {
            [$server, $output] = createTestServer();

            // Open a valid document
            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>Hello</div>',
                ],
            ]));

            // Change to invalid
            $server->handleMessage(Message::notification('textDocument/didChange', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'version' => 2,
                ],
                'contentChanges' => [
                    ['text' => '<div>Hello'],
                ],
            ]));

            $messages = readOutputMessages($output);

            // Two diagnostics notifications: one for open, one for change
            expect($messages)->toHaveCount(2);
            // Second should have errors
            expect($messages[1]->params['diagnostics'])->not->toBeEmpty();
        });
    });

    describe('textDocument/didClose', function () {
        it('clears diagnostics when closing a document', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>Hello</div>',
                ],
            ]));

            $server->handleMessage(Message::notification('textDocument/didClose', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                ],
            ]));

            $messages = readOutputMessages($output);

            // Open diagnostics + close clear diagnostics
            expect($messages)->toHaveCount(2);
            expect($messages[1]->method)->toBe('textDocument/publishDiagnostics');
            expect($messages[1]->params['uri'])->toBe('file:///test.phpx');
            expect($messages[1]->params['diagnostics'])->toBe([]);
        });
    });

    describe('textDocument/completion', function () {
        it('returns completions for an open document', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<d',
                ],
            ]));

            // Clear output from didOpen
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::request(1, 'textDocument/completion', [
                'textDocument' => ['uri' => 'file:///test.phpx'],
                'position' => ['line' => 0, 'character' => 2],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->id)->toBe(1);
            expect($messages[0]->isResponse())->toBeTrue();

            $labels = array_column($messages[0]->result, 'label');
            expect($labels)->toContain('div');
        });

        it('returns empty array for unknown document', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::request(1, 'textDocument/completion', [
                'textDocument' => ['uri' => 'file:///unknown.phpx'],
                'position' => ['line' => 0, 'character' => 0],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->result)->toBe([]);
        });
    });

    describe('textDocument/hover', function () {
        it('returns hover info for PHPX attributes', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div className="test">',
                ],
            ]));

            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::request(1, 'textDocument/hover', [
                'textDocument' => ['uri' => 'file:///test.phpx'],
                'position' => ['line' => 0, 'character' => 7],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->id)->toBe(1);
            expect($messages[0]->result)->not->toBeNull();
            expect($messages[0]->result['contents']['kind'])->toBe('markdown');
        });

        it('returns null for unknown document', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::request(1, 'textDocument/hover', [
                'textDocument' => ['uri' => 'file:///unknown.phpx'],
                'position' => ['line' => 0, 'character' => 0],
            ]));

            $messages = readOutputMessages($output);

            // hover returns null result, serialized as "result": null in the JSON response
            expect($messages)->toHaveCount(1);
        });
    });

    describe('unknown methods', function () {
        it('returns error -32601 for unknown request methods', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::request(1, 'unknown/method'));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->error)->not->toBeNull();
            expect($messages[0]->error['code'])->toBe(-32601);
            expect($messages[0]->error['message'])->toContain('Method not found');
        });
    });

    describe('initialization guard', function () {
        it('rejects requests before initialize with error -32002', function () {
            [$server, $output] = createTestServer();

            // Send a request without initializing first
            $server->handleMessage(Message::request(1, 'textDocument/completion', [
                'textDocument' => ['uri' => 'file:///test.phpx'],
                'position' => ['line' => 0, 'character' => 0],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->error)->not->toBeNull();
            expect($messages[0]->error['code'])->toBe(-32002);
            expect($messages[0]->error['message'])->toContain('not initialized');
        });

        it('allows initialize request itself before initialization', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(1, 'initialize', ['capabilities' => []]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->id)->toBe(1);
            expect($messages[0]->result)->toHaveKey('capabilities');
        });

        it('allows requests after initialize', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::request(1, 'initialize', ['capabilities' => []]));
            rewind($output);
            ftruncate($output, 0);

            $server->handleMessage(Message::request(2, 'textDocument/completion', [
                'textDocument' => ['uri' => 'file:///test.phpx'],
                'position' => ['line' => 0, 'character' => 0],
            ]));

            $messages = readOutputMessages($output);

            expect($messages)->toHaveCount(1);
            expect($messages[0]->id)->toBe(2);
            expect($messages[0]->error)->toBeNull();
        });
    });

    describe('empty URI guard', function () {
        it('ignores didOpen with empty URI', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>test</div>',
                    // uri intentionally missing
                ],
            ]));

            $messages = readOutputMessages($output);
            // No diagnostics should be published for empty URI
            expect($messages)->toHaveCount(0);
        });

        it('ignores didChange with empty URI', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didChange', [
                'textDocument' => ['version' => 2],
                'contentChanges' => [['text' => 'new content']],
            ]));

            $messages = readOutputMessages($output);
            expect($messages)->toHaveCount(0);
        });

        it('ignores didClose with empty URI', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didClose', [
                'textDocument' => [],
            ]));

            $messages = readOutputMessages($output);
            expect($messages)->toHaveCount(0);
        });

        it('ignores didSave with empty URI', function () {
            [$server, $output] = createTestServer();

            $server->handleMessage(Message::notification('textDocument/didSave', [
                'textDocument' => [],
            ]));

            $messages = readOutputMessages($output);
            expect($messages)->toHaveCount(0);
        });
    });
});
