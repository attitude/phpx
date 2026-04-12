<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class TextDocumentManager
{
    /** @var array<string, TextDocumentItem> uri => document */
    private array $documents = [];

    public function open(string $uri, string $languageId, int $version, string $text): void
    {
        $this->documents[$uri] = new TextDocumentItem($uri, $languageId, $version, $text);
    }

    /**
     * Note: The server advertises textDocumentSync.change = 1 (full sync), so clients
     * always send the entire document text. The incremental change path (with UTF-16
     * offsets) is not used in practice; the byte-offset implementation is sufficient
     * for full-text replacements.
     */
    public function change(string $uri, int $version, array $contentChanges): void
    {
        $document = $this->documents[$uri] ?? null;

        if ($document === null) {
            return;
        }

        $text = $document->text;

        foreach ($contentChanges as $change) {
            if (isset($change['range'])) {
                $text = $this->applyIncrementalChange($text, $change['range'], $change['text']);
            } else {
                $text = $change['text'];
            }
        }

        $this->documents[$uri] = new TextDocumentItem($uri, $document->languageId, $version, $text);
    }

    public function close(string $uri): void
    {
        unset($this->documents[$uri]);
    }

    public function get(string $uri): ?TextDocumentItem
    {
        return $this->documents[$uri] ?? null;
    }

    public function all(): array
    {
        return $this->documents;
    }

    private function applyIncrementalChange(string $text, array $range, string $newText): string
    {
        $lines = explode("\n", $text);
        $startLine = $range['start']['line'];
        $startChar = $range['start']['character'];
        $endLine = $range['end']['line'];
        $endChar = $range['end']['character'];

        $startOffset = $this->lineCharToOffset($lines, $startLine, $startChar);
        $endOffset = $this->lineCharToOffset($lines, $endLine, $endChar);

        return substr($text, 0, $startOffset) . $newText . substr($text, $endOffset);
    }

    /**
     * Convert a (line, character) pair to a byte offset in the document string.
     * Assumes lines are separated by "\n" (as produced by explode("\n", $text)).
     * Documents using "\r\n" line endings are handled correctly because
     * strlen($lines[$i]) includes the "\r", so adding +1 for "\n" gives the
     * right total byte count per line.
     *
     * Note: this method is only reachable when a client sends an incremental
     * change (range != null). Since the server advertises textDocumentSync.change = 1
     * (full sync), no LSP-conformant client will send ranges in practice.
     */
    private function lineCharToOffset(array $lines, int $line, int $char): int
    {
        $offset = 0;

        for ($i = 0; $i < $line && $i < count($lines); $i++) {
            $offset += strlen($lines[$i]) + 1; // +1 for \n separator
        }

        return $offset + $char;
    }
}
