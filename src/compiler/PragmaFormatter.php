<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

final class PragmaFormatter extends AbstractFormatter {
  public function __construct(
    private string $pragma = 'html',
    private string $fragment = 'fragment',
  ) {
  }

  public function formatElement(string $type, ?string $config, ?string $children): string {
    $parts = self::normalizeParts([self::formatElementType($type), $config, $children]);

    return $this->pragma . '(' . implode(', ', $parts) . ')';
  }

  public function formatFragment(string $children): string {
    return $this->fragment . '(' . $children . ')';
  }
}
