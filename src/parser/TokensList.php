<?php declare(strict_types=1);

namespace Attitude\PHPX\Parser;

// TX_* token constants live in constants.php (always autoloaded) — see the note there.

final class TokensList implements \JsonSerializable, \Iterator {
	private int $cursor = 0;

	public function __construct(private array $tokens) {
		foreach ($this->tokens as $token) {
			assert($token instanceof Token);
		}
	}

	private function tokenAtCursorMatchesSequence(array $sequence): Token|null {
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

	public function tokenAtCursorIsWord(int $offset = 0): Token|null {
		$token = $this->tokenAtCursor($offset);

		if ($token === null) {
			return null;
		} else if ($token->id === T_STRING) {
			return $token;
		} else if (preg_match('/^\w+$/', $token->text)) {
			return $token;
		} else {
			return null;
		}
	}

	/**
	 * Like tokenAtCursorIsWord(), but only matches tokens that can START a tag or
	 * attribute name: an identifier beginning with a letter or underscore. Used to
	 * disambiguate `<` — a tag can never start with a digit, so `<1` is less-than,
	 * not an element opener.
	 */
	public function tokenAtCursorIsNameStart(int $offset = 0): Token|null {
		$token = $this->tokenAtCursor($offset);

		if ($token === null) {
			return null;
		} else if ($token->id === T_STRING) {
			return $token;
		} else if (preg_match('/^[A-Za-z_]\w*$/', $token->text)) {
			return $token;
		} else {
			return null;
		}
	}

	public function tokenAtCursorMatches(int|string|array $value): Token|null {
		$token = $this->tokenAtCursor();

		if (is_array($value)) {
			if ($this->tokenAtCursorMatchesSequence($value)) {
				return $token;
			} else {
				return null;
			}
		} else if (is_string($value)) {
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
			throw new \InvalidArgumentException("Invalid value type " . gettype($value));
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
			foreach ($value as $t) {
				if (!($t instanceof Token)) {
					throw new \TypeError('replaceTokenAtCursor() expects an array of Token instances.');
				}
			}
			array_splice($this->tokens, $this->cursor, 1, $value);
		} else {
			$this->tokens[$this->cursor] = $value;
		}
	}

	public function __toString(): string {
		return implode('', array_map(fn($token) => $token->text, $this->tokens));
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
