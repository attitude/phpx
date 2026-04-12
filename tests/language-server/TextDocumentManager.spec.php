<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\TextDocumentManager;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('TextDocumentManager', function () {
    describe('open() and get()', function () {
        it('stores and retrieves a document', function () {
            $manager = new TextDocumentManager();
            $manager->open('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');

            $doc = $manager->get('file:///test.phpx');

            expect($doc)->toBeInstanceOf(TextDocumentItem::class);
            expect($doc->uri)->toBe('file:///test.phpx');
            expect($doc->languageId)->toBe('phpx');
            expect($doc->version)->toBe(1);
            expect($doc->text)->toBe('<div>Hello</div>');
        });

        it('returns null for unknown documents', function () {
            $manager = new TextDocumentManager();

            expect($manager->get('file:///unknown.phpx'))->toBeNull();
        });
    });

    describe('close()', function () {
        it('removes a document', function () {
            $manager = new TextDocumentManager();
            $manager->open('file:///test.phpx', 'phpx', 1, 'hello');
            $manager->close('file:///test.phpx');

            expect($manager->get('file:///test.phpx'))->toBeNull();
        });
    });

    describe('change()', function () {
        it('applies a full content change', function () {
            $manager = new TextDocumentManager();
            $manager->open('file:///test.phpx', 'phpx', 1, 'original');
            $manager->change('file:///test.phpx', 2, [
                ['text' => 'replaced'],
            ]);

            $doc = $manager->get('file:///test.phpx');

            expect($doc->version)->toBe(2);
            expect($doc->text)->toBe('replaced');
        });

        it('applies an incremental change', function () {
            $manager = new TextDocumentManager();
            $manager->open('file:///test.phpx', 'phpx', 1, "line0\nline1\nline2");
            $manager->change('file:///test.phpx', 2, [
                [
                    'range' => [
                        'start' => ['line' => 1, 'character' => 0],
                        'end' => ['line' => 1, 'character' => 5],
                    ],
                    'text' => 'REPLACED',
                ],
            ]);

            $doc = $manager->get('file:///test.phpx');

            expect($doc->text)->toBe("line0\nREPLACED\nline2");
        });

        it('ignores changes to unknown documents', function () {
            $manager = new TextDocumentManager();
            $manager->change('file:///unknown.phpx', 2, [['text' => 'new']]);

            expect($manager->get('file:///unknown.phpx'))->toBeNull();
        });

        it('applies multiple sequential changes', function () {
            $manager = new TextDocumentManager();
            $manager->open('file:///test.phpx', 'phpx', 1, "ab");
            $manager->change('file:///test.phpx', 2, [
                ['text' => 'cd'],
                ['text' => 'ef'],
            ]);

            $doc = $manager->get('file:///test.phpx');

            expect($doc->text)->toBe('ef');
        });
    });
});

describe('TextDocumentItem', function () {
    it('returns lines from text', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "line0\nline1\nline2");

        expect($doc->getLines())->toBe(['line0', 'line1', 'line2']);
    });

    it('returns a specific line by index', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "first\nsecond\nthird");

        expect($doc->getLine(0))->toBe('first');
        expect($doc->getLine(1))->toBe('second');
        expect($doc->getLine(2))->toBe('third');
    });

    it('returns null for out-of-range line index', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "only one line");

        expect($doc->getLine(5))->toBeNull();
    });

    it('counts lines correctly', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "a\nb\nc");

        expect($doc->lineCount())->toBe(3);
    });

    it('counts a single line', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, "hello");

        expect($doc->lineCount())->toBe(1);
    });
});
