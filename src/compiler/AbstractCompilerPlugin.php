<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

/**
 * Convenience base class for compiler plugins.
 *
 * All visitor methods return null by default, so you only need to override
 * the methods relevant to your plugin.
 */
abstract class AbstractCompilerPlugin implements CompilerPlugin
{
    public function visitElement(
        string $nameText,
        ?string $compiledAttributes,
        ?string $compiledChildren,
        FormatterInterface $formatter,
    ): ?string {
        return null;
    }

    public function visitFragment(
        string $compiledChildren,
        FormatterInterface $formatter,
    ): ?string {
        return null;
    }

    public function visitAttribute(
        string $nameText,
        string $compiledValue,
        FormatterInterface $formatter,
    ): ?string {
        return null;
    }
}
