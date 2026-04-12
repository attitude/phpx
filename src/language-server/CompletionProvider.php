<?php declare(strict_types=1);

namespace Attitude\PHPX\LanguageServer;

final class CompletionProvider
{
    // LSP CompletionItemKind
    private const KIND_PROPERTY = 10;
    private const KIND_VALUE = 12;
    private const KIND_KEYWORD = 14;
    private const KIND_SNIPPET = 15;

    /** HTML void elements (self-closing) */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Common HTML elements */
    private const HTML_ELEMENTS = [
        'a', 'abbr', 'address', 'article', 'aside', 'audio',
        'b', 'blockquote', 'body', 'button',
        'canvas', 'caption', 'cite', 'code',
        'dd', 'details', 'dialog', 'div', 'dl', 'dt',
        'em',
        'fieldset', 'figcaption', 'figure', 'footer', 'form',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'head', 'header', 'html',
        'i', 'iframe',
        'kbd',
        'label', 'legend', 'li',
        'main', 'mark',
        'nav',
        'ol', 'option', 'output',
        'p', 'picture', 'pre', 'progress',
        'q',
        's', 'samp', 'script', 'section', 'select', 'slot', 'small', 'span', 'strong', 'style', 'sub', 'summary', 'sup',
        'table', 'tbody', 'td', 'template', 'textarea', 'tfoot', 'th', 'thead', 'time', 'title', 'tr',
        'u', 'ul',
        'var', 'video',
    ];

    /** PHPX/JSX-style attribute names mapped to descriptions */
    private const COMMON_ATTRIBUTES = [
        'className' => 'CSS class name (maps to HTML class)',
        'htmlFor' => 'Label association (maps to HTML for)',
        'style' => 'Inline styles as array',
        'id' => 'Element identifier',
        'key' => 'Unique key for list rendering',
        'dangerouslySetInnerHTML' => 'Raw HTML injection',
        'onClick' => 'Click event handler',
        'onChange' => 'Change event handler',
        'onSubmit' => 'Form submit handler',
        'disabled' => 'Disable element',
        'hidden' => 'Hide element',
        'tabIndex' => 'Tab order (maps to tabindex)',
        'autoFocus' => 'Auto-focus on mount (maps to autofocus)',
        'placeholder' => 'Placeholder text',
        'type' => 'Input type',
        'value' => 'Input value',
        'name' => 'Form element name',
        'href' => 'Link URL',
        'src' => 'Source URL',
        'alt' => 'Alternative text',
        'role' => 'ARIA role',
        'data' => 'Data attribute namespace',
        'aria' => 'ARIA attribute namespace',
    ];

    public function complete(TextDocumentItem $document, int $line, int $character): array
    {
        $lineText = $document->getLine($line);

        if ($lineText === null) {
            return [];
        }

        $prefix = substr($lineText, 0, $character);
        $items = [];

        // Tag name completion after < — skip if inside a quoted string or {…} expression
        if (!$this->isInsideStringOrExpression($prefix) && preg_match('/<([\w-]*)$/', $prefix, $matches)) {
            $partial = $matches[1];
            $items = array_merge(
                $items,
                $this->completeTagName($partial),
            );
        }

        // Close tag completion after </ — same guard
        elseif (!$this->isInsideStringOrExpression($prefix) && preg_match('/<\/([\w-]*)$/', $prefix, $matches)) {
            $partial = $matches[1];
            $items = array_merge(
                $items,
                $this->completeCloseTag($document, $line, $character, $partial),
            );
        }

        // Attribute name completion inside a tag (may span multiple lines)
        elseif ($this->isInsideTag($document, $line, $character) && preg_match('/\s(\w*)$/', $prefix, $matches)) {
            $partial = $matches[1];
            $items = array_merge(
                $items,
                $this->completeAttribute($partial),
            );
        }

        return $items;
    }

    private function completeTagName(string $partial): array
    {
        $items = [];
        $allElements = array_merge(self::HTML_ELEMENTS, self::VOID_ELEMENTS);
        $allElements = array_unique($allElements);
        sort($allElements);

        foreach ($allElements as $tag) {
            if ($partial === '' || str_starts_with($tag, strtolower($partial))) {
                $isVoid = in_array($tag, self::VOID_ELEMENTS, true);
                $items[] = [
                    'label' => $tag,
                    'kind' => self::KIND_KEYWORD,
                    'detail' => $isVoid ? 'HTML void element' : 'HTML element',
                    'insertText' => $isVoid ? "{$tag} />" : "{$tag}>$1</{$tag}>",
                    'insertTextFormat' => 2, // Snippet
                ];
            }
        }

        // Fragment completion
        if ($partial === '') {
            $items[] = [
                'label' => '<>...</>',
                'kind' => self::KIND_SNIPPET,
                'detail' => 'PHPX Fragment',
                'insertText' => ">$1</>",
                'insertTextFormat' => 2,
            ];
        }

        return $items;
    }

    private function completeCloseTag(TextDocumentItem $document, int $currentLine, int $currentCharacter, string $partial): array
    {
        $tag = $this->findUnclosedTag($document, $currentLine, $currentCharacter);

        if ($tag === null) {
            return [];
        }

        if ($partial !== '' && !str_starts_with($tag, $partial)) {
            return [];
        }

        return [
            [
                'label' => "{$tag}>",
                'kind' => self::KIND_KEYWORD,
                'detail' => "Close <{$tag}> tag",
                'insertText' => "{$tag}>",
            ],
        ];
    }

    /** Attributes that typically take expression values {…} rather than string "…" */
    private const EXPRESSION_ATTRIBUTES = [
        'style', 'data', 'aria', 'key', 'dangerouslySetInnerHTML',
        'onClick', 'onChange', 'onSubmit',
        'tabIndex',
    ];

    private function completeAttribute(string $partial): array
    {
        $items = [];

        foreach (self::COMMON_ATTRIBUTES as $attr => $description) {
            if ($partial === '' || str_starts_with(strtolower($attr), strtolower($partial))) {
                // Use {…} for expression attributes, "…" for string attributes
                $isExpression = in_array($attr, self::EXPRESSION_ATTRIBUTES, true);
                $valuePlaceholder = $isExpression ? '={$1}' : '="$1"';

                $items[] = [
                    'label' => $attr,
                    'kind' => self::KIND_PROPERTY,
                    'detail' => $description,
                    'insertText' => $attr . $valuePlaceholder,
                    'insertTextFormat' => 2,
                ];
            }
        }

        return $items;
    }

    /**
     * Check if the cursor is inside a quoted string ("…" or '…') or a {…}
     * expression container. Handles backslash-escaped quote characters.
     * Used to suppress tag-name and close-tag completions when the `<` is
     * inside attribute string content rather than markup.
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

    /**
     * Check if the cursor is inside an opening tag's attribute area.
     * Analyzes all document text up to the cursor position (not just the
     * current line) to handle multi-line opening tags like:
     *   <div
     *     className="x"
     *     |  ← cursor here should trigger attribute completion
     */
    private function isInsideTag(TextDocumentItem $document, int $line, int $character): bool
    {
        // Build source from document start up to the cursor
        $lines = $document->getLines();
        $source = implode("\n", array_slice($lines, 0, $line)) . "\n" . substr($lines[$line] ?? '', 0, $character);

        try {
            // Ensure TokensList.php is loaded — its file-scope TX_* constants
            // are needed by the tokenizer but won't autoload on their own.
            class_exists(\Attitude\PHPX\Parser\TokensList::class);
            $tokens = \Attitude\PHPX\Parser\Token::tokenize($source);
        } catch (\Throwable) {
            // Fallback: simple heuristic for mid-typing states
            $lastOpen = strrpos($source, '<');
            $lastClose = strrpos($source, '>');
            return $lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose);
        }

        // Walk tokens: track whether we're inside an opening tag
        $insideTag = false;
        foreach ($tokens as $token) {
            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                $insideTag = true;
            } elseif ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_CLOSE) {
                $insideTag = false;
            }
        }

        return $insideTag;
    }

    /**
     * Find the most recent unclosed tag in the document.
     * Uses the PHPX tokenizer to avoid false matches on tag-like text
     * inside attribute strings or expression containers.
     */
    private function findUnclosedTag(TextDocumentItem $document, int $upToLine, int $upToCharacter): ?string
    {
        // Build source up to the exact cursor position (not the full line)
        // to avoid being influenced by tags after the cursor on the same line
        $lines = $document->getLines();
        $source = implode("\n", array_slice($lines, 0, $upToLine));
        if (isset($lines[$upToLine])) {
            $source .= ($upToLine > 0 ? "\n" : '') . substr($lines[$upToLine], 0, $upToCharacter);
        }

        return TagScanner::findUnclosedTag($source);
    }
}
