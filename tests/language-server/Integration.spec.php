<?php declare(strict_types=1);

/**
 * Integration test: verifies a full LSP session lifecycle over the Transport,
 * simulating what the Node.js bridge does when it spawns the PHP process.
 */

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Transport;
use Attitude\PHPX\LanguageServer\Server;

/**
 * Helper: builds a full LSP message as raw bytes, including Content-Length header.
 */
function lspEncode(Message $message): string {
    $json = json_encode($message->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $length = strlen($json);
    return "Content-Length: {$length}\r\n\r\n{$json}";
}

/**
 * Helper: reads all response messages from the server output stream.
 */
function readIntegrationResponses($stream): array {
    rewind($stream);
    $transport = new Transport($stream, fopen('php://memory', 'r+'));
    $messages = [];

    while (true) {
        $msg = $transport->read();
        if ($msg === null) {
            break;
        }
        $messages[] = $msg;
    }

    return $messages;
}

describe('LSP Integration', function () {

    it('completes a full session lifecycle: initialize → open → diagnostics → completion → hover → close → shutdown', function () {
        // Build a sequence of client→server messages
        $messages = [
            Message::request(1, 'initialize', [
                'capabilities' => [],
                'rootUri' => 'file:///project',
            ]),
            Message::notification('initialized'),
            Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///project/test.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div className="hello">World</div>',
                ],
            ]),
            Message::request(2, 'textDocument/completion', [
                'textDocument' => ['uri' => 'file:///project/test.phpx'],
                'position' => ['line' => 0, 'character' => 1], // right after <
            ]),
            Message::request(3, 'textDocument/hover', [
                'textDocument' => ['uri' => 'file:///project/test.phpx'],
                'position' => ['line' => 0, 'character' => 5], // over "className"
            ]),
            Message::notification('textDocument/didClose', [
                'textDocument' => ['uri' => 'file:///project/test.phpx'],
            ]),
            Message::request(4, 'shutdown'),
        ];

        // Encode all messages into a single input stream
        $inputData = '';
        foreach ($messages as $msg) {
            $inputData .= lspEncode($msg);
        }

        $input = fopen('php://memory', 'r+');
        fwrite($input, $inputData);
        rewind($input);

        $output = fopen('php://memory', 'r+');

        $transport = new Transport($input, $output);
        $server = new Server($transport);
        $server->run();

        // Read all responses
        $responses = readIntegrationResponses($output);

        // Expect responses:
        // 1. initialize response
        // 2. publishDiagnostics for didOpen
        // 3. completion response
        // 4. hover response
        // 5. publishDiagnostics for didClose (clears)
        // 6. shutdown response

        expect(count($responses))->toBeGreaterThanOrEqual(6);

        // 1. Initialize response
        $initResponse = $responses[0];
        expect($initResponse->id)->toBe(1);
        expect($initResponse->result['capabilities'])->toBeArray();
        expect($initResponse->result['serverInfo']['name'])->toBe('phpx-language-server');

        // 2. publishDiagnostics after open (valid PHPX → empty diagnostics)
        $diagNotification = $responses[1];
        expect($diagNotification->method)->toBe('textDocument/publishDiagnostics');
        expect($diagNotification->params['uri'])->toBe('file:///project/test.phpx');
        expect($diagNotification->params['diagnostics'])->toBe([]);

        // 3. Completion response
        $completionResponse = $responses[2];
        expect($completionResponse->id)->toBe(2);
        expect($completionResponse->result)->toBeArray();

        // 4. Hover response
        $hoverResponse = $responses[3];
        expect($hoverResponse->id)->toBe(3);
        // className should trigger a hover
        expect($hoverResponse->result)->toBeArray();
        expect($hoverResponse->result['contents']['kind'])->toBe('markdown');

        // 5. publishDiagnostics clearing on close
        $clearDiag = $responses[4];
        expect($clearDiag->method)->toBe('textDocument/publishDiagnostics');
        expect($clearDiag->params['diagnostics'])->toBe([]);

        // 6. Shutdown response
        $shutdownResponse = $responses[5];
        expect($shutdownResponse->id)->toBe(4);
        expect($shutdownResponse->result)->toBeNull();
    });

    it('reports parse errors during a session', function () {
        $messages = [
            Message::request(1, 'initialize', ['capabilities' => []]),
            Message::notification('initialized'),
            Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///project/broken.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>unclosed',
                ],
            ]),
            Message::request(2, 'shutdown'),
        ];

        $inputData = '';
        foreach ($messages as $msg) {
            $inputData .= lspEncode($msg);
        }

        $input = fopen('php://memory', 'r+');
        fwrite($input, $inputData);
        rewind($input);

        $output = fopen('php://memory', 'r+');
        $server = new Server(new Transport($input, $output));
        $server->run();

        $responses = readIntegrationResponses($output);

        // Find the diagnostics notification
        $diagMessages = array_filter($responses, fn($r) => $r->method === 'textDocument/publishDiagnostics');
        $diagMessages = array_values($diagMessages);

        expect(count($diagMessages))->toBeGreaterThanOrEqual(1);
        expect($diagMessages[0]->params['diagnostics'])->not->toBe([]);
        expect($diagMessages[0]->params['diagnostics'][0]['severity'])->toBe(1);
        expect($diagMessages[0]->params['diagnostics'][0]['source'])->toBe('phpx');
    });

    it('handles document changes and re-diagnoses', function () {
        $messages = [
            Message::request(1, 'initialize', ['capabilities' => []]),
            Message::notification('initialized'),
            // Open with broken PHPX
            Message::notification('textDocument/didOpen', [
                'textDocument' => [
                    'uri' => 'file:///project/edit.phpx',
                    'languageId' => 'phpx',
                    'version' => 1,
                    'text' => '<div>unclosed',
                ],
            ]),
            // Fix the PHPX
            Message::notification('textDocument/didChange', [
                'textDocument' => ['uri' => 'file:///project/edit.phpx', 'version' => 2],
                'contentChanges' => [['text' => '<div>fixed</div>']],
            ]),
            Message::request(2, 'shutdown'),
        ];

        $inputData = '';
        foreach ($messages as $msg) {
            $inputData .= lspEncode($msg);
        }

        $input = fopen('php://memory', 'r+');
        fwrite($input, $inputData);
        rewind($input);

        $output = fopen('php://memory', 'r+');
        $server = new Server(new Transport($input, $output));
        $server->run();

        $responses = readIntegrationResponses($output);

        $diagMessages = array_filter($responses, fn($r) => $r->method === 'textDocument/publishDiagnostics');
        $diagMessages = array_values($diagMessages);

        // First diagnostics: has errors
        expect($diagMessages[0]->params['diagnostics'])->not->toBe([]);

        // Second diagnostics (after fix): no errors
        expect($diagMessages[1]->params['diagnostics'])->toBe([]);
    });
});
