<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

use Psr\Log\LoggerInterface;

final class Server
{
    private TextDocumentManager $documents;
    private DiagnosticsProvider $diagnostics;
    private CompletionProvider $completion;
    private HoverProvider $hover;
    private RenameProvider $rename;
    private Transport $transport;
    private bool $initialized = false;
    private bool $running = false;
    private bool $shutdownRequested = false;
    private ?LoggerInterface $logger;

    public function __construct(
        ?Transport $transport = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->transport = $transport ?? new Transport();
        $this->logger = $logger;
        $this->documents = new TextDocumentManager();
        $this->diagnostics = new DiagnosticsProvider();
        $this->completion = new CompletionProvider();
        $this->hover = new HoverProvider();
        $this->rename = new RenameProvider();
    }

    public function run(): void
    {
        $this->running = true;

        while ($this->running) {
            $message = $this->transport->read();

            if ($message === null) {
                break;
            }

            $this->handleMessage($message);
        }
    }

    public function handleMessage(Message $message): void
    {
        $this->logger?->debug("Received: {$message->method}", $message->toArray());

        if ($message->isRequest()) {
            $this->handleRequest($message);
        } elseif ($message->isNotification()) {
            $this->handleNotification($message);
        }
    }

    private function handleRequest(Message $message): void
    {
        // Per LSP: after shutdown, reject all requests except shutdown itself
        if ($this->shutdownRequested && $message->method !== 'shutdown') {
            $this->transport->write(Message::error(
                $message->id,
                -32600, // InvalidRequest
                'Server is shutting down',
            ));
            return;
        }

        // Per LSP: reject requests before initialize (except initialize itself)
        if (!$this->initialized && $message->method !== 'initialize') {
            $this->transport->write(Message::error(
                $message->id,
                -32002, // ServerNotInitialized
                'Server not initialized',
            ));
            return;
        }

        $notFound = new \stdClass(); // sentinel

        $result = match ($message->method) {
            'initialize' => $this->handleInitialize($message->params ?? []),
            'shutdown' => $this->handleShutdown(),
            'textDocument/completion' => $this->handleCompletion($message->params ?? []),
            'textDocument/hover' => $this->handleHover($message->params ?? []),
            'textDocument/prepareRename' => $this->handlePrepareRename($message->params ?? []),
            'textDocument/rename' => $this->handleRename($message->params ?? []),
            default => $notFound,
        };

        if ($result !== $notFound) {
            $this->transport->write(Message::response($message->id, $result));
        } else {
            $this->transport->write(Message::error(
                $message->id,
                -32601,
                "Method not found: {$message->method}",
            ));
        }
    }

    private function handleNotification(Message $message): void
    {
        match ($message->method) {
            'initialized' => $this->handleInitialized(),
            'exit' => $this->handleExit(),
            'textDocument/didOpen' => $this->handleDidOpen($message->params ?? []),
            'textDocument/didChange' => $this->handleDidChange($message->params ?? []),
            'textDocument/didClose' => $this->handleDidClose($message->params ?? []),
            'textDocument/didSave' => $this->handleDidSave($message->params ?? []),
            default => $this->logger?->debug("Unhandled notification: {$message->method}"),
        };
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    private function handleInitialize(array $params): array
    {
        $this->initialized = true;

        // Negotiate UTF-8 position encoding. PHP strings are byte-indexed,
        // so UTF-8 positions map directly to strlen/substr offsets.
        // We only advertise utf-8 because we don't implement UTF-16 code unit
        // conversion. If the client doesn't support utf-8, we omit positionEncoding
        // entirely (LSP default is utf-16, and for ASCII-only PHPX source files
        // byte offsets happen to equal UTF-16 code units).
        $clientEncodings = $params['capabilities']['general']['positionEncodings'] ?? [];
        $positionEncoding = in_array('utf-8', $clientEncodings, true) ? 'utf-8' : null;

        $capabilities = [
            'textDocumentSync' => [
                'openClose' => true,
                'change' => 1, // Full sync
                'save' => ['includeText' => true],
            ],
            'completionProvider' => [
                'triggerCharacters' => ['<', '/', ' '],
                'resolveProvider' => false,
            ],
            'hoverProvider' => true,
            'renameProvider' => [
                'prepareProvider' => true,
            ],
        ];

        if ($positionEncoding !== null) {
            $capabilities['positionEncoding'] = $positionEncoding;
        }

        return [
            'capabilities' => $capabilities,
            'serverInfo' => [
                'name' => 'phpx-language-server',
                'version' => '0.1.0',
            ],
        ];
    }

    private function handleInitialized(): void
    {
        $this->logger?->info('PHPX Language Server initialized');
    }

    private function handleShutdown(): mixed
    {
        // Per LSP spec: respond to shutdown, but stay alive until 'exit' notification
        $this->shutdownRequested = true;
        return null;
    }

    private function handleExit(): void
    {
        $this->running = false;
    }

    // ── Document Sync ────────────────────────────────────────────────────────

    private function handleDidOpen(array $params): void
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';

        if ($uri === '') {
            $this->logger?->warning('didOpen: missing textDocument.uri, ignoring');
            return;
        }

        $languageId = $td['languageId'] ?? 'phpx';
        $version = $td['version'] ?? 0;
        $text = $td['text'] ?? '';

        $this->documents->open($uri, $languageId, $version, $text);
        $this->publishDiagnostics($uri);
    }

    private function handleDidChange(array $params): void
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';

        if ($uri === '') {
            $this->logger?->warning('didChange: missing textDocument.uri, ignoring');
            return;
        }

        $version = $td['version'] ?? 0;
        $contentChanges = $params['contentChanges'] ?? [];

        $this->documents->change($uri, $version, $contentChanges);
        $this->publishDiagnostics($uri);
    }

    private function handleDidClose(array $params): void
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';

        if ($uri === '') {
            $this->logger?->warning('didClose: missing textDocument.uri, ignoring');
            return;
        }

        $this->documents->close($uri);

        // Clear diagnostics
        $this->transport->write(Message::notification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => [],
        ]));
    }

    private function handleDidSave(array $params): void
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';

        if ($uri === '') {
            $this->logger?->warning('didSave: missing textDocument.uri, ignoring');
            return;
        }

        // If save included text, update document
        if (isset($params['text'])) {
            $document = $this->documents->get($uri);
            if ($document !== null) {
                $this->documents->change($uri, $document->version + 1, [['text' => $params['text']]]);
            }
        }

        $this->publishDiagnostics($uri);
    }

    // ── Language Features ────────────────────────────────────────────────────

    private function handleCompletion(array $params): array
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';
        $position = $params['position'] ?? [];
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;

        $document = $this->documents->get($uri);

        if ($document === null) {
            return [];
        }

        return $this->completion->complete($document, $line, $character);
    }

    private function handleHover(array $params): ?array
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';
        $position = $params['position'] ?? [];
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;

        $document = $this->documents->get($uri);

        if ($document === null) {
            return null;
        }

        return $this->hover->hover($document, $line, $character);
    }

    private function handlePrepareRename(array $params): ?array
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';
        $position = $params['position'] ?? [];
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;

        $document = $this->documents->get($uri);

        if ($document === null) {
            return null;
        }

        return $this->rename->prepareRename($document, $line, $character);
    }

    private function handleRename(array $params): ?array
    {
        $td = $params['textDocument'] ?? [];
        $uri = $td['uri'] ?? '';
        $position = $params['position'] ?? [];
        $line = $position['line'] ?? 0;
        $character = $position['character'] ?? 0;
        $newName = $params['newName'] ?? '';

        $document = $this->documents->get($uri);

        if ($document === null) {
            return null;
        }

        return $this->rename->rename($document, $line, $character, $newName);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function publishDiagnostics(string $uri): void
    {
        $document = $this->documents->get($uri);

        if ($document === null) {
            return;
        }

        $diagnostics = $this->diagnostics->diagnose($document);

        $this->transport->write(Message::notification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => $diagnostics,
        ]));
    }
}
