<?php declare(strict_types=1);

use Attitude\PHPX\LanguageServer\RenameProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;


describe('Rename Contracts', function () {
    beforeEach(function () {
        $this->provider = new RenameProvider();
        $this->uri = 'file:///contract-test.phpx';
    });

    describe('rename produces valid paired tags', function () {
        it('renames <ol>...</ol> to <ul>...</ul> via applied edits', function () {
            $text = '<ol><li>Item</li></ol>';
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'ul');

            expect($result)->not->toBeNull();
            $edits = $result['changes'][$this->uri];
            $applied = applyEdits($text, $edits);

            expect($applied)->toBe('<ul><li>Item</li></ul>');
        });

        it('renames <div>...</div> to <section>...</section>', function () {
            $text = '<div>content</div>';
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 2, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toBe('<section>content</section>');
        });

        it('rename from closing tag produces same result as from opening', function () {
            $text = '<div>Hello</div>';
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);

            $fromOpen = $this->provider->rename($doc, 0, 2, 'span');
            $fromClose = $this->provider->rename($doc, 0, 13, 'span');

            $appliedOpen = applyEdits($text, $fromOpen['changes'][$this->uri]);
            $appliedClose = applyEdits($text, $fromClose['changes'][$this->uri]);

            expect($appliedOpen)->toBe('<span>Hello</span>');
            expect($appliedClose)->toBe('<span>Hello</span>');
            expect($appliedOpen)->toBe($appliedClose);
        });

        it('renames custom element <my-comp> to <your-comp>', function () {
            $text = '<my-comp>content</my-comp>';
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'your-comp');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toBe('<your-comp>content</your-comp>');
        });
    });

    describe('rename preserves document structure', function () {
        it('preserves content between tags', function () {
            $text = "<div>Some <b>bold</b> text here</div>";
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toContain('Some <b>bold</b> text here');
        });

        it('preserves attributes on the opening tag', function () {
            $text = '<div className="test" id="main">content</div>';
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toBe('<section className="test" id="main">content</section>');
        });

        it('leaves other tags in the document unchanged', function () {
            $text = "<div>\n  <span>keep me</span>\n  <p>also me</p>\n</div>";
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toContain('<span>keep me</span>');
            expect($applied)->toContain('<p>also me</p>');
        });

        it('preserves indentation and whitespace', function () {
            $text = "<div>\n    <p>indented</p>\n</div>";
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toBe("<section>\n    <p>indented</p>\n</section>");
        });
    });

    describe('nested same-name tags - correct pairing', function () {
        it('renaming outer div does not touch inner div', function () {
            $text = "<div><div>inner</div></div>";
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toBe('<section><div>inner</div></section>');
        });

        it('renaming inner div does not touch outer div', function () {
            $text = "<div><div>inner</div></div>";
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            // Inner <div> starts at position 5, tag name at 6
            $result = $this->provider->rename($doc, 0, 6, 'span');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            expect($applied)->toBe('<div><span>inner</span></div>');
        });

        it('handles three levels deep correctly', function () {
            $text = "<div>\n  <div>\n    <div>x</div>\n  </div>\n</div>";
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, $text);
            // Rename the middle div (line 1, char 3)
            $result = $this->provider->rename($doc, 1, 3, 'section');

            $applied = applyEdits($text, $result['changes'][$this->uri]);
            // Outer and innermost divs remain
            expect($applied)->toContain('<div>');
            expect($applied)->toContain('<section>');
            expect($applied)->toContain('</section>');
            // The innermost div is untouched
            expect($applied)->toContain('<div>x</div>');
        });
    });

    describe('self-closing tags', function () {
        it('rename <br /> produces exactly 1 edit', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<br />');
            $result = $this->provider->rename($doc, 0, 1, 'hr');

            $edits = $result['changes'][$this->uri];
            expect($edits)->toHaveCount(1);
            expect($edits[0]['newText'])->toBe('hr');
        });

        it('rename <img /> to <input /> is a single edit', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<img src="photo.jpg" />');
            $result = $this->provider->rename($doc, 0, 1, 'input');

            $edits = $result['changes'][$this->uri];
            expect($edits)->toHaveCount(1);

            $applied = applyEdits('<img src="photo.jpg" />', $edits);
            expect($applied)->toBe('<input src="photo.jpg" />');
        });
    });

    describe('prepareRename contract', function () {
        it('returns range and placeholder when on a tag name', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 0, 2);

            expect($result)->not->toBeNull();
            expect($result)->toHaveKeys(['range', 'placeholder']);
        });

        it('placeholder equals the current tag name', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<section>Hello</section>');
            $result = $this->provider->prepareRename($doc, 0, 3);

            expect($result['placeholder'])->toBe('section');
        });

        it('range exactly covers the tag name, not < or >', function () {
            // <div>  → tag name "div" is chars 1-4
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 0, 2);

            expect($result['range']['start']['character'])->toBe(1); // after <
            expect($result['range']['end']['character'])->toBe(4);   // before >
        });

        it('returns null when cursor is on text content', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 0, 7); // on "l" in Hello

            expect($result)->toBeNull();
        });

        it('returns null when cursor is on an attribute', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div className="x">Hi</div>');
            // className starts at 5, but it's not a tag name
            $result = $this->provider->prepareRename($doc, 0, 8);

            expect($result)->toBeNull();
        });

        it('returns null when cursor is on whitespace', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '  <div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 0);

            expect($result)->toBeNull();
        });

        it('returns null for fragment <>', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<>content</>');
            // Position 0 is '<', position 1 is '>' — neither matches a tag name pattern
            $result = $this->provider->prepareRename($doc, 0, 0);
            expect($result)->toBeNull();

            $result = $this->provider->prepareRename($doc, 0, 1);
            expect($result)->toBeNull();
        });

        it('returns null for closing fragment </>', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<>content</>');
            $result = $this->provider->prepareRename($doc, 0, 9);
            expect($result)->toBeNull();

            $result = $this->provider->prepareRename($doc, 0, 10);
            expect($result)->toBeNull();
        });
    });

    describe('boundary positions', function () {
        it('cursor at first char of tag name works', function () {
            // <div> → "d" is at position 1
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 1);

            expect($result)->not->toBeNull();
            expect($result['placeholder'])->toBe('div');
        });

        it('cursor at last char of tag name works', function () {
            // <div> → "v" is at position 3, end-exclusive is 4
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 3);

            expect($result)->not->toBeNull();
            expect($result['placeholder'])->toBe('div');
        });

        it('cursor one position after tag name returns null (end-exclusive)', function () {
            // <div> → position 4 is ">", which is past the tag name
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 4);

            expect($result)->toBeNull();
        });

        it('cursor on < before tag name returns null', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 0);

            expect($result)->toBeNull();
        });

        it('cursor on > after tag name returns null', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 4);

            expect($result)->toBeNull();
        });

        it('cursor on / in </ returns null', function () {
            // </div> → "/" is at position 8 in '<div>Hi</div>'
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>Hi</div>');
            $result = $this->provider->prepareRename($doc, 0, 8);

            expect($result)->toBeNull();
        });
    });

    describe('newName validation', function () {
        it('rejects empty newName', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>text</div>');
            $result = $this->provider->rename($doc, 0, 1, '');

            expect($result)->toBeNull();
        });

        it('rejects newName starting with a digit', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>text</div>');
            $result = $this->provider->rename($doc, 0, 1, '1div');

            expect($result)->toBeNull();
        });

        it('rejects newName containing spaces', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>text</div>');
            $result = $this->provider->rename($doc, 0, 1, 'my div');

            expect($result)->toBeNull();
        });

        it('rejects newName containing special characters', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>text</div>');
            $result = $this->provider->rename($doc, 0, 1, 'div<>');

            expect($result)->toBeNull();
        });

        it('accepts valid hyphenated newName', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>text</div>');
            $result = $this->provider->rename($doc, 0, 1, 'my-component');

            expect($result)->not->toBeNull();
        });

        it('accepts valid uppercase newName', function () {
            $doc = new TextDocumentItem($this->uri, 'phpx', 1, '<div>text</div>');
            $result = $this->provider->rename($doc, 0, 1, 'MyComponent');

            expect($result)->not->toBeNull();
        });
    });
});
