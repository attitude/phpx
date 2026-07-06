<?php declare(strict_types=1);

use Attitude\PHPX\LanguageServer\DiagnosticsProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

function diagnose(string $source): array
{
    return (new DiagnosticsProvider())->diagnose(
        new TextDocumentItem('file:///t.phpx', 'phpx', 1, $source),
    );
}

describe('attribute-typo diagnostics', function () {
    it('warns on a misspelled attribute with a suggestion', function () {
        $d = diagnose('<div classname="x"></div>');

        expect($d)->toHaveCount(1);
        expect($d[0]['message'])->toContain('classname');
        expect($d[0]['message'])->toContain('className');
        expect($d[0]['severity'])->toBe(2); // Warning
        expect($d[0]['source'])->toBe('phpx');
    });

    it('catches HTML-vs-JSX casing (tabindex, onclik)', function () {
        expect(diagnose('<input tabindex="1" />')[0]['message'])->toContain('tabIndex');
        expect(diagnose('<button onclik={$f}></button>')[0]['message'])->toContain('onClick');
    });

    it('reports an accurate range for the attribute', function () {
        $d = diagnose('<div classname="x"></div>');
        // `classname` starts at column 5 on line 0, length 9.
        expect($d[0]['range']['start'])->toBe(['line' => 0, 'character' => 5]);
        expect($d[0]['range']['end'])->toBe(['line' => 0, 'character' => 14]);
    });

    $valid = [
        'correct attribute' => '<div className="x"></div>',
        'data-* attribute' => '<div data-foo="x"></div>',
        'aria-* attribute' => '<div aria-label="x"></div>',
        'element-specific attribute' => '<input type="text" />',
        'no close match (left alone)' => '<div zzzzzzzz="x"></div>',
    ];

    foreach ($valid as $label => $source) {
        it("does not warn: {$label}", function () use ($source) {
            expect(diagnose($source))->toBe([]);
        });
    }

    it('skips PHPX components (uppercase tags), whose props are unknown', function () {
        // Regression: the tag name was lowercased before the component check,
        // so components used to get false-positive attribute warnings.
        expect(diagnose('<Foo classname="x" />'))->toBe([]);
        expect(diagnose('<div><Card titel="x" /></div>'))->toBe([]);
    });

    it('does not warn when the document has a parse error (only after a clean parse)', function () {
        // Broken input yields a parse diagnostic, not an attribute one.
        $d = diagnose('<div classname="x">');
        expect($d)->toHaveCount(1);
        expect($d[0]['severity'])->toBe(1); // Error (unclosed tag), not the attribute warning
    });
});
