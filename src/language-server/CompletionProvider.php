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

    /** Attributes that typically take expression values {…} rather than string "…" */
    private const EXPRESSION_ATTRIBUTES = [
        'style', 'data', 'aria', 'key', 'dangerouslySetInnerHTML',
        'onClick', 'onDoubleClick', 'onChange', 'onInput', 'onSubmit',
        'onReset', 'onFocus', 'onBlur', 'onKeyDown', 'onKeyUp',
        'onMouseEnter', 'onMouseLeave', 'onMouseOver', 'onMouseOut',
        'onMouseDown', 'onMouseUp', 'onScroll', 'onWheel',
        'onDrag', 'onDragStart', 'onDragEnd', 'onDragOver', 'onDrop',
        'onCopy', 'onCut', 'onPaste', 'onLoad', 'onError',
        'tabIndex',
    ];

    /** Boolean attributes that don't need a value */
    private const BOOLEAN_ATTRIBUTES = [
        'disabled', 'hidden', 'checked', 'readOnly', 'required',
        'multiple', 'autoFocus', 'autoPlay', 'controls', 'loop',
        'muted', 'open', 'reversed', 'selected', 'async', 'defer',
        'noValidate', 'noModule', 'formNoValidate', 'allowFullScreen',
        'inert', 'playsInline', 'isMap', 'default',
        'draggable', 'spellCheck', 'contentEditable',
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
        elseif (preg_match('/\s(\w*)$/', $prefix, $matches)) {
            $tagContext = $this->getTagContext($document, $line, $character);
            if ($tagContext !== null) {
                $partial = $matches[1];
                $items = array_merge(
                    $items,
                    $this->completeAttribute($partial, $tagContext),
                );
            }
        }

        return $items;
    }

    private function completeTagName(string $partial): array
    {
        static $allElements = null;
        if ($allElements === null) {
            $allElements = array_unique(array_merge(self::HTML_ELEMENTS, self::VOID_ELEMENTS));
            sort($allElements);
        }

        $items = [];

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

    /**
     * Complete attributes for the given tag, using per-element attribute data.
     */
    private function completeAttribute(string $partial, string $tagName): array
    {
        $items = [];
        $attributes = HTMLAttributes::forElement($tagName);

        foreach ($attributes as $attr => [$type, $description]) {
            if ($partial === '' || str_starts_with(strtolower($attr), strtolower($partial))) {
                $isExpression = in_array($attr, self::EXPRESSION_ATTRIBUTES, true);
                $isBoolean = in_array($attr, self::BOOLEAN_ATTRIBUTES, true);

                if ($isExpression) {
                    $valuePlaceholder = '={$1}';
                } elseif ($isBoolean) {
                    // Boolean attributes: insert just the name (no value needed in JSX)
                    $items[] = [
                        'label' => $attr,
                        'kind' => self::KIND_PROPERTY,
                        'detail' => $type,
                        'documentation' => $description,
                        'insertText' => $attr,
                    ];
                    continue;
                } else {
                    $valuePlaceholder = '="$1"';
                }

                $items[] = [
                    'label' => $attr,
                    'kind' => self::KIND_PROPERTY,
                    'detail' => $type,
                    'documentation' => $description,
                    'insertText' => $attr . $valuePlaceholder,
                    'insertTextFormat' => 2,
                ];
            }
        }

        return $items;
    }

    private function isInsideStringOrExpression(string $prefix): bool
    {
        return TagScanner::isInsideStringOrExpression($prefix);
    }

    /**
     * Get the tag name the cursor is inside, or null if not inside a tag.
     *
     * Analyzes all document text up to the cursor position (not just the
     * current line) to handle multi-line opening tags like:
     *   <div
     *     className="x"
     *     |  ← cursor here should trigger attribute completion
     */
    private function getTagContext(TextDocumentItem $document, int $line, int $character): ?string
    {
        $lines = $document->getLines();
        $source = implode("\n", array_slice($lines, 0, $line)) . "\n" . substr($lines[$line] ?? '', 0, $character);

        try {
            class_exists(\Attitude\PHPX\Parser\TokensList::class);
            $tokens = \Attitude\PHPX\Parser\Token::tokenize($source);
        } catch (\Throwable) {
            // Fallback: simple heuristic for mid-typing states
            $lastOpen = strrpos($source, '<');
            $lastClose = strrpos($source, '>');

            if ($lastOpen === false || ($lastClose !== false && $lastClose > $lastOpen)) {
                return null;
            }

            // Extract tag name after the last '<'
            $after = substr($source, $lastOpen + 1);
            if (preg_match('/^([a-zA-Z][a-zA-Z0-9-]*)/', $after, $m)) {
                return strtolower($m[1]);
            }

            return null;
        }

        // Walk tokens: track whether we're inside an opening tag and which tag
        $insideTag = false;
        $currentTag = null;

        foreach ($tokens as $i => $token) {
            if ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_OPEN) {
                $insideTag = true;
                // The next T_STRING token is the tag name
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && $next->id === T_STRING) {
                    $currentTag = $next->text;
                } else {
                    $currentTag = null;
                }
            } elseif ($token->id === \Attitude\PHPX\Parser\TX_ELEMENT_OPENING_CLOSE) {
                $insideTag = false;
                $currentTag = null;
            }
        }

        return $insideTag ? strtolower($currentTag ?? '') : null;
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
