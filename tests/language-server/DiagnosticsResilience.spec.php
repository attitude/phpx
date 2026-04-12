<?php declare(strict_types = 1);

use Attitude\PHPX\LanguageServer\DiagnosticsProvider;
use Attitude\PHPX\LanguageServer\TextDocumentItem;

describe('DiagnosticsProvider resilience', function () {
    beforeEach(function () {
        $this->provider = new DiagnosticsProvider();
    });

    /**
     * Helper: assert that every diagnostic in the array has valid LSP shape.
     *
     * @param array $diagnostics
     * @param string $context  Human-readable label for failure messages
     */
    $assertValidDiagnostics = function (array $diagnostics, string $context): void {
        foreach ($diagnostics as $i => $diag) {
            // Must have all required keys
            expect($diag)->toHaveKeys(['range', 'severity', 'source', 'message']);

            // range shape
            $range = $diag['range'];
            expect($range)->toHaveKeys(['start', 'end']);
            expect($range['start'])->toHaveKeys(['line', 'character']);
            expect($range['end'])->toHaveKeys(['line', 'character']);

            // Non-negative positions
            expect($range['start']['line'])->toBeGreaterThanOrEqual(0);
            expect($range['start']['character'])->toBeGreaterThanOrEqual(0);
            expect($range['end']['line'])->toBeGreaterThanOrEqual(0);
            expect($range['end']['character'])->toBeGreaterThanOrEqual(0);

            // Severity must be 1 (Error)
            expect($diag['severity'])->toBe(1);

            // Source must be 'phpx'
            expect($diag['source'])->toBe('phpx');

            // Message must be a non-empty string
            expect($diag['message'])->toBeString();
            expect(strlen($diag['message']))->toBeGreaterThan(0);
        }
    };

    // ------------------------------------------------------------------
    // Broken / mid-typing inputs: diagnose() must never throw
    // ------------------------------------------------------------------

    $brokenInputs = [
        // Mid-typing states
        '<',
        '<d',
        '<div',
        '<div ',
        '<div>',
        '<div>text',
        '<div>text<',
        '<div>text</',
        '<div>text</d',
        '</div>',
        '< >',
        '<>',
        '</>',

        // Broken attributes
        '<div class="x">text</div>',  // class instead of className
        '<div ="value">text</div>',
        '<div attr=>text</div>',
        '<div attr={>text</div>',
        '<div attr={}>text</div>',

        // Broken expressions
        '{',
        '{<}',
        '{<div>}',
        '<div>{</div>',
        '<div>{<span>}</div>',

        // Broken nesting
        '<div><span></div></span>',
        '<div><div></div>',
        '<div></span>',

        // Broken fragments (note: '<>' already listed above)
        '<>text',
        '<>text</',

        // Template literals
        '`hello',
        '`hello ${',
        '`hello ${name`',

        // Mixed broken
        '<?php echo "hi";',
        '<div>// comment</div>',
        '<div># comment</div>',

        // Empty/whitespace
        '',
        '   ',
        "\n\n\n",
    ];

    foreach ($brokenInputs as $idx => $input) {
        $escaped = json_encode($input);

        it("never crashes on broken input #{$idx}: {$escaped}", function () use ($input, $assertValidDiagnostics) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $input);
            $diagnostics = $this->provider->diagnose($doc);

            expect($diagnostics)->toBeArray();
            $assertValidDiagnostics($diagnostics, "input: " . json_encode($input));
        });
    }

    // ------------------------------------------------------------------
    // Valid inputs: must return an empty array
    // ------------------------------------------------------------------

    $validInputs = [
        '<div>text</div>',
        '<br />',
        '<>text</>',
        '<div className="x">text</div>',
        '<my-component />',
        '<div><span>nested</span></div>',
        '<ul><li>one</li><li>two</li></ul>',
    ];

    foreach ($validInputs as $input) {
        it("returns empty diagnostics for valid input: '{$input}'", function () use ($input) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $input);
            $diagnostics = $this->provider->diagnose($doc);

            expect($diagnostics)->toBe([]);
        });
    }

    // ------------------------------------------------------------------
    // Multi-line inputs: line numbers in diagnostics must stay in bounds
    // ------------------------------------------------------------------

    it('produces in-bounds line numbers for multi-line broken input', function () use ($assertValidDiagnostics) {
        $multilineInputs = [
            "<div>\n  <span>\n  unclosed",
            "<div\n  className=\"x\"\n>",
            "\n\n\n<div>",
            "<div>\n</span>",
            "<div>\n  <img\n  src={\n>",
        ];

        foreach ($multilineInputs as $input) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $input);
            $diagnostics = $this->provider->diagnose($doc);

            expect($diagnostics)->toBeArray();
            $assertValidDiagnostics($diagnostics, "multiline input: " . json_encode($input));

            // Line numbers must not exceed the document's line count
            $lineCount = substr_count($input, "\n") + 1;
            foreach ($diagnostics as $diag) {
                expect($diag['range']['start']['line'])->toBeLessThan(
                    $lineCount,
                    "start.line out of bounds for: " . json_encode($input),
                );
                expect($diag['range']['end']['line'])->toBeLessThan(
                    $lineCount,
                    "end.line out of bounds for: " . json_encode($input),
                );
            }
        }
    });

    // ------------------------------------------------------------------
    // Rapid successive calls: provider stays stable
    // ------------------------------------------------------------------

    it('stays stable after many rapid calls with alternating valid and broken input', function () use ($assertValidDiagnostics) {
        $inputs = [
            '<div>ok</div>',
            '<div>',
            '<div>ok</div>',
            '<',
            '<div className="x">ok</div>',
            '<div class="x">bad</div>',
            '',
            '<br />',
            '< >',
            '<>fragment</>',
        ];

        foreach ($inputs as $input) {
            $doc = new TextDocumentItem('file:///test.phpx', 'phpx', 1, $input);
            $diagnostics = $this->provider->diagnose($doc);

            expect($diagnostics)->toBeArray();
            $assertValidDiagnostics($diagnostics, "rapid input: " . json_encode($input));
        }
    });
});
