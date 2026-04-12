<?php declare(strict_types = 1);

namespace Attitude\PHPX\Parser;

final class Token extends \PhpToken implements \JsonSerializable {
	public function __toString(): string {
		return $this->text;
	}

	public static function tokenize(string $source, int $flags = 0): array {
		$startsWithOpenTag = str_starts_with(ltrim($source), '<?php');
		$offset = 0;

		if (!$startsWithOpenTag) {
			if (str_contains($source, '<?php')) {
				throw new \ParseError("Expecting PHPX source to have a <?php tag at the beginning or not at all");
			}

			$source = '<?php '.$source;
			$offset = 6;
		}

		$tokens = parent::tokenize($source, $flags);

		if ($offset) {
			$source = substr($source, $offset);
		}

		if (!$startsWithOpenTag) {
			array_shift($tokens);
		}


		foreach ($tokens as $i => $token) {
			$token->pos -= $offset;

			if ($token->id === T_BAD_CHARACTER) {
				$token->id = T_STRING;
				$token->text = str_replace(chr(0x1A), "\\'", $token->text);
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
						substr($source, 0, $previousToken->pos)
						.chr(0x1A) // (substitute)
						.substr($source, $token->pos + 1);

					return self::tokenize($newSource, $flags);
				} else {
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
