<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class HoverProvider
{
    /** PHPX attribute docs: JSX-name => [HTML equivalent, description] */
    private const ATTRIBUTE_DOCS = [
        'className' => ['class', 'CSS class name. In PHPX, use `className` instead of `class` (which is a reserved PHP keyword).'],
        'htmlFor' => ['for', 'Associates a `<label>` with a form element. Use `htmlFor` instead of `for` (reserved PHP keyword).'],
        'tabIndex' => ['tabindex', 'Specifies the tab order of an element.'],
        'autoFocus' => ['autofocus', 'Automatically focus the element on page load.'],
        'autoPlay' => ['autoplay', 'Automatically start media playback.'],
        'crossOrigin' => ['crossorigin', 'Configures CORS for the element.'],
        'dangerouslySetInnerHTML' => [null, 'Injects raw HTML. Use with caution — no escaping is applied.'],
        'style' => [null, 'Inline styles as a PHP array. Keys are camelCase CSS properties, e.g. `[\'backgroundColor\' => \'red\']`.'],
        'data' => [null, 'Data attribute namespace. Pass an array, e.g. `data={[\'id\' => 123]}` becomes `data-id="123"`.'],
        'aria' => [null, 'ARIA attribute namespace. Pass an array, e.g. `aria={[\'label\' => \'Close\']}` becomes `aria-label="Close"`.'],
        'key' => [null, 'Unique key for list rendering. Used to track element identity across re-renders.'],
    ];

    public function hover(TextDocumentItem $document, int $line, int $character): ?array
    {
        $lineText = $document->getLine($line);

        if ($lineText === null) {
            return null;
        }

        // Check if this is a fragment — must happen before getWordAt() because
        // <> and </> consist of non-word characters that getWordAt() would reject.
        // Scan all occurrences on the line (not just the first) so that later
        // fragments on the same line are detected correctly.
        $fragmentHover = $this->checkFragmentHover($lineText, $line, $character);
        if ($fragmentHover !== null) {
            return $fragmentHover;
        }

        // Find the word at the cursor position
        $word = $this->getWordAt($lineText, $character);

        if ($word === null) {
            return null;
        }

        [$wordText, $startChar, $endChar] = $word;

        // Check if it's an attribute name
        if (isset(self::ATTRIBUTE_DOCS[$wordText])) {
            [$htmlEquiv, $description] = self::ATTRIBUTE_DOCS[$wordText];
            $content = "**`{$wordText}`**";
            if ($htmlEquiv !== null) {
                $content .= " → HTML `{$htmlEquiv}`";
            }
            $content .= "\n\n{$description}";

            return $this->makeHover($content, $line, $startChar, $endChar);
        }

        // Check if it's an HTML/custom element tag name inside < > or </ >
        // Skip if the cursor is inside a {…} expression container — the < before
        // the word is a comparison operator, not markup.
        $tagStart = $this->isInsideExpression($lineText, $character)
            ? -1
            : $this->findTagNameStart($lineText, $startChar);
        if ($tagStart >= 0) {
            // Extract the full tag name including hyphens (e.g. my-component)
            $tagName = $this->getTagNameAt($lineText, $tagStart);
            $tagEnd = $tagStart + strlen($tagName);

            $isComponent = ctype_upper($tagName[0] ?? '');
            if ($isComponent) {
                return $this->makeHover(
                    "**PHPX Component** `<{$tagName}>`\n\nA user-defined component. Must be a PHP function or closure that accepts an `array \$props` parameter and returns a PHPX array.",
                    $line, $tagStart, $tagEnd,
                );
            }

            return $this->makeHover(
                "**HTML Element** `<{$tagName}>`",
                $line, $tagStart, $tagEnd,
            );
        }

        return null;
    }

    /**
     * @return array{string, int, int}|null [word, startChar, endChar]
     */
    private function getWordAt(string $line, int $character): ?array
    {
        if ($character < 0 || $character >= strlen($line)) {
            return null;
        }

        $start = $character;
        $end = $character;

        while ($start > 0 && $this->isWordChar($line[$start - 1])) {
            $start--;
        }

        while ($end < strlen($line) - 1 && $this->isWordChar($line[$end + 1])) {
            $end++;
        }

        if (!$this->isWordChar($line[$character])) {
            return null;
        }

        $word = substr($line, $start, $end - $start + 1);
        return [$word, $start, $end + 1];
    }

    private function isWordChar(string $char): bool
    {
        return preg_match('/[\w]/', $char) === 1;
    }

    /**
     * Check if the word at $wordStart is part of a tag name.
     * Walks backwards past hyphens and word chars to find `<` or `</`.
     * Returns the start position of the full tag name, or -1 if not a tag.
     */
    private function findTagNameStart(string $line, int $wordStart): int
    {
        // Walk backwards past word chars and hyphens (for custom elements like my-component)
        $pos = $wordStart;
        while ($pos > 0 && ($this->isWordChar($line[$pos - 1]) || $line[$pos - 1] === '-')) {
            $pos--;
        }

        // Now check what's immediately before: should be < or </
        $before = rtrim(substr($line, 0, $pos));
        if (str_ends_with($before, '<') || str_ends_with($before, '</')) {
            return $pos;
        }

        return -1;
    }

    /**
     * Extract the full tag name starting at a position, including hyphens.
     */
    private function getTagNameAt(string $line, int $start): string
    {
        $end = $start;
        $len = strlen($line);
        while ($end < $len && ($this->isWordChar($line[$end]) || $line[$end] === '-')) {
            $end++;
        }
        return substr($line, $start, $end - $start);
    }

    /**
     * Check all <> and </> occurrences on the line and return a fragment hover
     * if the cursor falls within any of them.
     */
    private function checkFragmentHover(string $lineText, int $line, int $character): ?array
    {
        $fragmentMsg = "**PHPX Fragment** `<>...</>`\n\nA wrapper for grouping multiple elements without adding an extra node to the DOM.";

        // Check all </> first (longer match prevents <> matching the < of </>)
        $offset = 0;
        while (($pos = strpos($lineText, '</>', $offset)) !== false) {
            if ($character >= $pos && $character < $pos + 3) {
                return $this->makeHover($fragmentMsg, $line, $pos, $pos + 3);
            }
            $offset = $pos + 3;
        }

        // Check all <>
        $offset = 0;
        while (($pos = strpos($lineText, '<>', $offset)) !== false) {
            if ($character >= $pos && $character < $pos + 2) {
                return $this->makeHover($fragmentMsg, $line, $pos, $pos + 2);
            }
            $offset = $pos + 2;
        }

        return null;
    }

    /**
     * Check if the cursor is inside a {…} expression container by counting
     * unmatched opening braces in the line prefix up to the cursor.
     */
    private function isInsideExpression(string $lineText, int $character): bool
    {
        $depth = 0;
        $len = min($character, strlen($lineText));
        for ($i = 0; $i < $len; $i++) {
            if ($lineText[$i] === '{') {
                $depth++;
            } elseif ($lineText[$i] === '}') {
                $depth = max(0, $depth - 1);
            }
        }
        return $depth > 0;
    }

    private function makeHover(string $content, int $line, int $startChar, int $endChar): array
    {
        return [
            'contents' => [
                'kind' => 'markdown',
                'value' => $content,
            ],
            'range' => [
                'start' => ['line' => $line, 'character' => $startChar],
                'end' => ['line' => $line, 'character' => $endChar],
            ],
        ];
    }
}
