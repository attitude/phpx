<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

abstract class AbstractFormatter implements FormatterInterface {
  /** An attribute expression is compiled the same way regardless of output shape. */
  public function formatAttributeExpression(string $name, string $value): string {
    return "'{$name}'=>{$value}";
  }

  /** Uppercase-first tag names become PHP variable references; the rest stay string literals. */
  protected static function formatElementType(string $type): string {
    return ctype_upper($type[0]) ? "\${$type}" : "'{$type}'";
  }

  /** A config/children part is "empty" when it carries no data. */
  protected static function isEmptyPart(?string $part): bool {
    return empty($part) || $part === 'null' || $part === '[]';
  }

  /**
   * Drop trailing empty parts and replace any remaining empty part with 'null',
   * so `[type, config, children]` collapses to the shortest valid argument list.
   *
   * @param array<?string> $parts
   * @return string[]
   */
  protected static function normalizeParts(array $parts): array {
    while ($parts !== [] && self::isEmptyPart(end($parts))) {
      array_pop($parts);
    }
    return array_map(fn(?string $part) => self::isEmptyPart($part) ? 'null' : $part, $parts);
  }
}
