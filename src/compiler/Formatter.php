<?php

namespace Attitude\PHPX\Compiler;

// TODO: Incomplete
class Formatter {
  public function formatElement(string $type, string|null $config, string|null $children): string {
    $compiled = [
			"'{$type}'",
			$config,
			$children,
		];

    if (empty($compiled[2])) {
      array_pop($compiled);

      if (empty($compiled[1])) {
        array_pop($compiled);
      }
    } else if (empty($compiled[1])) {
      $compiled[1] = 'null';
    }

    return '['.implode(', ', $compiled).']';
  }

  public function formatFragment(string $children): string {
    return $children;
  }

  public function formatAttributeExpression(string $name, string $value): string {
    return "'{$name}'=>{$value}";
  }
}
