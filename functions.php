<?php declare(strict_types = 1);

namespace Attitude\PHPX;

function parse(string $source, int $flags = Parser::SKIP_WHITESPACE): array {
  return (Parser::create($source, $flags))->ast();
}

function nodeAST(string $source, int $flags = Parser::SKIP_WHITESPACE): array {
  return (Parser::create($source, $flags))->nodeAST();
}
