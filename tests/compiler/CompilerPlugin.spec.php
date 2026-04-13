<?php declare(strict_types=1);

namespace Attitude\PHPX;

use Attitude\PHPX\Compiler\AbstractCompilerPlugin;
use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Compiler\CompilerPlugin;
use Attitude\PHPX\Compiler\FormatterInterface;

require_once __DIR__ . '/../../src/index.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Plugin that overrides <slot> elements with a custom renderSlot() call. */
function makeSlotPlugin(): CompilerPlugin {
    return new class extends AbstractCompilerPlugin {
        public function visitElement(
            string $nameText,
            ?string $compiledAttributes,
            ?string $compiledChildren,
            FormatterInterface $formatter,
        ): ?string {
            if ($nameText !== 'slot') return null;
            $parts = array_filter([$compiledAttributes, $compiledChildren]);
            return 'renderSlot(' . implode(', ', $parts) . ')';
        }
    };
}

/** Plugin that overrides <> fragments with a custom fragment() call. */
function makeFragmentPlugin(): CompilerPlugin {
    return new class extends AbstractCompilerPlugin {
        public function visitFragment(
            string $compiledChildren,
            FormatterInterface $formatter,
        ): ?string {
            return 'fragment(' . $compiledChildren . ')';
        }
    };
}

/** Plugin that intercepts the x-if attribute and emits a raw PHP value. */
function makeXIfAttributePlugin(): CompilerPlugin {
    return new class extends AbstractCompilerPlugin {
        public function visitAttribute(
            string $nameText,
            string $compiledValue,
            FormatterInterface $formatter,
        ): ?string {
            if ($nameText !== 'x-if') return null;
            return "'__xif__' => {$compiledValue}";
        }
    };
}

// ── Element visitor tests ─────────────────────────────────────────────────────

describe('CompilerPlugin — element visitor', function () {
    it('intercepts a specific element and returns custom output', function () {
        $compiler = new Compiler(plugins: [makeSlotPlugin()]);
        $result = $compiler->compile('<slot />');
        expect($result)->toBe('renderSlot()');
    });

    it('passes compiled attributes to the plugin', function () {
        $compiler = new Compiler(plugins: [makeSlotPlugin()]);
        $result = $compiler->compile('<slot name="header" />');
        expect($result)->toContain('renderSlot(');
        expect($result)->toContain("'name'=>\"header\"");
    });

    it('passes compiled children to the plugin', function () {
        $compiler = new Compiler(plugins: [makeSlotPlugin()]);
        $result = $compiler->compile('<slot>default</slot>');
        expect($result)->toContain('renderSlot(');
        expect($result)->toContain("'default'");
    });

    it('returns null to fall through to the formatter for unhandled elements', function () {
        $compiler = new Compiler(plugins: [makeSlotPlugin()]);
        $result = $compiler->compile('<div />');
        // Default formatter output for <div />
        expect($result)->toBe("['$', 'div']");
    });

    it('first matching plugin wins; remaining plugins are not consulted', function () {
        $calls = [];

        $first = new class($calls) extends AbstractCompilerPlugin {
            public function __construct(private array &$calls) {}
            public function visitElement(string $nameText, ?string $ca, ?string $cc, FormatterInterface $f): ?string {
                $this->calls[] = 'first';
                return 'first-wins';
            }
        };

        $second = new class($calls) extends AbstractCompilerPlugin {
            public function __construct(private array &$calls) {}
            public function visitElement(string $nameText, ?string $ca, ?string $cc, FormatterInterface $f): ?string {
                $this->calls[] = 'second';
                return 'second-wins';
            }
        };

        $compiler = new Compiler(plugins: [$first, $second]);
        $result = $compiler->compile('<div />');

        expect($result)->toBe('first-wins');
        expect($calls)->toBe(['first']);
    });

    it('falls through all plugins and uses the formatter when all return null', function () {
        $noOp = new class extends AbstractCompilerPlugin {};

        $compiler = new Compiler(plugins: [$noOp, $noOp]);
        $result = $compiler->compile('<span />');
        expect($result)->toBe("['$', 'span']");
    });
});

// ── Fragment visitor tests ────────────────────────────────────────────────────

describe('CompilerPlugin — fragment visitor', function () {
    it('intercepts a fragment and returns custom output', function () {
        $compiler = new Compiler(plugins: [makeFragmentPlugin()]);
        $result = $compiler->compile('<>Hello</>');
        expect($result)->toBe("fragment(['Hello'])");
    });

    it('returns null to fall through to the formatter for fragments', function () {
        $compiler = new Compiler(plugins: [makeSlotPlugin()]); // slot plugin ignores fragments
        $result = $compiler->compile('<>Hello</>');
        // Default formatter output
        expect($result)->toBe("['Hello']");
    });
});

// ── Attribute visitor tests ───────────────────────────────────────────────────

describe('CompilerPlugin — attribute visitor', function () {
    it('intercepts a specific attribute and returns custom output', function () {
        $compiler = new Compiler(plugins: [makeXIfAttributePlugin()]);
        $result = $compiler->compile('<div x-if={$show} />');
        expect($result)->toContain("'__xif__' => (\$show)");
    });

    it('leaves unhandled attributes to the formatter', function () {
        $compiler = new Compiler(plugins: [makeXIfAttributePlugin()]);
        $result = $compiler->compile('<div className="foo" />');
        // Compiler keeps JSX names; renderer maps className→class
        expect($result)->toContain("'className'=>\"foo\"");
    });

    it('can mix intercepted and default attributes on the same element', function () {
        $compiler = new Compiler(plugins: [makeXIfAttributePlugin()]);
        $result = $compiler->compile('<div className="foo" x-if={$show} />');
        expect($result)->toContain("'className'=>\"foo\"");
        expect($result)->toContain("'__xif__' => (\$show)");
    });

    it('intercepts prop-punned attributes ({$var} shorthand)', function () {
        // Plugin that renames 'href' to '__href__'
        $plugin = new class extends AbstractCompilerPlugin {
            public function visitAttribute(
                string $nameText,
                string $compiledValue,
                FormatterInterface $formatter,
            ): ?string {
                if ($nameText !== 'href') return null;
                return "'__href__'=>{$compiledValue}";
            }
        };

        $compiler = new Compiler(plugins: [$plugin]);
        $result = $compiler->compile('<a {$href} />');
        expect($result)->toContain("'__href__'=>\$href");
    });
});

// ── AbstractCompilerPlugin no-op base ─────────────────────────────────────────

describe('AbstractCompilerPlugin', function () {
    it('all methods return null by default', function () {
        $plugin = new class extends AbstractCompilerPlugin {};
        $formatter = new \Attitude\PHPX\Compiler\Formatter();

        expect($plugin->visitElement('div', null, null, $formatter))->toBeNull();
        expect($plugin->visitFragment('[]', $formatter))->toBeNull();
        expect($plugin->visitAttribute('class', '"foo"', $formatter))->toBeNull();
    });

    it('a no-op plugin does not alter compiler output', function () {
        $noOp = new class extends AbstractCompilerPlugin {};
        $plain = new Compiler();
        $withPlugin = new Compiler(plugins: [$noOp]);

        $source = '<div className="hello"><span>World</span></div>';
        expect($withPlugin->compile($source))->toBe($plain->compile($source));
    });
});
