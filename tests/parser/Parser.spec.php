<?php declare(strict_types=1);

use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Parser\NodeType;
use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;

/** Parse PHPX source into its AST. */
function phpxParse(string $source): array
{
    return (new Parser())->parse(new TokensList(Token::tokenize($source)));
}

/** Compile PHPX source into the PHP array-literal string. */
function phpxCompile(string $source): string
{
    return (new Compiler())->compile($source);
}

/** Extract the attribute nodes from an element node (attributes interleave whitespace tokens). */
function phpxAttributes(array $element): array
{
    return array_values(array_filter(
        $element['attributes'],
        fn($attr) => is_array($attr) && $attr['$$type'] === NodeType::PHPX_ATTRIBUTE,
    ));
}

describe('Parser AST shape', function () {
    it('parses an element node', function () {
        $ast = phpxParse('<div id="x">hi</div>');

        expect($ast)->toHaveCount(1);
        expect($ast[0]['$$type'])->toBe(NodeType::PHPX_ELEMENT);
        expect($ast[0]['selfClosing'])->toBeFalse();
        expect($ast[0]['attributes'])->toBeArray();
        expect($ast[0]['children'])->toBeArray();
    });

    it('parses a self-closing element node with no children key', function () {
        $ast = phpxParse('<br />');

        expect($ast[0]['$$type'])->toBe(NodeType::PHPX_ELEMENT);
        expect($ast[0]['selfClosing'])->toBeTrue();
        expect($ast[0])->not->toHaveKey('children');
    });

    it('parses a fragment node', function () {
        $ast = phpxParse('<>hi</>');

        expect($ast[0]['$$type'])->toBe(NodeType::PHPX_FRAGMENT);
        expect($ast[0]['children'])->toBeArray();
    });

    it('parses an attribute node', function () {
        $ast = phpxParse('<div id="x" />');
        $attribute = phpxAttributes($ast[0])[0];

        expect($attribute['$$type'])->toBe(NodeType::PHPX_ATTRIBUTE);
        expect($attribute['assignment']->text)->toBe('=');
    });

    it('parses a boolean attribute as value true with no assignment', function () {
        $ast = phpxParse('<input disabled />');
        $attribute = phpxAttributes($ast[0])[0];

        expect($attribute['$$type'])->toBe(NodeType::PHPX_ATTRIBUTE);
        expect($attribute['assignment'])->toBeNull();
        expect($attribute['value'])->toBeTrue();
    });

    it('parses an expression container child', function () {
        $ast = phpxParse('<div>{$x}</div>');
        $child = $ast[0]['children'][0];

        expect($child['$$type'])->toBe(NodeType::PHPX_EXPRESSION_CONTAINER);
    });

    it('parses a template literal node', function () {
        $ast = phpxParse('`a${$b}`');

        expect($ast[0]['$$type'])->toBe(NodeType::TEMPLATE_LITERAL);
    });
});

describe('Parser accepts PHP keyword names', function () {
    // PHP tokenizes these names as keyword tokens (T_USE, T_FOR, T_READONLY),
    // not T_STRING. They are valid HTML/SVG names and must parse.
    it('accepts a keyword tag name', function () {
        expect(phpxCompile('<use href="#icon" />'))->toBe('[\'$\', \'use\', [\'href\'=>"#icon"]]');
    });

    it('accepts a keyword attribute name (for)', function () {
        expect(phpxCompile('<label for="x">Hi</label>'))->toBe('[\'$\', \'label\', [\'for\'=>"x"], [\'Hi\']]');
    });

    it('accepts a keyword boolean attribute (readonly)', function () {
        expect(phpxCompile('<input readonly />'))->toBe('[\'$\', \'input\', [\'readonly\'=>true]]');
    });

    it('accepts a namespaced attribute name', function () {
        expect(phpxCompile('<use xmlns:xlink="ns" />'))->toBe('[\'$\', \'use\', [\'xmlns:xlink\'=>"ns"]]');
    });

    // Regression for #32: keyword tag names must parse as elements in CHILD
    // position too, not just at the top level (SVG <use> sprites break otherwise).
    it('parses a self-closing keyword child as an element', function () {
        expect(phpxCompile('<div><use href="#x" /></div>'))
            ->toBe('[\'$\', \'div\', null, [[\'$\', \'use\', [\'href\'=>"#x"]]]]');
    });

    it('parses a paired keyword child as an element', function () {
        expect(phpxCompile('<svg><use href="#x"></use></svg>'))
            ->toBe('[\'$\', \'svg\', null, [[\'$\', \'use\', [\'href\'=>"#x"]]]]');
    });

    it('parses another keyword child (<list>) as an element', function () {
        expect(phpxCompile('<div><list></list></div>'))
            ->toBe('[\'$\', \'div\', null, [[\'$\', \'list\']]]');
    });

    it('still treats < followed by a space as text inside children', function () {
        expect(phpxCompile('<p>a &lt; b</p>'))->toBe('[\'$\', \'p\', null, [\'a &lt; b\']]');
    });
});

describe('Parser disambiguates < as less-than', function () {
    // A tag can never start with a digit, so `<` immediately followed by a number
    // is a comparison, not an element opener.
    $cases = [
        '{$x<1}' => '{$x<1}',
        '{$x<2}' => '{$x<2}',
        '{1<$x}' => '{1<$x}',
        '{$x<$y}' => '{$x<$y}',
    ];

    foreach ($cases as $source => $expected) {
        it("keeps {$source} as an expression", function () use ($source, $expected) {
            expect(phpxCompile($source))->toBe($expected);
        });
    }

    it('disambiguates <0 (less-than) from <p> (element) in one expression', function () {
        expect(phpxCompile('<div>{$count<0 ? <p>x</p> : null}</div>'))
            ->toBe("['\$', 'div', null, [(\$count<0 ? ['\$', 'p', null, ['x']] : null)]]");
    });
});

describe('Parser error handling', function () {
    $invalid = [
        'mismatched closing tag' => ['<div></span>', 'closing tag to match'],
        'closing fragment where element expected' => ['<div></>', 'expected element name'],
        'unclosed element' => ['<div>', 'end of input'],
        'unclosed fragment' => ['<>hi', 'fragment element closer'],
        'unterminated template literal' => ['`hello', 'template literal'],
        'class instead of className' => ['<div class="x">hi</div>', 'className'],
        'malformed namespaced attribute' => ['<div a:="x" />', 'namespaced attribute name'],
        'digit-start attribute name' => ['<div 1="x" />', 'Unexpected token'],
    ];

    foreach ($invalid as $label => [$source, $needle]) {
        it("throws ParseError on {$label}", function () use ($source, $needle) {
            expect(fn() => phpxParse($source))->toThrow(\ParseError::class, $needle);
        });
    }
});

describe('Token::tokenize', function () {
    it('tags the fragment opener with a synthetic id', function () {
        $tokens = Token::tokenize('<>hi</>');

        expect($tokens[0]->id)->toBe(\Attitude\PHPX\Parser\TX_FRAGMENT_ELEMENT_OPEN);
    });

    it('throws when a <?php tag appears mid-source', function () {
        expect(fn() => Token::tokenize('markup <?php echo 1;'))
            ->toThrow(\ParseError::class, '<?php');
    });

    // The TX_* constants Token::tokenize() depends on must be autoloaded even when
    // Token::tokenize() is the very first call in a fresh process (composer "files").
    it('works as the first call in a fresh process (constants autoloaded)', function () {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        expect($autoload)->not->toBeFalse();

        $script = '<?php require ' . var_export($autoload, true) . ';'
            . 'echo \\Attitude\\PHPX\\Parser\\Token::tokenize("<>hi</>")[0]->id'
            . ' === \\Attitude\\PHPX\\Parser\\TX_FRAGMENT_ELEMENT_OPEN ? "OK" : "MISMATCH";';

        $tmp = tempnam(sys_get_temp_dir(), 'phpx_autoload_');
        file_put_contents($tmp, $script);
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tmp));
        unlink($tmp);

        expect(trim((string) $output))->toBe('OK');
    });
});

describe('Parser production behaviour (assertions disabled)', function () {
    // The mismatched-closing-tag check was once an assert(), which is a no-op
    // when zend.assertions is off (the production default) — malformed input
    // was silently accepted. Prove it now throws regardless of that setting.
    it('rejects mismatched closing tags with zend.assertions=-1', function () {
        $autoload = realpath(__DIR__ . '/../../vendor/autoload.php');
        expect($autoload)->not->toBeFalse();

        $script = '<?php require ' . var_export($autoload, true) . ';'
            . 'try { (new Attitude\\PHPX\\Compiler\\Compiler())->compile("<div></span>"); echo "NO_THROW"; }'
            . ' catch (\\ParseError $e) { echo "PARSE_ERROR"; }'
            . ' catch (\\Throwable $e) { echo "OTHER:" . get_class($e); }';

        $tmp = tempnam(sys_get_temp_dir(), 'phpx_prod_');
        file_put_contents($tmp, $script);
        $output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d zend.assertions=-1 ' . escapeshellarg($tmp));
        unlink($tmp);

        expect(trim((string) $output))->toBe('PARSE_ERROR');
    });
});
