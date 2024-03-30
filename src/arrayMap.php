<?php declare(strict_types = 1);

namespace Attitude\PHPX;

function arrayMap(array $array, callable $callback): array {
  $result = [];
  foreach ($array as $key => $value) {
    $result[$key] = $callback($value, $key, $array);
  }
  return $result;
}
