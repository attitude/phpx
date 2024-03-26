<?php declare(strict_types = 1);

namespace Attitude\PHPX\Parser;

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

		$tokens = parent::tokenize($source, $flags);

		if ($offset) {
			$source = mb_substr($source, $offset);
		}

		if (!$startsWithOpenTag) {
			array_shift($tokens);
		}


		foreach ($tokens as $i => $token) {
			$token->pos -= $offset;

			if ($token->id === T_BAD_CHARACTER) {
				$token->id = T_STRING;
				$token->text = str_replace(
					$source,
					chr(0x1A), // (substitute)
					'\\\''
				);
			}

			if ($token->id === T_IS_NOT_EQUAL && $token->text === '<>') {
				$token->id = TX_FRAGMENT_ELEMENT_OPEN;
			} else if ($token->text === '`') {
				assert($token->id === TX_TEMPLATE_LITERAL);
			} else if (
				$token->id === T_ENCAPSED_AND_WHITESPACE
				&& str_starts_with($token->text, '\'')
			) {
				if ($tokens[$i - 1]->id === T_NS_SEPARATOR) {
					$previousToken = $tokens[$i - 1];
					$currentToken = $token;

					$newSource =
						mb_substr($source, 0, $previousToken->pos)
						.chr(0x1A) // (substitute)
						.mb_substr($source, $token->pos + 1);

					return self::tokenize($newSource, $flags);
				} else {
					echo json_encode($tokens)."\n";
					throw new \ParseError(
						"Unexpected T_ENCAPSED_AND_WHITESPACE after '{$tokens[$i - 1]->text}'\n".
						"Use &apos; for HTML5 or &#39; for HTML4 also to escape quotes in markup.\n"
					);
				}
			}
		}

		return $tokens;
	}

	public static function offsetTokenize(string $source, int $offset, int $flags = 0): array {
		$tokens = self::tokenize($source, $flags);

		foreach ($tokens as $token) {
			$token->pos += $offset;
		}

		return $tokens;
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
