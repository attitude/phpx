<?php

namespace Attitude\PHPX\Compiler;

require_once 'FormatterInterface.php';

final class PragmaFormatter implements FormatterInterface {
  public function __construct(
    private string $pragma = 'html',
    private string $fragment = 'fragment',
  ) {}

  public function formatElement(string $type, string|null $config, string|null $children): string {
    $compiled = [
			"'{$type}'",
			$config,
			$children,
		];

    if (empty($compiled[2]) || $compiled[2] === 'null' || $compiled[2] === '[]') {
      array_pop($compiled);

      if (empty($compiled[1]) || $compiled[1] === 'null' || $compiled[1] === '[]') {
        array_pop($compiled);
      }
    } else if (empty($compiled[1]) || $compiled[1] === 'null' || $compiled[1] === '[]') {
      $compiled[1] = 'null';
    }

    return $this->pragma.'('.implode(', ', $compiled).')';
  }

  public function formatFragment(string $children): string {
    return $this->fragment.'('.$children.')';
  }

  public function formatAttributeExpression(string $name, string $value): string {
    return "'{$name}'=>{$value}";
  }
}
