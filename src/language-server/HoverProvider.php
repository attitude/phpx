<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class HoverProvider
{
    /** JSX-to-HTML attribute name mapping for the "→ HTML" hover note */
    private const JSX_TO_HTML = [
        'className'      => 'class',
        'htmlFor'        => 'for',
        'tabIndex'       => 'tabindex',
        'autoFocus'      => 'autofocus',
        'autoPlay'       => 'autoplay',
        'autoComplete'   => 'autocomplete',
        'crossOrigin'    => 'crossorigin',
        'readOnly'       => 'readonly',
        'noValidate'     => 'novalidate',
        'noModule'       => 'nomodule',
        'formAction'     => 'formaction',
        'formMethod'     => 'formmethod',
        'formNoValidate' => 'formnovalidate',
        'formTarget'     => 'formtarget',
        'formEncType'    => 'formenctype',
        'encType'        => 'enctype',
        'colSpan'        => 'colspan',
        'rowSpan'        => 'rowspan',
        'srcDoc'         => 'srcdoc',
        'srcSet'         => 'srcset',
        'useMap'         => 'usemap',
        'isMap'          => 'ismap',
        'hrefLang'       => 'hreflang',
        'dateTime'       => 'datetime',
        'httpEquiv'      => 'http-equiv',
        'allowFullScreen'=> 'allowfullscreen',
        'playsInline'    => 'playsinline',
        'fetchPriority'  => 'fetchpriority',
        'enterKeyHint'   => 'enterkeyhint',
        'inputMode'      => 'inputmode',
        'minLength'      => 'minlength',
        'maxLength'      => 'maxlength',
        'acceptCharset'  => 'accept-charset',
        'spellCheck'     => 'spellcheck',
        'contentEditable'=> 'contenteditable',
    ];

    public function hover(TextDocumentItem $document, int $line, int $character): ?array
    {
        $lineText = $document->getLine($line);

        if ($lineText === null) {
            return null;
        }

        // Fragment check must happen before findTagAtPosition: TagScanner skips
        // TX_FRAGMENT_ELEMENT_OPEN entirely, so <> and </> are invisible to it.
        // Also before getWordAt(), since <> and </> consist of non-word characters
        // that getWordAt() would reject.
        $fragmentHover = $this->checkFragmentHover($lineText, $line, $character);
        if ($fragmentHover !== null) {
            return $fragmentHover;
        }

        // Check element tag names before getWordAt() so that hovering on a hyphen
        // in "my-component" resolves to the full tag name rather than returning null.
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

        // Don't show hover inside string/expression contexts
        if ($this->isInsideStringOrExpression(substr($lineText, 0, $startChar))) {
            return null;
        }

        // Only show attribute hover when cursor is inside an opening tag's attribute area
        $tagContext = $this->findTagContextForAttribute($document, $line, $startChar);

        if ($tagContext === null) {
            return null;
        }

        // Try to find the attribute in the per-element data
        $attrInfo = HTMLAttributes::lookup($tagContext, $wordText);

        if ($attrInfo !== null) {
            [$type, $description] = $attrInfo;
            return $this->makeAttributeHover($wordText, $type, $description, $line, $startChar, $endChar);
        }

        return null;
    }

    /**
     * Build attribute hover content with type and HTML mapping info.
     */
    private function makeAttributeHover(string $attr, string $type, string $description, int $line, int $startChar, int $endChar): array
    {
        $htmlEquiv = self::JSX_TO_HTML[$attr] ?? null;

        $content = "**`{$attr}`**";
        if ($htmlEquiv !== null) {
            $content .= " → HTML `{$htmlEquiv}`";
        }
        $content .= ": `{$type}`";
        $content .= "\n\n{$description}";

        return $this->makeHover($content, $line, $startChar, $endChar);
    }

    /**
     * Find the tag name for the attribute at the given position.
     *
     * Scans the text before the attribute position (including preceding lines)
     * to find the opening `<tagName`.
     */
    private function findTagContextForAttribute(TextDocumentItem $document, int $line, int $attrStart): ?string
    {
        $lines = $document->getLines();

        // Build text from document start up to the attribute position
        $source = implode("\n", array_slice($lines, 0, $line)) . "\n" . substr($lines[$line] ?? '', 0, $attrStart);

        try {
            class_exists(\Attitude\PHPX\Parser\TokensList::class);
            $tokens = \Attitude\PHPX\Parser\Token::tokenize($source);
        } catch (\Throwable) {
            // Fallback: find the last `<tagName` in the source before this position
            if (preg_match('/<([a-zA-Z][a-zA-Z0-9-]*)(?:\s[^>]*)?$/', $source, $m)) {
                return strtolower($m[1]);
            }
            return null;
        }

        // Walk tokens to find the currently-open tag
        $currentTag = null;
        foreach ($tokens as $i => $token) {
            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next->id === T_STRING) {
                    $currentTag = $next->text;
                }
            } elseif ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_CLOSE) {
                $currentTag = null;
            }
        }

        return $currentTag !== null ? strtolower($currentTag) : null;
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
        return ctype_alnum($char) || $char === '_';
    }

    /**
     * Check all <> and </> occurrences on the line and return a fragment hover
     * if the cursor falls within any of them. Suppressed when the occurrence
     * is inside a quoted string or {…} expression context.
     */
    private function checkFragmentHover(string $lineText, int $line, int $character): ?array
    {
        $fragmentMsg = "**PHPX Fragment** `<>...</>`\n\nA wrapper for grouping multiple elements without adding an extra node to the DOM.";

        // Check </> before <> — "</>" contains "<>" as a substring, so checking
        // the longer token first prevents a false <> match inside </>.
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

    private function isInsideStringOrExpression(string $prefix): bool
    {
        return TagScanner::isInsideStringOrExpression($prefix);
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
