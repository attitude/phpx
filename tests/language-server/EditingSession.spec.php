<?php declare(strict_types=1);

/**
 * Realistic editing session integration tests.
 *
 * Each session simulates a VS Code editing workflow through the full
 * Server over Transport, verifying that responses are well-formed LSP messages.
 */

use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Transport;
use Attitude\PHPX\LanguageServer\Server;

/**
 * Encode a Message into raw LSP bytes (Content-Length header + JSON body).
 */
function editSessionEncode(Message $msg): string
{
    $json = json_encode($msg->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return "Content-Length: " . strlen($json) . "\r\n\r\n" . $json;
}

/**
 * Run a full LSP session: feed messages in, collect all responses.
 *
 * @param  Message[]  $messages  Client-to-server messages
 * @return Message[]  Server responses/notifications
 */
function runEditSession(array $messages): array
{
    $inputData = '';
    foreach ($messages as $msg) {
        $inputData .= editSessionEncode($msg);
    }

    $input = fopen('php://memory', 'r+');
    fwrite($input, $inputData);
    rewind($input);

    $output = fopen('php://memory', 'r+');
    $server = new Server(new Transport($input, $output));
    $server->run();

    // Read all responses back
    rewind($output);
    $transport = new Transport($output, fopen('php://memory', 'r+'));
    $responses = [];

    while (true) {
        $msg = $transport->read();
        if ($msg === null) break;
        $responses[] = $msg;
    }

    return $responses;
}

/**
 * Filter responses for publishDiagnostics notifications, optionally for a specific URI.
 */
function filterDiagnostics(array $responses, ?string $uri = null): array
{
    $result = array_filter($responses, function ($r) use ($uri) {
        if ($r->method !== 'textDocument/publishDiagnostics') return false;
        if ($uri !== null && ($r->params['uri'] ?? '') !== $uri) return false;
        return true;
    });
    return array_values($result);
}

/**
 * Filter responses for request responses (have an id, no method).
 */
function filterResponses(array $responses): array
{
    return array_values(array_filter($responses, fn($r) => $r->isResponse()));
}

describe('Editing Sessions', function () {

    describe('Session 1: Type a component from scratch', function () {
        it('follows a typing workflow from empty file to valid PHPX', function () {
            $uri = 'file:///project/scratch.phpx';

            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),

                // Open empty file
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uri,
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => '',
                    ],
                ]),

                // Type "<" — incomplete tag
                Message::notification('textDocument/didChange', [
                    'textDocument' => ['uri' => $uri, 'version' => 2],
                    'contentChanges' => [['text' => '<']],
                ]),

                // Type "<div>" — unclosed tag
                Message::notification('textDocument/didChange', [
                    'textDocument' => ['uri' => $uri, 'version' => 3],
                    'contentChanges' => [['text' => '<div>']],
                ]),

                // Type "<div></div>" — valid
                Message::notification('textDocument/didChange', [
                    'textDocument' => ['uri' => $uri, 'version' => 4],
                    'contentChanges' => [['text' => '<div></div>']],
                ]),

                // Request completion right after <
                Message::request(2, 'textDocument/completion', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 1],
                ]),

                Message::request(3, 'shutdown'),
                Message::notification('exit'),
            ]);

            // All responses must have jsonrpc field
            foreach ($responses as $r) {
                expect($r->jsonrpc)->toBe('2.0');
            }

            // Collect diagnostics in order
            $diags = filterDiagnostics($responses, $uri);

            // We expect 4 diagnostic notifications: didOpen (empty), didChange x3
            expect(count($diags))->toBeGreaterThanOrEqual(4);

            // First: empty file - may or may not error depending on implementation
            // Second: "<" alone is a valid less-than operator (not a truncated tag) - no error
            expect($diags[1]->params['diagnostics'])->toBe([]);

            // Third: "<div>" unclosed should have error
            expect($diags[2]->params['diagnostics'])->not->toBe([]);

            // Fourth: "<div></div>" is valid - no errors
            expect($diags[3]->params['diagnostics'])->toBe([]);

            // Completion response
            $completionResp = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 2) {
                    $completionResp = $r;
                    break;
                }
            }
            expect($completionResp)->not->toBeNull();
            expect($completionResp->result)->toBeArray();

            // Shutdown response
            $shutdownResp = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 3) {
                    $shutdownResp = $r;
                    break;
                }
            }
            expect($shutdownResp)->not->toBeNull();
            expect($shutdownResp->result)->toBeNull();
        });
    });

    describe('Session 2: Fix a broken file', function () {
        it('reports error then clears after fix', function () {
            $uri = 'file:///project/broken.phpx';

            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),

                // Open with unclosed tag (always errors, unlike mismatched tags
                // which depend on assert() and may pass when zend.assertions=-1)
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uri,
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => '<div>Hello',
                    ],
                ]),

                // Fix it
                Message::notification('textDocument/didChange', [
                    'textDocument' => ['uri' => $uri, 'version' => 2],
                    'contentChanges' => [['text' => '<div>Hello</div>']],
                ]),

                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $diags = filterDiagnostics($responses, $uri);
            expect(count($diags))->toBeGreaterThanOrEqual(2);

            // First diagnostics: has errors (mismatched tags)
            expect($diags[0]->params['diagnostics'])->not->toBe([]);

            // Second diagnostics: no errors (fixed)
            expect($diags[1]->params['diagnostics'])->toBe([]);

            // Verify responses have correct jsonrpc
            $reqResponses = filterResponses($responses);
            foreach ($reqResponses as $r) {
                expect($r->jsonrpc)->toBe('2.0');
                expect($r->id)->not->toBeNull();
            }
        });
    });

    describe('Session 3: Rename workflow', function () {
        it('prepares rename, executes it, and verifies the result is valid', function () {
            $uri = 'file:///project/rename.phpx';
            $originalText = "<ol>\n  <li>Item 1</li>\n  <li>Item 2</li>\n</ol>";

            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),

                // Open with an ordered list
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uri,
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => $originalText,
                    ],
                ]),

                // prepareRename on "ol" at line 0, char 1
                Message::request(2, 'textDocument/prepareRename', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 1],
                ]),

                // rename ol -> ul
                Message::request(3, 'textDocument/rename', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 1],
                    'newName' => 'ul',
                ]),

                Message::request(4, 'shutdown'),
                Message::notification('exit'),
            ]);

            // prepareRename response
            $prepareResp = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 2) {
                    $prepareResp = $r;
                    break;
                }
            }
            expect($prepareResp)->not->toBeNull();
            expect($prepareResp->result)->not->toBeNull();
            expect($prepareResp->result['placeholder'])->toBe('ol');
            expect($prepareResp->result['range']['start']['line'])->toBe(0);
            expect($prepareResp->result['range']['start']['character'])->toBe(1);
            expect($prepareResp->result['range']['end']['character'])->toBe(3);

            // rename response
            $renameResp = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 3) {
                    $renameResp = $r;
                    break;
                }
            }
            expect($renameResp)->not->toBeNull();
            expect($renameResp->result)->not->toBeNull();

            $edits = $renameResp->result['changes'][$uri];
            expect($edits)->toHaveCount(2);

            // Apply edits and verify the renamed document
            $renamedText = applyEdits($originalText, $edits);
            expect($renamedText)->toBe("<ul>\n  <li>Item 1</li>\n  <li>Item 2</li>\n</ul>");

            // Now send the renamed text as a didChange and verify it produces clean diagnostics
            $responses2 = runEditSession([
                Message::request(10, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uri,
                        'languageId' => 'phpx',
                        'version' => 3,
                        'text' => $renamedText,
                    ],
                ]),
                Message::request(11, 'shutdown'),
                Message::notification('exit'),
            ]);

            $diags = filterDiagnostics($responses2, $uri);
            expect(count($diags))->toBeGreaterThanOrEqual(1);
            expect($diags[0]->params['diagnostics'])->toBe([]);
        });
    });

    describe('Session 4: Multiple files', function () {
        it('tracks diagnostics independently per file', function () {
            $uriA = 'file:///project/fileA.phpx';
            $uriB = 'file:///project/fileB.phpx';

            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),

                // Open file A — valid
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uriA,
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => '<div>Valid</div>',
                    ],
                ]),

                // Open file B — invalid (unclosed tag — always errors regardless of assert settings)
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uriB,
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => '<div>Broken',
                    ],
                ]),

                // Close file A
                Message::notification('textDocument/didClose', [
                    'textDocument' => ['uri' => $uriA],
                ]),

                // Change file B (still broken — different unclosed tag)
                Message::notification('textDocument/didChange', [
                    'textDocument' => ['uri' => $uriB, 'version' => 2],
                    'contentChanges' => [['text' => '<span>Still broken']],
                ]),

                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            // File A diagnostics on open: empty (valid)
            $diagsA = filterDiagnostics($responses, $uriA);
            expect(count($diagsA))->toBeGreaterThanOrEqual(1);
            expect($diagsA[0]->params['diagnostics'])->toBe([]);

            // File A close: clears diagnostics
            $closeDiags = array_filter($diagsA, fn($d) => count($d->params['diagnostics']) === 0);
            expect(count($closeDiags))->toBeGreaterThanOrEqual(1);

            // File B diagnostics on open: has errors
            $diagsB = filterDiagnostics($responses, $uriB);
            expect(count($diagsB))->toBeGreaterThanOrEqual(2);
            expect($diagsB[0]->params['diagnostics'])->not->toBe([]);

            // File B still has errors after change
            expect($diagsB[1]->params['diagnostics'])->not->toBe([]);

            // Verify close sends empty diagnostics for file A
            $allDiagsA = filterDiagnostics($responses, $uriA);
            $lastA = end($allDiagsA);
            expect($lastA->params['diagnostics'])->toBe([]);
        });
    });

    describe('Session 5: Hover during editing', function () {
        it('provides hover results for attributes and tag names', function () {
            $uri = 'file:///project/hover.phpx';

            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),

                // Open with rich PHPX
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => $uri,
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => '<div className="test" style={[\'color\' => \'red\']}>Hello</div>',
                    ],
                ]),

                // Hover over className (starts at char 5)
                Message::request(2, 'textDocument/hover', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 7],
                ]),

                // Hover over style (starts at char 21)
                Message::request(3, 'textDocument/hover', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 23],
                ]),

                // Hover over div (tag name at char 1)
                Message::request(4, 'textDocument/hover', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 2],
                ]),

                // Hover over "Hello" (text content)
                Message::request(5, 'textDocument/hover', [
                    'textDocument' => ['uri' => $uri],
                    'position' => ['line' => 0, 'character' => 51],
                ]),

                Message::request(6, 'shutdown'),
                Message::notification('exit'),
            ]);

            // className hover
            $classNameHover = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 2) {
                    $classNameHover = $r;
                    break;
                }
            }
            expect($classNameHover)->not->toBeNull();
            expect($classNameHover->result)->not->toBeNull();
            expect($classNameHover->result['contents']['kind'])->toBe('markdown');
            expect($classNameHover->result['contents']['value'])->toContain('class');

            // style hover
            $styleHover = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 3) {
                    $styleHover = $r;
                    break;
                }
            }
            expect($styleHover)->not->toBeNull();
            expect($styleHover->result)->not->toBeNull();
            expect($styleHover->result['contents']['kind'])->toBe('markdown');
            expect($styleHover->result['contents']['value'])->toContain('style');

            // div tag hover
            $divHover = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 4) {
                    $divHover = $r;
                    break;
                }
            }
            expect($divHover)->not->toBeNull();
            expect($divHover->result)->not->toBeNull();
            expect($divHover->result['contents']['value'])->toContain('HTML Element');

            // Text hover - should be null
            $textHover = null;
            foreach ($responses as $r) {
                if ($r->isResponse() && $r->id === 5) {
                    $textHover = $r;
                    break;
                }
            }
            expect($textHover)->not->toBeNull();
            expect($textHover->result)->toBeNull();
        });
    });

    describe('LSP message format', function () {
        it('all responses have jsonrpc field', function () {
            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            foreach ($responses as $r) {
                expect($r->jsonrpc)->toBe('2.0');
            }
        });

        it('request responses have id matching request', function () {
            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $reqResponses = filterResponses($responses);
            $ids = array_map(fn($r) => $r->id, $reqResponses);

            expect($ids)->toContain(1);
            expect($ids)->toContain(2);
        });

        it('notifications have method but no id', function () {
            $responses = runEditSession([
                Message::request(1, 'initialize', ['capabilities' => []]),
                Message::notification('initialized'),
                Message::notification('textDocument/didOpen', [
                    'textDocument' => [
                        'uri' => 'file:///test.phpx',
                        'languageId' => 'phpx',
                        'version' => 1,
                        'text' => '<div>Hi</div>',
                    ],
                ]),
                Message::request(2, 'shutdown'),
                Message::notification('exit'),
            ]);

            $notifications = array_filter($responses, fn($r) => $r->isNotification());
            foreach ($notifications as $n) {
                expect($n->method)->not->toBeNull();
                expect($n->id)->toBeNull();
            }
        });
    });
});
