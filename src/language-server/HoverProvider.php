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

        // Check if it's an HTML/custom element tag name — delegate to TagScanner
        // which tokenizes the full document and correctly ignores tags inside
        // quoted attribute strings and {…} expression containers. This must happen
        // before getWordAt() so that hovering on a hyphen in "my-component" still
        // resolves to the full tag name rather than returning null early.
        $tag = TagScanner::findTagAtPosition($document->text, $line, $character);
        if ($tag !== null) {
            $tagName = $tag['name'];
            $tagStart = $tag['start'];
            $tagEnd = $tag['end'];

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

        // Find the word at the cursor position
        $word = $this->getWordAt($lineText, $character);

        if ($word === null) {
            return null;
        }

        [$wordText, $startChar, $endChar] = $word;

        // Check if it's an attribute name — guard against cursor being inside a string
        // or expression context (e.g. data="className" must not trigger attribute hover).
        if (isset(self::ATTRIBUTE_DOCS[$wordText])
            && !$this->isInsideStringOrExpression(substr($lineText, 0, $startChar))
        ) {
            [$htmlEquiv, $description] = self::ATTRIBUTE_DOCS[$wordText];
            $content = "**`{$wordText}`**";
            if ($htmlEquiv !== null) {
                $content .= " → HTML `{$htmlEquiv}`";
            }
            $content .= "\n\n{$description}";

            return $this->makeHover($content, $line, $startChar, $endChar);
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
     * Check all <> and </> occurrences on the line and return a fragment hover
     * if the cursor falls within any of them. Suppressed when the occurrence
     * is inside a quoted string or {…} expression context.
     */
    private function checkFragmentHover(string $lineText, int $line, int $character): ?array
    {
        $fragmentMsg = "**PHPX Fragment** `<>...</>`\n\nA wrapper for grouping multiple elements without adding an extra node to the DOM.";

        // Check all </> first (longer match prevents <> matching the < of </>)
        $offset = 0;
        while (($pos = strpos($lineText, '</>', $offset)) !== false) {
            if ($character >= $pos && $character < $pos + 3) {
                if ($this->isInsideStringOrExpression(substr($lineText, 0, $pos))) {
                    return null;
                }
                return $this->makeHover($fragmentMsg, $line, $pos, $pos + 3);
            }
            $offset = $pos + 3;
        }

        // Check all <>
        $offset = 0;
        while (($pos = strpos($lineText, '<>', $offset)) !== false) {
            if ($character >= $pos && $character < $pos + 2) {
                if ($this->isInsideStringOrExpression(substr($lineText, 0, $pos))) {
                    return null;
                }
                return $this->makeHover($fragmentMsg, $line, $pos, $pos + 2);
            }
            $offset = $pos + 2;
        }

        return null;
    }

    /**
     * Check if the given line prefix ends inside a quoted string ("…", '…', `…`)
     * or a {…} expression container. Used to suppress hover results when the
     * cursor is inside attribute string content rather than markup.
     *
     * Note: operates on the current-line prefix only. Multi-line attribute values
     * (a string literal spanning multiple lines) are not detected. In practice
     * PHPX source files do not use multi-line quoted attribute values, so this
     * is an acceptable limitation.
     */
    private function isInsideStringOrExpression(string $prefix): bool
    {
        $depth = 0;
        $inDouble = false;
        $inSingle = false;
        $inBacktick = false;
        $len = strlen($prefix);

        for ($i = 0; $i < $len; $i++) {
            $c = $prefix[$i];

            if ($inDouble) {
                if ($c === '\\') { $i++; continue; }
                if ($c === '"') { $inDouble = false; }
            } elseif ($inSingle) {
                if ($c === '\\') { $i++; continue; }
                if ($c === "'") { $inSingle = false; }
            } elseif ($inBacktick) {
                if ($c === '\\') { $i++; continue; }
                if ($c === '`') { $inBacktick = false; }
            } elseif ($depth === 0) {
                if ($c === '"') { $inDouble = true; }
                elseif ($c === "'") { $inSingle = true; }
                elseif ($c === '`') { $inBacktick = true; }
                elseif ($c === '{') { $depth++; }
            } else {
                if ($c === '{') { $depth++; }
                elseif ($c === '}') { $depth = max(0, $depth - 1); }
            }
        }

        return $depth > 0 || $inDouble || $inSingle || $inBacktick;
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
