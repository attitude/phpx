<?php

namespace Attitude\PHPX\Compiler;

require_once 'FormatterInterface.php';

final class Formatter implements FormatterInterface {
  public function formatElement(string $type, string|null $config, string|null $children): string {
    $compiled = [
        "'$'",
        "'{$type}'",
        $config,
        $children,
    ];

    if (empty($compiled[3]) || $compiled[3] === 'null' || $compiled[3] === '[]') {
      array_pop($compiled);

      if (empty($compiled[2]) || $compiled[2] === 'null' || $compiled[2] === '[]') {
        array_pop($compiled);
      }
    } else if (empty($compiled[2]) || $compiled[2] === 'null' || $compiled[2] === '[]') {
      $compiled[2] = 'null';
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
