<?php declare(strict_types=1);

use Attitude\PHPX\LanguageServer\RenameProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('RenameProvider', function () {
    beforeEach(function () {
        $this->provider = new RenameProvider();
    });

    describe('prepareRename', function () {
        it('returns range and placeholder for an opening tag name', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 0, 2); // cursor on "div" in <div>

            expect($result)->not->toBeNull();
            expect($result['placeholder'])->toBe('div');
            expect($result['range']['start']['character'])->toBe(1);
            expect($result['range']['end']['character'])->toBe(4);
        });

        it('returns range and placeholder for a closing tag name', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 0, 12); // cursor on "div" in </div>

            expect($result)->not->toBeNull();
            expect($result['placeholder'])->toBe('div');
        });

        it('returns null when cursor is not on a tag name', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 0, 6); // cursor on "Hello"

            expect($result)->toBeNull();
        });

        it('returns null for out-of-range line', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->prepareRename($doc, 5, 0);

            expect($result)->toBeNull();
        });

        it('works with multi-line documents', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "<ol>\n  <li>item</li>\n</ol>");
            $result = $this->provider->prepareRename($doc, 0, 1); // cursor on "ol"

            expect($result)->not->toBeNull();
            expect($result['placeholder'])->toBe('ol');
        });
    });

    describe('rename', function () {
        it('renames both opening and closing tags', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<ol>content</ol>');
            $result = $this->provider->rename($doc, 0, 2, 'ul'); // cursor on "ol" in <ol>

            expect($result)->not->toBeNull();
            $edits = $result['changes']['file:///test.phpx'];
            expect($edits)->toHaveCount(2);

            // Opening tag edit
            expect($edits[0]['newText'])->toBe('ul');
            // Closing tag edit
            expect($edits[1]['newText'])->toBe('ul');
        });

        it('renames from the closing tag side', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>content</div>');
            $result = $this->provider->rename($doc, 0, 15, 'span'); // cursor on "div" in </div>

            expect($result)->not->toBeNull();
            $edits = $result['changes']['file:///test.phpx'];
            expect($edits)->toHaveCount(2);
            expect($edits[0]['newText'])->toBe('span');
            expect($edits[1]['newText'])->toBe('span');
        });

        it('renames tags across multiple lines', function () {
            $text = "<ol>\n  <li>item</li>\n</ol>";
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $text);
            $result = $this->provider->rename($doc, 0, 1, 'ul'); // cursor on "ol" on line 0

            expect($result)->not->toBeNull();
            $edits = $result['changes']['file:///test.phpx'];
            expect($edits)->toHaveCount(2);

            // Opening tag on line 0
            expect($edits[0]['range']['start']['line'])->toBe(0);
            expect($edits[0]['newText'])->toBe('ul');

            // Closing tag on line 2
            expect($edits[1]['range']['start']['line'])->toBe(2);
            expect($edits[1]['newText'])->toBe('ul');
        });

        it('handles self-closing tags with a single edit', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<br />');
            $result = $this->provider->rename($doc, 0, 1, 'hr'); // cursor on "br"

            expect($result)->not->toBeNull();
            $edits = $result['changes']['file:///test.phpx'];
            expect($edits)->toHaveCount(1);
            expect($edits[0]['newText'])->toBe('hr');
        });

        it('returns null when cursor is not on a tag', function () {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');
            $result = $this->provider->rename($doc, 0, 6, 'span');

            expect($result)->toBeNull();
        });

        it('renames the correct pair in nested same-name tags', function () {
            $text = "<div>\n  <div>inner</div>\n</div>";
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $text);
            // Rename the outer <div> (line 0)
            $result = $this->provider->rename($doc, 0, 1, 'section');

            expect($result)->not->toBeNull();
            $edits = $result['changes']['file:///test.phpx'];
            expect($edits)->toHaveCount(2);

            // Opening: line 0
            expect($edits[0]['range']['start']['line'])->toBe(0);
            // Closing: line 2 (the outer </div>)
            expect($edits[1]['range']['start']['line'])->toBe(2);
        });
    });
});
