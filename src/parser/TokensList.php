<?php declare(strict_types = 1);

namespace Attitude\PHPX\Parser;

/** Token for {, value of ord('{'); */
const TX_CURLY_BRACKET_OPEN = 123;
/** Token for }, value of ord('}'); */
const TX_CURLY_BRACKET_CLOSE = 125;
/** Token for (, value of ord('('); */
const TX_PARENTHESIS_OPEN = 40;
/** Token for ), value of ord(')'); */
const TX_PARENTHESIS_CLOSE = 41;
/** Token for [, value of ord('['); */
const TX_SQUARE_BRACKET_OPEN = 91;
/** Token for ], value of ord(']'); */
const TX_SQUARE_BRACKET_CLOSE = 93;
/** Token for <>, value of word_ord('<>'); */
const TX_FRAGMENT_ELEMENT_OPEN = 15932;
/** Token sequence for </>, value of ['<', '/', '>']; */
const TX_FRAGMENT_ELEMENT_CLOSING_SEQUENCE = ['<', '/', '>'];
/** Token for <, value of ord('<'); */
const TX_ELEMENT_OPENING_OPEN = 60;
/** Token for <T_STRING sequence, value of ['<',T_STRING]; */
const TX_ELEMENT_OPENING_OPEN_SEQUENCE = ['<', T_STRING];
/** Token sequence for />, value of ['/', '>']; */
const TX_ELEMENT_SELF_CLOSING_SEQUENCE = ['/', '>'];
/** Token for >, value of ord('>'); */
const TX_ELEMENT_OPENING_CLOSE = 62;
/** Token for </T_STRING> sequence, value of ['<', '/', T_STRING, '>']; */
const TX_ELEMENT_CLOSING_SEQUENCE = ['<', '/', T_STRING, '>'];
/** Token for Template Literal backtick, value of ord('`'); */
const TX_TEMPLATE_LITERAL = 96; // ord('`');

final class TokensList implements \JsonSerializable, \Iterator {
	protected int $cursor = 0;

	public function __construct(protected array $tokens) {
		foreach ($this->tokens as $token) {
			assert($token instanceof Token);
		}
	}

	protected function tokenAtCursorMatchingSequence(array $sequence): Token|null {
		foreach ($sequence as $i => $text) {
			assert(is_string($text) || is_int($text));

			if ($this->exist()) {
				if (is_string($text)) {
					if ($this->tokenAtCursor($i)?->text !== $text) {
						return null;
					}
				} else if (is_int($text)) {
					if ($this->tokenAtCursor($i)?->id !== $text) {
						return null;
					}
				} else {
					return null;
				}
			} else {
				return null;
			}
		}

		return $this->tokenAtCursor();
	}

	public function tokenAtCursorMatching(int|string|array $value): Token|null {
		$token = $this->tokenAtCursor();

		if (is_array($value)) {
			if ($this->tokenAtCursorMatchingSequence($value)) {
				return $token;
			} else {
				return null;
			}
		} else {
			if (is_string($value)) {
				if ($token?->text === $value) {
					return $token;
				} else {
					return null;
				}
			} else if (is_int($value)) {
				if ($token?->id === $value) {
					return $token;
				} else {
					return null;
				}
			} else {
				throw new \InvalidArgumentException("Invalid value type ".gettype($value));
			}
		}
	}

	public function tokenAtCursor(int $offset = 0): Token|null {
		return $this->tokens[$this->cursor + $offset] ?? null;
	}

	public function tokenAtCursorAndForward(): Token|null {
		$token = $this->tokenAtCursor();
		$this->move();

		return $token;
	}

	public function index(): int {
		return $this->cursor;
	}

	public function move(int $offset = 1): void {
		$this->cursor = $this->cursor + $offset;
	}

	public function exist(int $offset = 0): bool {
		return isset($this->tokens[$this->cursor + $offset]);
	}

	public function replaceTokenAtCursor(array|Token $value): void {
		if (is_array($value)) {
			array_splice($this->tokens, $this->cursor, 1, array_map(fn(Token $it) => $it, $value));
		} else {
			$this->tokens[$this->cursor] = $value;
		}
	}

	public function __toString(): string {
		return implode('', array_map(fn ($token) => $token->text, $this->tokens));
	}

	// Iterator methods:

	public function valid(): bool {
		return isset($this->tokens[$this->cursor]);
	}

	public function current(): Token {
		return $this->tokens[$this->cursor];
	}

	public function key(): int {
		return $this->cursor;
	}

	public function next(): void {
		$this->cursor++;
	}

	public function rewind(): void {
		$this->cursor = 0;
	}

	// JsonSerializable methods:

	public function jsonSerialize(): array {
		return $this->tokens;
	}
}
