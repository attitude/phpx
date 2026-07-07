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
    /** Negotiated LSP position encoding: 'utf-16' (LSP default) or 'utf-8'. */
    private string $positionEncoding = 'utf-16';
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
            $message = null;

            try {
                $message = $this->transport->read();

                if ($message === null) {
                    break; // EOF — stream closed
                }

                $this->handleMessage($message);
            } catch (InvalidMessageException $e) {
                // Malformed frame shape — answer -32600 and keep reading.
                $this->logger?->warning("Invalid message: {$e->getMessage()}");
                $this->transport->write(Message::error($e->recoverableId, -32600, 'Invalid Request'));
            } catch (\Throwable $e) {
                // Any other failure during dispatch must not kill the server.
                $this->logger?->error("Uncaught error handling message: {$e->getMessage()}");
                if ($message !== null && $message->id !== null) {
                    $this->transport->write(Message::error($message->id, -32603, 'Internal error'));
                }
            }
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

        // Negotiate the position encoding. PHP strings are byte-indexed, so we
        // prefer utf-8 (positions map directly to strlen/substr offsets, no
        // conversion needed). When the client doesn't offer utf-8 we omit the
        // capability and the LSP default (utf-16) applies — incoming/outgoing
        // positions are then converted between UTF-16 code units and bytes at
        // the protocol boundary (see toByteCharacter()/encodePositions()).
        $clientEncodings = $params['capabilities']['general']['positionEncodings'] ?? [];
        if (!is_array($clientEncodings)) {
            $clientEncodings = [];
        }
        $positionEncoding = in_array('utf-8', $clientEncodings, true) ? 'utf-8' : null;
        $this->positionEncoding = $positionEncoding ?? 'utf-16';

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

        $character = $this->toByteCharacter($document, $line, $character);

        return $this->encodePositions($this->completion->complete($document, $line, $character), $document);
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

        $character = $this->toByteCharacter($document, $line, $character);

        return $this->encodePositions($this->hover->hover($document, $line, $character), $document);
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

        $character = $this->toByteCharacter($document, $line, $character);

        return $this->encodePositions($this->rename->prepareRename($document, $line, $character), $document);
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

        $character = $this->toByteCharacter($document, $line, $character);

        return $this->encodePositions($this->rename->rename($document, $line, $character, $newName), $document);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function publishDiagnostics(string $uri): void
    {
        $document = $this->documents->get($uri);

        if ($document === null) {
            return;
        }

        $diagnostics = $this->encodePositions($this->diagnostics->diagnose($document), $document);

        $this->transport->write(Message::notification('textDocument/publishDiagnostics', [
            'uri' => $uri,
            'diagnostics' => $diagnostics,
        ]));
    }

    /**
     * Convert an incoming Position's character from the negotiated encoding to a
     * byte offset on its line. Identity when the negotiated encoding is utf-8.
     */
    private function toByteCharacter(TextDocumentItem $document, int $line, int $character): int
    {
        if ($this->positionEncoding === 'utf-8') {
            return $character;
        }

        return PositionEncoding::utf16ToByte($document->getLine($line) ?? '', $character);
    }

    /**
     * Recursively convert every LSP Position ({line, character}) in a provider
     * result from byte offsets back to the negotiated encoding. Identity when
     * the negotiated encoding is utf-8. Each Position's character is converted
     * against its own line's text, so ranges spanning multiple lines are handled.
     */
    private function encodePositions(mixed $value, TextDocumentItem $document): mixed
    {
        if ($this->positionEncoding === 'utf-8' || !is_array($value)) {
            return $value;
        }

        if (isset($value['line'], $value['character']) && is_int($value['line']) && is_int($value['character'])) {
            $lineText = $document->getLine($value['line']) ?? '';
            $value['character'] = PositionEncoding::byteToUtf16($lineText, $value['character']);
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->encodePositions($item, $document);
        }

        return $value;
    }
}
