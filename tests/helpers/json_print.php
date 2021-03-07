<?php declare(strict_types = 1);

function json_print(mixed $value, int $depth = 512): void {
  echo json_encode(
    $value,
    JSON_PRETTY_PRINT
    | JSON_UNESCAPED_SLASHES
    | JSON_UNESCAPED_UNICODE,
    $depth
  );
}
