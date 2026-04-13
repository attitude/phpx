<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\CompletionProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('CompletionProvider validity', function () {
    beforeEach(function () {
        $this->provider = new CompletionProvider();
    });

    // Valid LSP CompletionItemKind values (subset the provider uses)
    $validKinds = [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25];

    /**
     * Helper: assert that every completion item has valid LSP shape.
     */
    $assertValidItems = function (array $items, string $context) use ($validKinds): void {
        foreach ($items as $i => $item) {
            // Required: label must be a non-empty string
            expect($item)->toHaveKeys(['label', 'kind']);
            expect($item['label'])->toBeString();
            expect(strlen($item['label']))->toBeGreaterThan(0);

            // Required: kind must be a valid CompletionItemKind
            expect($item['kind'])->toBeInt();
            expect($validKinds)->toContain($item['kind']);

            // If insertText is present, it must be a non-empty string
            if (isset($item['insertText'])) {
                expect($item['insertText'])->toBeString();
                expect(strlen($item['insertText']))->toBeGreaterThan(0);
            }

            // If insertTextFormat is 2 (Snippet), insertText must either contain
            // snippet placeholders ($1, ${1:default}) or be a self-closing void element
            if (isset($item['insertTextFormat']) && $item['insertTextFormat'] === 2) {
                expect($item)->toHaveKey('insertText');
                $hasPlaceholder = (bool) preg_match('/\$\d|\$\{\d/', $item['insertText']);
                $isSelfClosing = str_contains($item['insertText'], '/>');
                expect($hasPlaceholder || $isSelfClosing)->toBeTrue();
            }
        }
    };

    // ------------------------------------------------------------------
    // Tag completions (after <)
    // ------------------------------------------------------------------

    describe('tag completions', function () use ($assertValidItems) {
        it('produces valid LSP items for every tag completion', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<');
            $items = $this->provider->complete($doc, 0, 1);

            expect(count($items))->toBeGreaterThan(0);
            $assertValidItems($items, 'tag completion after <');
        });

        it('produces self-closing insertText for void elements', function () {
            $voidElements = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<');
            $items = $this->provider->complete($doc, 0, 1);

            $itemsByLabel = [];
            foreach ($items as $item) {
                $itemsByLabel[$item['label']] = $item;
            }

            foreach ($voidElements as $tag) {
                if (!isset($itemsByLabel[$tag])) {
                    continue; // Not all void elements may be in the completion list
                }

                $item = $itemsByLabel[$tag];
                expect($item['insertText'])->toContain('/>');
                expect($item['insertText'])->not->toContain("</{$tag}>");
            }
        });

        it('produces paired opening/closing tags for regular elements', function () {
            $regularElements = ['div', 'span', 'p', 'section', 'article', 'h1', 'ul', 'li', 'a', 'button'];
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<');
            $items = $this->provider->complete($doc, 0, 1);

            $itemsByLabel = [];
            foreach ($items as $item) {
                $itemsByLabel[$item['label']] = $item;
            }

            foreach ($regularElements as $tag) {
                expect($itemsByLabel)->toHaveKey($tag);
                $item = $itemsByLabel[$tag];
                expect($item['insertText'])->toContain("</{$tag}>");
            }
        });

        it('never produces bare tag names without closing syntax', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<');
            $items = $this->provider->complete($doc, 0, 1);

            foreach ($items as $item) {
                if (!isset($item['insertText'])) {
                    continue;
                }
                $insert = $item['insertText'];
                // Must contain either /> (self-closing) or > with a closing tag
                $hasSelfClose = str_contains($insert, '/>');
                $hasCloseTag = preg_match('/<\/[\w-]+>/', $insert);
                $hasClosingAngle = str_contains($insert, '>');

                expect($hasSelfClose || $hasCloseTag || $hasClosingAngle)->toBeTrue();
            }
        });

        it('filters tag completions by partial prefix', function () use ($assertValidItems) {
            $prefixes = ['<d', '<di', '<div', '<s', '<sp', '<h', '<h1'];

            foreach ($prefixes as $prefix) {
                $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $prefix);
                $items = $this->provider->complete($doc, 0, strlen($prefix));

                $assertValidItems($items, "tag completion after '{$prefix}'");

                // Every returned label must start with the typed partial
                $partial = substr($prefix, 1); // strip leading <
                foreach ($items as $item) {
                    if ($item['label'] === '<>...</>') {
                        continue; // fragment snippet
                    }
                    expect(str_starts_with($item['label'], $partial))->toBeTrue();
                }
            }
        });
    });

    // ------------------------------------------------------------------
    // Close tag completions (after </)
    // ------------------------------------------------------------------

    describe('close tag completions', function () use ($assertValidItems) {
        it('only suggests tags that are actually unclosed', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<div><span>\n</");
            $items = $this->provider->complete($doc, 1, 2);

            $assertValidItems($items, 'close tag after </');

            if (count($items) > 0) {
                // The suggested tag must be one that is open (span is the innermost unclosed)
                $labels = array_column($items, 'label');
                expect($labels)->toContain('span>');
            }
        });

        it('includes closing > in close tag insertText', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<section>\n</");
            $items = $this->provider->complete($doc, 1, 2);

            $assertValidItems($items, 'close tag >');

            foreach ($items as $item) {
                expect($item['insertText'])->toMatch('/\w+>$/');
            }
        });

        it('returns empty when all tags are already closed', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<div></div>\n</");
            $items = $this->provider->complete($doc, 1, 2);

            expect($items)->toBeEmpty();
        });
    });

    // ------------------------------------------------------------------
    // Attribute completions
    // ------------------------------------------------------------------

    describe('attribute completions', function () use ($assertValidItems) {
        it('produces valid LSP items for attribute completions', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $items = $this->provider->complete($doc, 0, 5);

            expect(count($items))->toBeGreaterThan(0);
            $assertValidItems($items, 'attribute completion');
        });

        it('uses quoted string syntax for string attributes', function () {
            $stringAttributes = ['className', 'id', 'role', 'title', 'lang', 'slot'];
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $items = $this->provider->complete($doc, 0, 5);

            $itemsByLabel = [];
            foreach ($items as $item) {
                $itemsByLabel[$item['label']] = $item;
            }

            foreach ($stringAttributes as $attr) {
                if (!isset($itemsByLabel[$attr])) {
                    continue;
                }
                $insert = $itemsByLabel[$attr]['insertText'];
                expect($insert)->toContain('="');
                expect($insert)->not->toContain('={');
            }
        });

        it('uses expression syntax for expression attributes', function () {
            $expressionAttributes = ['style', 'onClick', 'onChange', 'onSubmit', 'key', 'dangerouslySetInnerHTML', 'tabIndex'];
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $items = $this->provider->complete($doc, 0, 5);

            $itemsByLabel = [];
            foreach ($items as $item) {
                $itemsByLabel[$item['label']] = $item;
            }

            foreach ($expressionAttributes as $attr) {
                if (!isset($itemsByLabel[$attr])) {
                    continue;
                }
                $insert = $itemsByLabel[$attr]['insertText'];
                expect($insert)->toContain('={');
            }
        });

        it('never produces bare attr=$1 syntax', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $items = $this->provider->complete($doc, 0, 5);

            foreach ($items as $item) {
                $insert = $item['insertText'];
                $isSnippet = isset($item['insertTextFormat']) && $item['insertTextFormat'] === 2;

                if ($isSnippet) {
                    // Snippet attributes must use ="..." or ={...}, never bare =value
                    expect($insert)->toMatch('/="\$1"$|=\{\$1\}$/');
                } else {
                    // Boolean attributes: bare name, no = sign
                    expect($insert)->not->toContain('=');
                }
            }
        });
    });

    // ------------------------------------------------------------------
    // Edge cases that must not crash
    // ------------------------------------------------------------------

    describe('edge cases', function () use ($assertValidItems) {
        it('does not crash on completion at every position in a document', function () use ($assertValidItems) {
            $text = '<div className="test">Hello</div>';
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $text);

            for ($col = 0; $col <= strlen($text); $col++) {
                $items = $this->provider->complete($doc, 0, $col);

                expect($items)->toBeArray();
                $assertValidItems($items, "position {$col}");
            }
        });

        it('does not crash on empty document', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '');
            $items = $this->provider->complete($doc, 0, 0);

            expect($items)->toBeArray();
            $assertValidItems($items, 'empty document');
        });

        it('does not crash on whitespace-only lines', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '   ');
            $items = $this->provider->complete($doc, 0, 3);

            expect($items)->toBeArray();
            $assertValidItems($items, 'whitespace-only');
        });

        it('does not crash when position is beyond line length', function () use ($assertValidItems) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>');
            $items = $this->provider->complete($doc, 0, 100);

            expect($items)->toBeArray();
            $assertValidItems($items, 'position beyond line');
        });

        it('does not crash on multi-line documents at various positions', function () use ($assertValidItems) {
            $text = "<div>\n  <span className=\"test\">\n    Hello\n  </span>\n</div>";
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $text);
            $lines = explode("\n", $text);

            for ($line = 0; $line < count($lines); $line++) {
                $lineLen = strlen($lines[$line]);
                // Test at start, middle, and end of each line
                $positions = [0, intdiv($lineLen, 2), $lineLen];

                foreach ($positions as $col) {
                    $items = $this->provider->complete($doc, $line, $col);

                    expect($items)->toBeArray();
                    $assertValidItems($items, "line {$line}, col {$col}");
                }
            }
        });

        it('does not crash on line beyond document', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>');
            $items = $this->provider->complete($doc, 99, 0);

            expect($items)->toBeArray();
        });

        it('returns consistent results on repeated calls with same input', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $first = $this->provider->complete($doc, 0, 5);
            $second = $this->provider->complete($doc, 0, 5);

            expect($first)->toBe($second);
        });

        it('provides attribute completions inside multi-line opening tags', function () {
            // Tag opened on line 0, attributes continue on line 1
            $text = "<div\n  cla";
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $text);
            // Cursor at end of line 1 (position 5 = "  cla" length)
            $items = $this->provider->complete($doc, 1, 5);

            expect($items)->not->toBeEmpty();
            $labels = array_column($items, 'label');
            expect($labels)->toContain('className');
        });

        it('provides attribute completions on blank line inside multi-line tag', function () {
            $text = "<div\n  className=\"test\"\n  ";
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $text);
            // Cursor at end of line 2
            $items = $this->provider->complete($doc, 2, 2);

            expect($items)->not->toBeEmpty();
        });
    });
});
