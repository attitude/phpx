<?php declare(strict_types=1);

namespace Attitude\PHPX\Modules;

use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\SyntaxRecognizer;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;

/**
 * Recognizes ESM-style `import`/`export` statements and parses them into
 * 'ImportDeclaration'/'ExportDeclaration' custom nodes. ImportExportVisitor
 * lowers those nodes to plain PHP before the Compiler emits code.
 */
final class ImportExportRecognizer implements SyntaxRecognizer {
	private const INSIGNIFICANT = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
	private const REJECTING_PREVIOUS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST];

	public function claims(TokensList $tokens): bool {
		$token = $tokens->tokenAtCursor();

		if ($token === null || $token->id !== T_STRING) {
			return false;
		}

		if ($token->text !== 'import' && $token->text !== 'export') {
			return false;
		}

		$previousOffset = $this->significantOffset($tokens, -1, -1);
		if ($previousOffset !== null) {
			$previous = $tokens->tokenAtCursor($previousOffset);
			if (in_array($previous->id, self::REJECTING_PREVIOUS, true)) {
				return false;
			}
		}

		$nextOffset = $this->significantOffset($tokens, 1, 1);
		if ($nextOffset === null) {
			return false;
		}
		$next = $tokens->tokenAtCursor($nextOffset);

		return $token->text === 'import'
			? $this->claimsImport($tokens, $next, $nextOffset)
			: $this->claimsExport($next);
	}

	private function claimsImport(TokensList $tokens, Token $next, int $nextOffset): bool {
		if ($next->text === '{' || $next->text === '*') {
			return true;
		}

		if (!$this->isWord($next)) {
			return false;
		}

		$afterOffset = $this->significantOffset($tokens, $nextOffset + 1, 1);
		$after = $afterOffset === null ? null : $tokens->tokenAtCursor($afterOffset);

		return $after !== null && $after->id === T_STRING && $after->text === 'from';
	}

	private function claimsExport(Token $next): bool {
		return $next->id === T_VARIABLE || $next->text === 'default' || $next->text === '{';
	}

	/**
	 * True when the token can serve as an import/export name: an identifier starting
	 * with a letter or underscore. Matched by text, not id, because PHP tokenizes
	 * keyword-cased names (List, Switch, Default, …) as keyword tokens, not T_STRING —
	 * same approach as TokensList::tokenAtCursorIsNameStart() for JSX names.
	 */
	private function isWord(?Token $token): bool {
		return $token !== null && preg_match('/^[A-Za-z_]\w*$/', $token->text) === 1;
	}

	/** Scan from $start in steps of $step, skipping whitespace/comments; returns the offset of the first other token, or null. */
	private function significantOffset(TokensList $tokens, int $start, int $step): ?int {
		$offset = $start;

		while ($tokens->exist($offset)) {
			if (!in_array($tokens->tokenAtCursor($offset)->id, self::INSIGNIFICANT, true)) {
				return $offset;
			}

			$offset += $step;
		}

		return null;
	}

	public function parse(TokensList $tokens, Parser $parser): array {
		$keyword = $tokens->tokenAtCursorAndForward();
		$line = $keyword->line;

		return $keyword->text === 'import'
			? $this->parseImport($tokens, $line)
			: $this->parseExport($tokens, $line);
	}

	private function parseImport(TokensList $tokens, int $line): array {
		$token = $this->currentAfterSkipping($tokens);

		if ($token === null) {
			throw new \ParseError("Unexpected end of input after 'import' (line {$line}).");
		}

		if ($token->text === '{') {
			return $this->parseNamedImport($tokens, $line);
		}

		if ($token->text === '*') {
			return $this->parseNamespaceImport($tokens, $line);
		}

		if ($this->isWord($token)) {
			return $this->parseDefaultImport($tokens, $line);
		}

		throw new \ParseError("Unexpected token '{$token->text}' after 'import' (line {$line}).");
	}

	private function parseNamedImport(TokensList $tokens, int $line): array {
		$this->expectText($tokens, '{', $line);

		$specifiers = [];
		while ($this->currentAfterSkipping($tokens)?->text !== '}') {
			$imported = $this->expectIdentifier($tokens, $line)->text;
			$local = $imported;

			if ($this->matchesText($tokens, 'as')) {
				$this->expectText($tokens, 'as', $line);
				$local = $this->expectIdentifier($tokens, $line)->text;
			}

			$specifiers[] = ['imported' => $imported, 'local' => $local];

			if ($this->matchesText($tokens, ',')) {
				$this->expectText($tokens, ',', $line);
				continue;
			}

			break;
		}

		if ($specifiers === []) {
			throw new \ParseError("Expected at least one name inside { … } (line {$line}).");
		}

		$this->expectText($tokens, '}', $line);
		$this->expectText($tokens, 'from', $line);
		$source = $this->expectString($tokens, $line);
		$this->expectText($tokens, ';', $line);

		return [
			'$$type' => 'ImportDeclaration',
			'kind' => 'named',
			'specifiers' => $specifiers,
			'source' => $source,
			'line' => $line,
		];
	}

	private function parseNamespaceImport(TokensList $tokens, int $line): array {
		$this->expectText($tokens, '*', $line);
		$this->expectText($tokens, 'as', $line);
		$local = $this->expectIdentifier($tokens, $line)->text;
		$this->expectText($tokens, 'from', $line);
		$source = $this->expectString($tokens, $line);
		$this->expectText($tokens, ';', $line);

		return [
			'$$type' => 'ImportDeclaration',
			'kind' => 'namespace',
			'local' => $local,
			'source' => $source,
			'line' => $line,
		];
	}

	private function parseDefaultImport(TokensList $tokens, int $line): array {
		$local = $this->expectIdentifier($tokens, $line)->text;
		$this->expectText($tokens, 'from', $line);
		$source = $this->expectString($tokens, $line);
		$this->expectText($tokens, ';', $line);

		return [
			'$$type' => 'ImportDeclaration',
			'kind' => 'default',
			'local' => $local,
			'source' => $source,
			'line' => $line,
		];
	}

	private function parseExport(TokensList $tokens, int $line): array {
		$token = $this->currentAfterSkipping($tokens);

		if ($token === null) {
			throw new \ParseError("Unexpected end of input after 'export' (line {$line}).");
		}

		if ($token->id === T_VARIABLE) {
			return $this->parseExportNamed($tokens, $line);
		}

		if ($token->text === 'default') {
			return $this->parseExportDefault($tokens, $line);
		}

		if ($token->text === '{') {
			return $this->parseExportBraced($tokens, $line);
		}

		throw new \ParseError("Unexpected token '{$token->text}' after 'export' (line {$line}).");
	}

	private function parseExportNamed(TokensList $tokens, int $line): array {
		$name = substr($this->expectVariable($tokens, $line)->text, 1);
		$this->expectText($tokens, ';', $line);

		return ['$$type' => 'ExportDeclaration', 'kind' => 'named', 'name' => $name, 'line' => $line];
	}

	private function parseExportDefault(TokensList $tokens, int $line): array {
		$this->expectText($tokens, 'default', $line);
		$value = substr($this->expectVariable($tokens, $line)->text, 1);
		$this->expectText($tokens, ';', $line);

		return ['$$type' => 'ExportDeclaration', 'kind' => 'default', 'value' => $value, 'line' => $line];
	}

	private function parseExportBraced(TokensList $tokens, int $line): array {
		$this->expectText($tokens, '{', $line);

		$specifiers = [];
		while ($this->currentAfterSkipping($tokens)?->text !== '}') {
			$imported = $this->expectIdentifier($tokens, $line)->text;
			$exported = $imported;

			if ($this->matchesText($tokens, 'as')) {
				$this->expectText($tokens, 'as', $line);
				$exported = $this->expectIdentifier($tokens, $line)->text;
			}

			$specifiers[] = ['imported' => $imported, 'exported' => $exported];

			if ($this->matchesText($tokens, ',')) {
				$this->expectText($tokens, ',', $line);
				continue;
			}

			break;
		}

		if ($specifiers === []) {
			throw new \ParseError("Expected at least one name inside { … } (line {$line}).");
		}

		$this->expectText($tokens, '}', $line);

		if (!$this->matchesText($tokens, 'from')) {
			throw new \ParseError("export { … } requires a from source; export the variable directly instead: export \$X; (line {$line}).");
		}

		$this->expectText($tokens, 'from', $line);
		$source = $this->expectString($tokens, $line);
		$this->expectText($tokens, ';', $line);

		return [
			'$$type' => 'ExportDeclaration',
			'kind' => 'reexport',
			'specifiers' => $specifiers,
			'source' => $source,
			'line' => $line,
		];
	}

	// Consuming token helpers. Every one of these skips whitespace/comments first,
	// per the grammar note that they are allowed between all tokens.

	private function skipInsignificant(TokensList $tokens): void {
		while ($tokens->exist() && in_array($tokens->tokenAtCursor()->id, self::INSIGNIFICANT, true)) {
			$tokens->tokenAtCursorAndForward();
		}
	}

	private function currentAfterSkipping(TokensList $tokens): ?Token {
		$this->skipInsignificant($tokens);

		return $tokens->tokenAtCursor();
	}

	private function matchesText(TokensList $tokens, string $text): bool {
		$token = $this->currentAfterSkipping($tokens);

		return $token !== null && $token->text === $text;
	}

	private function expectText(TokensList $tokens, string $text, int $line): Token {
		$token = $this->currentAfterSkipping($tokens);

		if ($token === null || $token->text !== $text) {
			$found = $token === null ? 'end of input' : "'{$token->text}'";
			throw new \ParseError("Expected '{$text}' but found {$found} (line {$line}).");
		}

		return $tokens->tokenAtCursorAndForward();
	}

	private function expectIdentifier(TokensList $tokens, int $line): Token {
		$token = $this->currentAfterSkipping($tokens);

		if (!$this->isWord($token)) {
			$found = $token === null ? 'end of input' : "'{$token->text}'";
			throw new \ParseError("Expected an identifier but found {$found} (line {$line}).");
		}

		return $tokens->tokenAtCursorAndForward();
	}

	private function expectVariable(TokensList $tokens, int $line): Token {
		$token = $this->currentAfterSkipping($tokens);

		if ($token === null || $token->id !== T_VARIABLE) {
			$found = $token === null ? 'end of input' : "'{$token->text}'";
			throw new \ParseError("Expected a variable but found {$found} (line {$line}).");
		}

		return $tokens->tokenAtCursorAndForward();
	}

	private function expectString(TokensList $tokens, int $line): string {
		$token = $this->currentAfterSkipping($tokens);

		if ($token === null || $token->id !== T_CONSTANT_ENCAPSED_STRING) {
			$found = $token === null ? 'end of input' : "'{$token->text}'";
			throw new \ParseError("Expected a string literal but found {$found} (line {$line}).");
		}

		$tokens->tokenAtCursorAndForward();

		return substr($token->text, 1, -1);
	}
}
