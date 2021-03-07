<?php declare(strict_types = 1);

namespace PHPX\PHPX;

final class Token extends \PhpToken implements \JsonSerializable {
	public function __toString(): string {
		return $this->text;
	}

	public static function tokenize(string $source, int $flags = 0): array {
		$startsWithOpenTag = preg_match('/^\s*<\?php/', $source);
		$offset = 0;

		if (!$startsWithOpenTag) {
			if ($startsWithOpenTag === false) {
				$errorCode = preg_last_error();

				if ($errorCode !== PREG_NO_ERROR) {
					$errorMessages = [
							PREG_INTERNAL_ERROR => 'Internal error',
							PREG_BACKTRACK_LIMIT_ERROR => 'Backtrack limit was exhausted',
							PREG_RECURSION_LIMIT_ERROR => 'Recursion limit was exhausted',
							PREG_BAD_UTF8_ERROR => 'Malformed UTF-8 characters',
							PREG_BAD_UTF8_OFFSET_ERROR => 'Offset into the string is not valid UTF-8 code point',
					];

					$errorMessage = $errorMessages[$errorCode] ?? 'Unknown error';
					throw new \Exception("preg_match error: $errorMessage");
				}
			}

			if (strstr($source, '<?php')) {
				throw new \ParseError("Expecting PHPX source to have a <?php tag at the beginning or not at all");
			}

			$source = '<?php '.$source;
			$offset = 6;
		}

		$tokens = parent::tokenize($source);
		if (!$startsWithOpenTag) {
			array_shift($tokens);
		}

		return array_map(function (Token $token) use ($offset, $source) {
			$text = $token->text;
			$id = $token->id;
			$line = $token->line;
			$position = $token->pos;

			if ($id === T_ENCAPSED_AND_WHITESPACE && str_starts_with($text, '\'')) {
				$buffer = substr($source, 0, $position);
				$lastPositionOfNewLine = strrpos($buffer, "\n");
				$nextPositionOfNewLine = strpos($text, "\n");

				echo json_encode(trim($text, "\n"));
				$before = substr($buffer, $lastPositionOfNewLine + 1);

				throw new \ParseError(
					"Unescaped single quote found at line {$line}:\n".
					$before.substr($text, 0, $nextPositionOfNewLine)."\n".
					str_repeat('-', strlen($before))."^\n".
					"Use &apos; for HTML5 or &#39; for HTML4 also to escape quotes in markup.\n"
				);
			}

			if ($id === T_IS_NOT_EQUAL && $text === '<>') {
				return new Token(id: TX_FRAGMENT_ELEMENT_OPEN, text: '<>', line: $line, pos: $position);
			} else if ($text === '`') {
				assert($id === TX_TEMPLATE_LITERAL);

				return match($offset) {
					0 => $token,
					default => new Token(id: $id, text: $text, line: $line, pos: $position - $offset),
				};
			} else {
				return match($offset) {
					0 => $token,
					default => new Token(id: $id, text: $text, line: $line, pos: $position - $offset),
				};
			}
		}, $tokens);
	}

	public function jsonSerialize(): string {
		$end = $this->pos +strlen($this->text);

		if ($this->getTokenName() === $this->text) {
			return "L{$this->line}:P{$this->pos}-{$end}:{$this->text}:{$this->id}";
		} else {
			return "L{$this->line}:P{$this->pos}-{$end}:{$this->text}:{$this->id}:<{$this->getTokenName()}";
		}
	}
}
