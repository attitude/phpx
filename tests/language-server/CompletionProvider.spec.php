<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\CompletionProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('CompletionProvider', function () {
    beforeEach(function () {
        $this->provider = new CompletionProvider();
    });

    describe('tag name completion', function () {
        it('completes HTML element names after <', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<d');
            $items = $this->provider->complete($doc, 0, 2);

            $labels = array_column($items, 'label');

            expect($labels)->toContain('div');
            expect($labels)->toContain('details');
            expect($labels)->toContain('dialog');
        });

        it('includes void elements in tag completion', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<i');
            $items = $this->provider->complete($doc, 0, 2);

            $labels = array_column($items, 'label');

            expect($labels)->toContain('img');
            expect($labels)->toContain('input');
        });

        it('returns all elements when prefix is just <', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<');
            $items = $this->provider->complete($doc, 0, 1);

            expect(count($items))->toBeGreaterThan(10);
        });

        it('includes a fragment snippet when prefix is <', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<');
            $items = $this->provider->complete($doc, 0, 1);

            $labels = array_column($items, 'label');

            expect($labels)->toContain('<>...</>');
        });

        it('provides snippet insertText for elements', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<di');
            $items = $this->provider->complete($doc, 0, 3);

            $divItem = null;
            foreach ($items as $item) {
                if ($item['label'] === 'div') {
                    $divItem = $item;
                    break;
                }
            }

            expect($divItem)->not->toBeNull();
            expect($divItem['insertTextFormat'])->toBe(2); // Snippet format
            expect($divItem['insertText'])->toContain('div');
            expect($divItem['insertText'])->toContain('</div>');
        });

        it('provides self-closing insertText for void elements', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<br');
            $items = $this->provider->complete($doc, 0, 3);

            $brItem = null;
            foreach ($items as $item) {
                if ($item['label'] === 'br') {
                    $brItem = $item;
                    break;
                }
            }

            expect($brItem)->not->toBeNull();
            expect($brItem['insertText'])->toContain('/>');
            expect($brItem['detail'])->toBe('HTML void element');
        });
    });

    describe('close tag completion', function () {
        it('suggests closing tag for unclosed element', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<div>\n</");
            $items = $this->provider->complete($doc, 1, 2);

            expect($items)->toHaveCount(1);
            expect($items[0]['label'])->toBe('div>');
            expect($items[0]['insertText'])->toBe('div>');
        });

        it('returns empty when no unclosed tags exist', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<div></div>\n</");
            $items = $this->provider->complete($doc, 1, 2);

            expect($items)->toBeEmpty();
        });
    });

    describe('attribute completion', function () {
        it('completes attributes inside a tag', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div c');
            $items = $this->provider->complete($doc, 0, 6);

            $labels = array_column($items, 'label');

            expect($labels)->toContain('className');
        });

        it('completes all attributes when prefix is empty', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $items = $this->provider->complete($doc, 0, 5);

            $labels = array_column($items, 'label');

            expect($labels)->toContain('className');
            expect($labels)->toContain('htmlFor');
            expect($labels)->toContain('style');
            expect($labels)->toContain('id');
        });

        it('provides property kind for attribute items', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div c');
            $items = $this->provider->complete($doc, 0, 6);

            foreach ($items as $item) {
                expect($item['kind'])->toBe(10); // KIND_PROPERTY
            }
        });

        it('inserts valid PHPX attribute syntax (quoted string or expression)', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div ');
            $items = $this->provider->complete($doc, 0, 5);

            foreach ($items as $item) {
                $insert = $item['insertText'];
                // Every attribute snippet must use ="…" or ={…} — never bare =value
                expect($insert)->toMatch('/="\$1"$|=\{\$1\}$/');
            }
        });
    });

    describe('string/expression context suppression', function () {
        it('returns empty when < is inside a double-quoted attribute value', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div data="<');
            $items = $this->provider->complete($doc, 0, 12);

            expect($items)->toBeEmpty();
        });

        it('returns empty when < is inside a single-quoted attribute value', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<div data='<");
            $items = $this->provider->complete($doc, 0, 12);

            expect($items)->toBeEmpty();
        });

        it('returns empty when < is inside a backtick template literal', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div data={`<');
            $items = $this->provider->complete($doc, 0, 13);

            expect($items)->toBeEmpty();
        });

        it('returns empty when < is inside a {…} expression container', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>{$x<');
            $items = $this->provider->complete($doc, 0, 9);

            expect($items)->toBeEmpty();
        });
    });

    describe('edge cases', function () {
        it('returns empty for out-of-range line', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>');
            $items = $this->provider->complete($doc, 99, 0);

            expect($items)->toBeEmpty();
        });

        it('returns empty outside of tag context', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, 'Hello world');
            $items = $this->provider->complete($doc, 0, 5);

            expect($items)->toBeEmpty();
        });
    });
});
