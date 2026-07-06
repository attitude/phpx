<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

final class Formatter extends AbstractFormatter {
  public function formatElement(string $type, ?string $config, ?string $children): string {
    $parts = self::normalizeParts(["'$'", self::formatElementType($type), $config, $children]);

    return '[' . implode(', ', $parts) . ']';
  }

  public function formatFragment(string $children): string {
    return $children;
  }
}
