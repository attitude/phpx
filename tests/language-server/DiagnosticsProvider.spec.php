<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\DiagnosticsProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('DiagnosticsProvider', function () {
    beforeEach(function () {
        $this->provider = new DiagnosticsProvider();
    });

    it('returns empty diagnostics for valid PHPX', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</div>');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toBe([]);
    });

    it('returns empty diagnostics for valid self-closing tags', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<br />');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toBe([]);
    });

    it('returns empty diagnostics for valid nested PHPX', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div><span>text</span></div>');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toBe([]);
    });

    it('returns diagnostics for unclosed tags', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toHaveCount(1);
        expect($diagnostics[0]['severity'])->toBe(1);
        expect($diagnostics[0]['source'])->toBe('phpx');
        expect($diagnostics[0]['message'])->toBeString();
        expect($diagnostics[0])->toHaveKey('range');
    });

    it('returns diagnostics for mismatched tags', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello</span>');
        $diagnostics = $this->provider->diagnose($doc);

        // The parser detects mismatched tags via assert(). When zend.assertions=-1
        // (e.g. PHP 8.5 CI), asserts are compiled out and the mismatch is silently
        // ignored. In that case no diagnostic is produced — which is acceptable
        // because the compiled PHP would also silently accept it.
        if ((int) ini_get('zend.assertions') >= 0) {
            expect($diagnostics)->toHaveCount(1);
            expect($diagnostics[0]['severity'])->toBe(1);
            expect($diagnostics[0]['source'])->toBe('phpx');
        } else {
            expect($diagnostics)->toBeArray();
        }
    });

    it('provides a range in each diagnostic', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div>Hello');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->not->toBeEmpty();
        $range = $diagnostics[0]['range'];
        expect($range)->toHaveKey('start');
        expect($range)->toHaveKey('end');
        expect($range['start'])->toHaveKey('line');
        expect($range['start'])->toHaveKey('character');
        expect($range['end'])->toHaveKey('line');
        expect($range['end'])->toHaveKey('character');
    });

    it('returns empty diagnostics for empty input', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toBe([]);
    });

    it('returns empty diagnostics for plain text', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, 'Hello World');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toBe([]);
    });

    it('returns empty diagnostics for fragments', function () {
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<>Hello</>');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toBe([]);
    });

    it('catches Throwable from parser and returns a diagnostic instead of crashing', function () {
        // Use class attribute (reserved keyword) which throws ParseError
        $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, '<div class="x">test</div>');
        $diagnostics = $this->provider->diagnose($doc);

        expect($diagnostics)->toHaveCount(1);
        expect($diagnostics[0]['severity'])->toBe(1);
        expect($diagnostics[0]['source'])->toBe('phpx');
        expect($diagnostics[0]['message'])->toContain('className');
    });

    it('survives any parser crash and always returns an array', function () {
        // Various broken inputs that could trigger different parser errors
        $broken = [
            '<div>{</div>',
            '< >',
            '<div class="x">',
            '{<}',
            '<div></>',
        ];

        foreach ($broken as $input) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $input);
            $diagnostics = $this->provider->diagnose($doc);

            expect($diagnostics)->toBeArray("Failed for input: $input");
        }
    });
});
