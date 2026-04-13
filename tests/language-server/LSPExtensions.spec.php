<?php declare(strict_types=1);

use Attitude\PHPX\LanguageServer\CompletionExtension;
use Attitude\PHPX\LanguageServer\DiagnosticsExtension;
use Attitude\PHPX\LanguageServer\HoverExtension;
use Attitude\PHPX\LanguageServer\Message;
use Attitude\PHPX\LanguageServer\Server;
use Attitude\PHPX\LanguageServer\TextDocumentItem;
use Attitude\PHPX\LanguageServer\Transport;

// ── Test helpers ─────────────────────────────────────────────────────────────

function createServerWithExtensions(
    array $completionExtensions = [],
    array $hoverExtensions = [],
    array $diagnosticsExtensions = [],
): array {
    $input = fopen('php://memory', 'r+');
    $output = fopen('php://memory', 'r+');
    $transport = new Transport($input, $output);
    $server = new Server(
        transport: $transport,
        completionExtensions: $completionExtensions,
        hoverExtensions: $hoverExtensions,
        diagnosticsExtensions: $diagnosticsExtensions,
    );

    return [$server, $output, $transport];
}

function initializeExtServer(Server $server, mixed $output): void {
    $server->handleMessage(Message::request(0, 'initialize', ['capabilities' => []]));
    rewind($output);
    ftruncate($output, 0);
}

// ── CompletionExtension ───────────────────────────────────────────────────────

describe('CompletionExtension', function () {
    it('merges extension completion items with built-in items', function () {
        $ext = new class implements CompletionExtension {
            public function complete(TextDocumentItem $document, int $line, int $character): array {
                return [['label' => 'x-custom', 'kind' => 14, 'detail' => 'Custom tag']];
            }
            public function getCapabilities(): array { return []; }
        };

        [$server, $output] = createServerWithExtensions(completionExtensions: [$ext]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<d'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        $server->handleMessage(Message::request(1, 'textDocument/completion', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 2],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);

        $labels = array_column($messages[0]->result, 'label');
        expect($labels)->toContain('div');        // built-in
        expect($labels)->toContain('x-custom');   // extension
    });

    it('merges trigger characters from extensions into capabilities', function () {
        $ext = new class implements CompletionExtension {
            public function complete(TextDocumentItem $document, int $line, int $character): array { return []; }
            public function getCapabilities(): array { return ['triggerCharacters' => ['x', ':']]; }
        };

        [$server, $output] = createServerWithExtensions(completionExtensions: [$ext]);

        $server->handleMessage(Message::request(1, 'initialize', ['capabilities' => []]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);

        $triggers = $messages[0]->result['capabilities']['completionProvider']['triggerCharacters'];
        expect($triggers)->toContain('<');   // built-in
        expect($triggers)->toContain('x');  // extension
        expect($triggers)->toContain(':');  // extension
    });

    it('deduplicates trigger characters', function () {
        $ext = new class implements CompletionExtension {
            public function complete(TextDocumentItem $document, int $line, int $character): array { return []; }
            public function getCapabilities(): array { return ['triggerCharacters' => ['<', 'x']]; }
        };

        [$server, $output] = createServerWithExtensions(completionExtensions: [$ext]);
        $server->handleMessage(Message::request(1, 'initialize', ['capabilities' => []]));

        $messages = readOutputMessages($output);
        $triggers = $messages[0]->result['capabilities']['completionProvider']['triggerCharacters'];

        // '<' appears in both built-in and extension; must appear only once
        expect(array_count_values($triggers)['<'])->toBe(1);
    });

    it('all registered extensions are called', function () {
        $calls = [];

        $makeExt = function (string $label) use (&$calls): CompletionExtension {
            return new class($label, $calls) implements CompletionExtension {
                public function __construct(private string $label, private array &$calls) {}
                public function complete(TextDocumentItem $document, int $line, int $character): array {
                    $this->calls[] = $this->label;
                    return [['label' => $this->label, 'kind' => 14]];
                }
                public function getCapabilities(): array { return []; }
            };
        };

        [$server, $output] = createServerWithExtensions(completionExtensions: [
            $makeExt('ext-a'),
            $makeExt('ext-b'),
        ]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        $server->handleMessage(Message::request(1, 'textDocument/completion', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 1],
        ]));

        readOutputMessages($output);
        expect($calls)->toBe(['ext-a', 'ext-b']);
    });

    it('survives a throwing extension and still returns built-in items', function () {
        $throwing = new class implements CompletionExtension {
            public function complete(TextDocumentItem $document, int $line, int $character): array {
                throw new \RuntimeException('extension crash');
            }
            public function getCapabilities(): array { return []; }
        };

        [$server, $output] = createServerWithExtensions(completionExtensions: [$throwing]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<d'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        $server->handleMessage(Message::request(1, 'textDocument/completion', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 2],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);
        // Built-in completions still returned despite extension crash
        $labels = array_column($messages[0]->result, 'label');
        expect($labels)->toContain('div');
    });
});

// ── HoverExtension ────────────────────────────────────────────────────────────

describe('HoverExtension', function () {
    it('extension hover result takes priority over built-in', function () {
        $ext = new class implements HoverExtension {
            public function hover(TextDocumentItem $document, int $line, int $character): ?array {
                return [
                    'contents' => ['kind' => 'markdown', 'value' => 'extension hover'],
                    'range' => ['start' => ['line' => $line, 'character' => 0], 'end' => ['line' => $line, 'character' => 1]],
                ];
            }
        };

        [$server, $output] = createServerWithExtensions(hoverExtensions: [$ext]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div className="x">'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        // Hover on 'className' — built-in would return docs, extension should win
        $server->handleMessage(Message::request(1, 'textDocument/hover', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 7],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);
        expect($messages[0]->result['contents']['value'])->toBe('extension hover');
    });

    it('first extension that returns non-null wins', function () {
        $calls = [];

        $makeExt = function (bool $handles, string $name) use (&$calls): HoverExtension {
            return new class($handles, $name, $calls) implements HoverExtension {
                public function __construct(private bool $handles, private string $name, private array &$calls) {}
                public function hover(TextDocumentItem $document, int $line, int $character): ?array {
                    $this->calls[] = $this->name;
                    if (!$this->handles) return null;
                    return ['contents' => ['kind' => 'plaintext', 'value' => $this->name]];
                }
            };
        };

        [$server, $output] = createServerWithExtensions(hoverExtensions: [
            $makeExt(false, 'skip'),
            $makeExt(true, 'winner'),
            $makeExt(true, 'never-reached'),
        ]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div>'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        $server->handleMessage(Message::request(1, 'textDocument/hover', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 1],
        ]));

        readOutputMessages($output);
        expect($calls)->toBe(['skip', 'winner']);
    });

    it('falls back to built-in hover when all extensions return null', function () {
        $nullExt = new class implements HoverExtension {
            public function hover(TextDocumentItem $document, int $line, int $character): ?array { return null; }
        };

        [$server, $output] = createServerWithExtensions(hoverExtensions: [$nullExt]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div className="x">'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        $server->handleMessage(Message::request(1, 'textDocument/hover', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 7],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);
        // Built-in hover provides className docs
        expect($messages[0]->result)->not->toBeNull();
        expect($messages[0]->result['contents']['value'])->toContain('className');
    });

    it('survives a throwing extension and falls back to built-in hover', function () {
        $throwing = new class implements HoverExtension {
            public function hover(TextDocumentItem $document, int $line, int $character): ?array {
                throw new \RuntimeException('extension crash');
            }
        };

        [$server, $output] = createServerWithExtensions(hoverExtensions: [$throwing]);
        initializeExtServer($server, $output);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div className="x">'],
        ]));
        rewind($output);
        ftruncate($output, 0);

        $server->handleMessage(Message::request(1, 'textDocument/hover', [
            'textDocument' => ['uri' => 'file:///test.phpx'],
            'position' => ['line' => 0, 'character' => 7],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);
        // Built-in hover still works despite extension crash
        expect($messages[0]->result)->not->toBeNull();
        expect($messages[0]->result['contents']['value'])->toContain('className');
    });
});

// ── DiagnosticsExtension ──────────────────────────────────────────────────────

describe('DiagnosticsExtension', function () {
    it('merges extension diagnostics with built-in diagnostics', function () {
        $ext = new class implements DiagnosticsExtension {
            public function diagnose(TextDocumentItem $document): array {
                return [[
                    'range' => ['start' => ['line' => 0, 'character' => 0], 'end' => ['line' => 0, 'character' => 1]],
                    'severity' => 2, // Warning
                    'source' => 'test-ext',
                    'message' => 'Extension warning',
                ]];
            }
        };

        [$server, $output] = createServerWithExtensions(diagnosticsExtensions: [$ext]);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div>Hello</div>'],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);

        $diags = $messages[0]->params['diagnostics'];
        $sources = array_column($diags, 'source');
        expect($sources)->toContain('test-ext');
    });

    it('all registered extensions are called', function () {
        $calls = [];

        $makeExt = function (string $name) use (&$calls): DiagnosticsExtension {
            return new class($name, $calls) implements DiagnosticsExtension {
                public function __construct(private string $name, private array &$calls) {}
                public function diagnose(TextDocumentItem $document): array {
                    $this->calls[] = $this->name;
                    return [];
                }
            };
        };

        [$server, $output] = createServerWithExtensions(diagnosticsExtensions: [
            $makeExt('ext-a'),
            $makeExt('ext-b'),
        ]);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div>test</div>'],
        ]));

        expect($calls)->toBe(['ext-a', 'ext-b']);
    });

    it('extension diagnostics are also published on didChange', function () {
        $count = 0;
        $ext = new class($count) implements DiagnosticsExtension {
            public function __construct(private int &$count) {}
            public function diagnose(TextDocumentItem $document): array {
                $this->count++;
                return [];
            }
        };

        [$server, $output] = createServerWithExtensions(diagnosticsExtensions: [$ext]);

        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div>Hello</div>'],
        ]));

        $server->handleMessage(Message::notification('textDocument/didChange', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'version' => 2],
            'contentChanges' => [['text' => '<span>World</span>']],
        ]));

        // Called once for open, once for change
        expect($count)->toBe(2);
    });

    it('survives a throwing extension and still publishes built-in diagnostics', function () {
        $throwing = new class implements DiagnosticsExtension {
            public function diagnose(TextDocumentItem $document): array {
                throw new \RuntimeException('extension crash');
            }
        };

        [$server, $output] = createServerWithExtensions(diagnosticsExtensions: [$throwing]);

        // Open an invalid document — built-in diagnostics should still report the error
        $server->handleMessage(Message::notification('textDocument/didOpen', [
            'textDocument' => ['uri' => 'file:///test.phpx', 'languageId' => 'phpx', 'version' => 1, 'text' => '<div>unclosed'],
        ]));

        $messages = readOutputMessages($output);
        expect($messages)->toHaveCount(1);
        expect($messages[0]->method)->toBe('textDocument/publishDiagnostics');
        // Built-in parse error diagnostics still published despite extension crash
        expect($messages[0]->params['diagnostics'])->not->toBeEmpty();
        expect($messages[0]->params['diagnostics'][0]['source'])->toBe('phpx');
    });
});
