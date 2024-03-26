<?php

function concatenateStringMembers(array $array, bool $allowNumeric = false): array {
  $combinedArray = [];
  $currentString = '';

  foreach ($array as $item) {
    if (
      is_string($item)
      ||
      ($allowNumeric && is_numeric($item)
    )) {
      $currentString .= $item;
    } else {
      if ($currentString !== '') {
        $combinedArray[] = $currentString;
        $currentString = '';
      }
      $combinedArray[] = $item;
    }
  }

  if ($currentString !== '') {
    $combinedArray[] = $currentString;
  }

  return $combinedArray;
}
