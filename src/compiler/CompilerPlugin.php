<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

/**
 * Visitor-style plugin for the PHPX compiler.
 *
 * Plugins intercept element, fragment, and attribute compilation after attributes
 * and children have already been compiled into PHP strings by the Compiler. Return
 * a non-null string to override the output; return null to fall through to the next
 * plugin or, ultimately, the active FormatterInterface.
 *
 * Plugins are tried in registration order; the first non-null return wins.
 *
 * Example — intercept a custom <slot> element:
 *
 *   class SlotPlugin extends AbstractCompilerPlugin {
 *       public function visitElement(
 *           string $nameText,
 *           ?string $compiledAttributes,
 *           ?string $compiledChildren,
 *           FormatterInterface $formatter,
 *       ): ?string {
 *           if ($nameText !== 'slot') return null;
 *           $name = '...'; // extract from $compiledAttributes if needed
 *           return "renderSlot({$name}, {$compiledChildren})";
 *       }
 *   }
 */
interface CompilerPlugin
{
    /**
     * Called for every PHPX element node.
     *
     * @param string $nameText Tag name as it appears in source (e.g. 'div', 'MyComponent').
     * @param string|null $compiledAttributes Already-compiled PHP props array, or null when the
     *   element has no attributes.
     * @param string|null $compiledChildren Already-compiled PHP children array, or null when the
     *   element has no children.
     * @param FormatterInterface $formatter The active formatter — use it to delegate for elements
     *   you don't handle.
     * @return string|null Compiled PHP string, or null to defer.
     */
    public function visitElement(
        string $nameText,
        ?string $compiledAttributes,
        ?string $compiledChildren,
        FormatterInterface $formatter,
    ): ?string;

    /**
     * Called for every PHPX fragment node (<>…</>).
     *
     * @param string $compiledChildren Already-compiled PHP children array.
     * @param FormatterInterface $formatter The active formatter.
     * @return string|null Compiled PHP string, or null to defer.
     */
    public function visitFragment(
        string $compiledChildren,
        FormatterInterface $formatter,
    ): ?string;

    /**
     * Called for every PHPX attribute node.
     *
     * @param string $nameText Attribute name as it appears in source (e.g. 'className', 'x-if').
     * @param string $compiledValue Already-compiled PHP value expression.
     * @param FormatterInterface $formatter The active formatter.
     * @return string|null Compiled PHP string (the full 'key' => value pair), or null to defer.
     */
    public function visitAttribute(
        string $nameText,
        string $compiledValue,
        FormatterInterface $formatter,
    ): ?string;
}
