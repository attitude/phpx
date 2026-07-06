<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\HoverProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('HoverProvider', function () {
    beforeEach(function () {
        $this->provider = new HoverProvider();
    });

    describe('attribute hover', function () {
        it('provides hover info for className attribute', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div className="test">');
            $hover = $this->provider->hover($doc, 0, 7);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['kind'])->toBe('markdown');
            expect($hover['contents']['value'])->toContain('className');
            expect($hover['contents']['value'])->toContain('class');
            expect($hover)->toHaveKey('range');
        });

        it('provides hover info for htmlFor attribute', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<label htmlFor="name">');
            $hover = $this->provider->hover($doc, 0, 10);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('htmlFor');
            expect($hover['contents']['value'])->toContain('for');
        });

        it('provides hover info for style attribute', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div style={[]}>');
            // Position cursor on 'style' (starts at 5)
            $hover = $this->provider->hover($doc, 0, 7);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('style');
        });

        it('provides hover info for dangerouslySetInnerHTML', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div dangerouslySetInnerHTML={$html}>');
            $hover = $this->provider->hover($doc, 0, 10);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('dangerouslySetInnerHTML');
        });
    });

    describe('tag name hover', function () {
        it('provides hover for HTML element tag name', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>hello</div>');
            $hover = $this->provider->hover($doc, 0, 2);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('HTML Element');
            expect($hover['contents']['value'])->toContain('div');
        });

        it('provides hover for PHPX component (uppercase tag)', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<MyComponent />');
            $hover = $this->provider->hover($doc, 0, 5);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('PHPX Component');
            expect($hover['contents']['value'])->toContain('MyComponent');
        });

        it('provides hover for closing tag name', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>hello</div>');
            $hover = $this->provider->hover($doc, 0, 13);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('div');
        });

        it('provides hover for hyphenated custom element tag names', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<my-component>text</my-component>');
            $hover = $this->provider->hover($doc, 0, 1); // cursor on 'm' of 'my-component'

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('my-component');
            expect($hover['contents']['value'])->toContain('HTML Element');
        });

        it('provides hover for hyphenated tag when cursor is after hyphen', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<my-component />');
            $hover = $this->provider->hover($doc, 0, 5); // cursor on 'o' of 'component'

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('my-component');
        });
    });

    describe('fragment hover', function () {
        it('provides hover for opening fragment at position 0', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<>content</>');
            $hover = $this->provider->hover($doc, 0, 0); // cursor on '<' of '<>'

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('Fragment');
            expect($hover['range']['start']['character'])->toBe(0);
            expect($hover['range']['end']['character'])->toBe(2);
        });

        it('provides hover for opening fragment at position 1', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<>content</>');
            $hover = $this->provider->hover($doc, 0, 1); // cursor on '>' of '<>'

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('Fragment');
        });

        it('provides hover for closing fragment', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<>content</>');
            $hover = $this->provider->hover($doc, 0, 9); // cursor on '<' of '</>'

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('Fragment');
            expect($hover['range']['start']['character'])->toBe(9);
            expect($hover['range']['end']['character'])->toBe(12);
        });

        it('returns null between fragment delimiters', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<>content</>');
            $hover = $this->provider->hover($doc, 0, 5); // cursor on 'e' in 'content'

            // 'e' is a word char but not a known attribute or tag — returns null
            expect($hover)->toBeNull();
        });

        it('handles multiple fragments on the same line', function () {
            // '<>a</><>b</>' — character positions:
            //  0123456789...
            //  <>a</><>b</>
            //  Second <> starts at position 6
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<>a</><>b</>');
            $hover = $this->provider->hover($doc, 0, 6);

            expect($hover)->not->toBeNull();
            expect($hover['contents']['value'])->toContain('Fragment');
            expect($hover['range']['start']['character'])->toBe(6);
            expect($hover['range']['end']['character'])->toBe(8);
        });
    });

    describe('edge cases', function () {
        it('returns null for out-of-range line', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>');
            $hover = $this->provider->hover($doc, 99, 0);

            expect($hover)->toBeNull();
        });

        it('returns null for plain text', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, 'Hello world');
            $hover = $this->provider->hover($doc, 0, 3);

            expect($hover)->toBeNull();
        });

        it('returns null for position on whitespace', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>  </div>');
            $hover = $this->provider->hover($doc, 0, 6);

            expect($hover)->toBeNull();
        });

        it('returns a range with the hover result', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div className="x">');
            $hover = $this->provider->hover($doc, 0, 7);

            expect($hover)->not->toBeNull();
            expect($hover['range']['start'])->toHaveKey('line');
            expect($hover['range']['start'])->toHaveKey('character');
            expect($hover['range']['end'])->toHaveKey('line');
            expect($hover['range']['end'])->toHaveKey('character');
        });

        it('does not show attribute hover for a matching word in text content', function () {
            // `className` here is body text, not an attribute — no tag context,
            // so no attribute hover should be produced.
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>className here</div>');
            $hover = $this->provider->hover($doc, 0, 7);

            expect($hover)->toBeNull();
        });
    });
});
