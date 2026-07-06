<?php

declare(strict_types=1);

namespace Attitude\PHPX\Parser;

use Psr\Log\LoggerInterface;

final class Parser {
	private TokensList $tokens;
	private array $ast;
	public function __construct(
		private ?LoggerInterface $logger = null,
	) {
	}

	public function parse(TokensList $tokens): array {
		$this->ast = [];
		$this->tokens = $tokens;

		$this->logger?->debug('Parse TokensList', (array) $this->tokens);
		$this->tokens->rewind();

		while ($this->tokens->exist()) {
			$this->debugCurrentToken(__FUNCTION__);

			$this->ast[] = match ($this->tokens->tokenAtCursor()->id) {
				T_CURLY_OPEN,
				T_DOLLAR_OPEN_CURLY_BRACES,
				TX_CURLY_BRACKET_OPEN,
				TX_PARENTHESIS_OPEN,
				TX_SQUARE_BRACKET_OPEN => $this->parseParentheses(),
				TX_FRAGMENT_ELEMENT_OPEN => $this->parseFragmentElement(),
				TX_ELEMENT_OPENING_OPEN => $this->parseElement(),
				TX_TEMPLATE_LITERAL => $this->parseTemplateLiteral(),
				default => ['$$type' => NodeType::EXPRESSION, 'value' => $this->tokens->tokenAtCursorAndForward()],
			};
		}

		$this->logger?->debug('AST', $this->ast);

		return $this->ast;
	}

	private function parseFragmentElement(): array {
		return [
			'$$type' => NodeType::PHPX_FRAGMENT,
			'openingElement' => $this->tokens->tokenAtCursorAndForward(),
			'children' => $this->parseElementChildren(),
			'closingElement' => match (!!$this->tokens->tokenAtCursorMatches(TX_FRAGMENT_ELEMENT_CLOSING_SEQUENCE)) {
				true => [
					$this->tokens->tokenAtCursorAndForward(),
					$this->tokens->tokenAtCursorAndForward(),
					$this->tokens->tokenAtCursorAndForward(),
				],
				false => throw new \ParseError("Expected fragment element closer"),
			},
		];
	}

	private function parseElement(): array {
		$this->debugCurrentToken(__FUNCTION__);
		$openingElementStart = $this->tokens->tokenAtCursorAndForward();
		assert($openingElementStart->id === TX_ELEMENT_OPENING_OPEN);

		$this->debugCurrentToken(__FUNCTION__);
		$elementName = $this->parseElementName();

		$attributes = $this->parseElementAttributes();

		if ($this->tokens->tokenAtCursorMatches(TX_ELEMENT_SELF_CLOSING_SEQUENCE)) {
			$this->debugCurrentToken(__FUNCTION__);
			$closingElementSlash = $this->tokens->tokenAtCursorAndForward();
			assert($closingElementSlash->text === '/');

			$this->debugCurrentToken(__FUNCTION__);
			$closingElementEnd = $this->tokens->tokenAtCursorAndForward();
			assert($closingElementEnd->text === '>');

			return [
				'$$type' => NodeType::PHPX_ELEMENT,
				'openingElement' => [$openingElementStart, $elementName],
				'selfClosing' => true,
				'attributes' => $attributes,
				'closingElement' => [$closingElementSlash, $closingElementEnd],
			];
		} else if ($this->tokens->tokenAtCursorMatches(TX_ELEMENT_OPENING_CLOSE)) {
			$this->debugCurrentToken(__FUNCTION__);
			$openingElementEnd = $this->tokens->tokenAtCursorAndForward();
			assert($openingElementEnd->id === TX_ELEMENT_OPENING_CLOSE);

			$elementNameText = is_array($elementName)
				? implode('', array_map(fn(Token $t) => $t->text, $elementName))
				: $elementName->text;
			$elementLine = is_array($elementName) ? $elementName[0]->line : $elementName->line;

			$children = $this->parseElementChildren();

			if (!$this->tokens->exist()) {
				throw new \ParseError("Unexpected end of input, expected closing tag for '<{$elementNameText}>' from line {$elementLine}");
			}

			$this->debugCurrentToken(__FUNCTION__);
			$closingElementStart = $this->tokens->tokenAtCursorAndForward();
			assert($closingElementStart->text === TX_ELEMENT_CLOSING_OPEN_SEQUENCE[0]);

			$this->debugCurrentToken(__FUNCTION__);
			$closingElementSlash = $this->tokens->tokenAtCursorAndForward();
			assert($closingElementSlash->text === TX_ELEMENT_CLOSING_OPEN_SEQUENCE[1]);

			$this->debugCurrentToken(__FUNCTION__);
			$closingElementName = $this->parseElementName();
			$closingElementNameText = is_array($closingElementName)
				? implode('', array_map(fn(Token $t) => $t->text, $closingElementName))
				: $closingElementName->text;
			if ($closingElementNameText !== $elementNameText) {
				throw new \ParseError(
					"Expected closing tag to match opening tag name '{$elementNameText}' at line {$elementLine}"
				);
			}

			$this->debugCurrentToken(__FUNCTION__);
			$closingElementEnd = $this->tokens->tokenAtCursorAndForward();
			if ($closingElementEnd === null) {
				throw new \ParseError("Unexpected end of input, expected '>' for closing tag '</{$elementNameText}>' from line {$elementLine}");
			}
			if ($closingElementEnd->id !== TX_ELEMENT_CLOSING_CLOSE) {
				throw new \ParseError("Unexpected token '{$closingElementEnd->text}' in closing tag '</{$elementNameText}>', expected '>' at line {$closingElementEnd->line}");
			}

			return [
				'$$type' => NodeType::PHPX_ELEMENT,
				'openingElement' => [$openingElementStart, $elementName, $openingElementEnd],
				'selfClosing' => false,
				'attributes' => $attributes,
				'children' => $children,
				'closingElement' => is_array($closingElementName)
					? [$closingElementStart, $closingElementSlash, ...$closingElementName, $closingElementEnd]
					: [$closingElementStart, $closingElementSlash, $closingElementName, $closingElementEnd],
			];
		}

		if (!$this->tokens->exist()) {
			$elementNameText = is_array($elementName)
				? implode('', array_map(fn(Token $t) => $t->text, $elementName))
				: $elementName->text;
			$elementLine = is_array($elementName) ? $elementName[0]->line : $elementName->line;
			throw new \ParseError("Unexpected end of input, expected '>' or '/>' for '<{$elementNameText}>' from line {$elementLine}");
		}

		throw new \ParseError("Not implemented");
	}

	private function parseElementName(): Token|array {
		$this->debugCurrentToken(__FUNCTION__);
		if (!$this->tokens->exist()) {
			throw new \ParseError("Unexpected end of input, expected element name");
		}
		// Tag names may be PHP keyword tokens (e.g. <use>, <var>, <list>), not just
		// T_STRING, but must start with a letter or underscore — reject anything else.
		if ($this->tokens->tokenAtCursorIsNameStart() === null) {
			$token = $this->tokens->tokenAtCursor();
			throw new \ParseError("Unexpected token '{$token->text}', expected element name at line {$token->line}");
		}
		$firstToken = $this->tokens->tokenAtCursorAndForward();

		if (!$this->tokens->tokenAtCursorMatches('-') || !$this->tokens->tokenAtCursorIsWord(1)) {
			return $firstToken;
		}

		$name = [$firstToken];
		while ($this->tokens->tokenAtCursorMatches('-') && $this->tokens->tokenAtCursorIsWord(1)) {
			$name[] = $this->tokens->tokenAtCursorAndForward(); // '-'
			$name[] = $this->tokens->tokenAtCursorAndForward(); // T_STRING
		}
		return $name;
	}

	private function parseExpressionContainer(): array {
		$this->debugCurrentToken(__FUNCTION__);
		assert($this->tokens->tokenAtCursorMatches(TX_CURLY_BRACKET_OPEN));

		$expression = $this->parseParentheses();

		if (count($expression['children']) === 1) {
			if (is_array($expression['children'][0]) && $expression['children'][0]['$$type'] === NodeType::TEMPLATE_LITERAL) {
				return $expression['children'][0];
			}

			if ($expression['children'][0]->id === T_COMMENT) {
				return [
					'$$type' => NodeType::PHPX_COMMENT,
					'comment' => $expression['children'][0],
				];
			}
		}

		return [
			'$$type' => NodeType::PHPX_EXPRESSION_CONTAINER,
			'expression' => $expression,
		];
	}

	private function parseTextNode(): array {
		if ($this->tokens->tokenAtCursorMatches(T_WHITESPACE) && strstr($this->tokens->tokenAtCursor()->text, "\n")) {
			$this->debugCurrentToken(__FUNCTION__);
			return ['$$type' => NodeType::PHPX_TEXT, 'tokens' => [$this->tokens->tokenAtCursorAndForward()]];
		}

		$value = [];

		while (
			$this->tokens->exist()
			&& !$this->tokens->tokenAtCursorMatches(TX_FRAGMENT_ELEMENT_OPEN)
			&& !$this->tokens->tokenAtCursorMatches(TX_FRAGMENT_ELEMENT_CLOSING_SEQUENCE)
			&& !$this->tokens->tokenAtCursorMatches(TX_ELEMENT_OPENING_OPEN_SEQUENCE)
			&& !$this->tokens->tokenAtCursorMatches(TX_ELEMENT_CLOSING_OPEN_SEQUENCE)
			&& !$this->tokens->tokenAtCursorMatches(TX_CURLY_BRACKET_OPEN)
		) {
			$this->debugCurrentToken(__FUNCTION__);

			if ($this->tokens->tokenAtCursorMatches(TX_PHP_OPEN_SEQUENCE)) {
				throw new \ParseError("Unexpected PHP opening tag on line {$this->tokens->tokenAtCursor()->line}");
			}

			if ($this->tokens->tokenAtCursorMatches(T_COMMENT)) {
				$this->logger?->debug('Found comment', ['token' => $this->tokens->tokenAtCursor()]);
				$commentToken = $this->tokens->tokenAtCursor();
				$tokenText = $commentToken->text;

				if (str_starts_with($tokenText, '//')) {
					$this->logger?->debug('Token starts with //', ['token' => $this->tokens->tokenAtCursor()]);

					$tokensToInsert = [
						new Token(T_STRING, '//', $commentToken->line, $commentToken->pos),
						...Token::offsetTokenize(substr($tokenText, 2), $commentToken->pos + 2),
					];
					$this->logger?->debug('Tokens to insert', ['tokens' => $tokensToInsert]);

					$this->tokens->replaceTokenAtCursor($tokensToInsert);
				} else if (str_starts_with($tokenText, '#')) {
					$this->logger?->debug('Token starts with #', ['token' => $this->tokens->tokenAtCursor()]);

					$tokensToInsert = [
						new Token(T_STRING, '#', $commentToken->line, $commentToken->pos),
						...Token::offsetTokenize(substr($tokenText, 1), $commentToken->pos + 1),
					];
					$this->logger?->debug('Tokens to insert', ['tokens' => $tokensToInsert]);

					$this->tokens->replaceTokenAtCursor($tokensToInsert);
				} else {
					throw new \ParseError("Unescaped PHP comment found at line {$this->tokens->tokenAtCursor()->line}");
				}
			}

			$value[] = $this->tokens->tokenAtCursorAndForward();
		}

		if ($this->tokens->tokenAtCursor(-1)?->id === T_WHITESPACE && strstr($value[count($value) - 1]->text, "\n")) {
			array_pop($value);
			$this->tokens->move(-1);
		}

		return ['$$type' => NodeType::PHPX_TEXT, 'tokens' => $value];
	}

	private function parseTemplateLiteral(): array {
		$opener = $this->tokens->tokenAtCursorAndForward();
		$children = [];

		while (
			$this->tokens->exist()
			&& !$this->tokens->tokenAtCursorMatches(TX_TEMPLATE_LITERAL)
		) {
			$this->debugCurrentToken(__FUNCTION__);

			$children[] = match ($this->tokens->tokenAtCursor()->id) {
				T_DOLLAR_OPEN_CURLY_BRACES => $this->parseParentheses(),
				default => $this->tokens->tokenAtCursorAndForward(),
			};
		}

		if (!$this->tokens->exist()) {
			throw new \ParseError("Unexpected end of input, expected closing template literal from line {$opener->line}");
		}

		$closer = $this->tokens->tokenAtCursorAndForward();
		assert($closer->id === TX_TEMPLATE_LITERAL);

		return [
			'$$type' => NodeType::TEMPLATE_LITERAL,
			'opening' => $opener,
			'children' => $children,
			'closing' => $closer,
		];
	}

	private function parseElementChildren(): array {
		$children = [];

		while (
			$this->tokens->exist()
			&& !$this->tokens->tokenAtCursorMatches(TX_FRAGMENT_ELEMENT_CLOSING_SEQUENCE)
			&& !$this->tokens->tokenAtCursorMatches(TX_ELEMENT_CLOSING_OPEN_SEQUENCE)
		) {
			$this->debugCurrentToken(__FUNCTION__);

			$children[] = match ($this->tokens->tokenAtCursor()->id) {
				TX_CURLY_BRACKET_OPEN => $this->parseExpressionContainer(),
				TX_PARENTHESIS_OPEN => $this->parseParentheses(),
				TX_FRAGMENT_ELEMENT_OPEN => $this->parseFragmentElement(),
				default => match (!!$this->tokens->tokenAtCursorMatches(TX_ELEMENT_OPENING_OPEN_SEQUENCE)) {
						true => $this->parseElement(),
						false => $this->parseTextNode(),
					},
			};
		}

		return $children;
	}

	private function parseElementAttributes(): array {
		$attributes = [];

		while (
			$this->tokens->exist()
			&& !$this->tokens->tokenAtCursorMatches(TX_ELEMENT_SELF_CLOSING_SEQUENCE)
			&& !$this->tokens->tokenAtCursorMatches(TX_ELEMENT_OPENING_CLOSE)
		) {
			$this->debugCurrentToken(__FUNCTION__);
			$attributes[] = match ($this->tokens->tokenAtCursor()->id) {
				T_WHITESPACE => $this->tokens->tokenAtCursorAndForward(),
				T_STRING => $this->parseElementAttribute(),
				T_CURLY_OPEN,
				TX_CURLY_BRACKET_OPEN => $this->parseParentheses(),
				T_CLASS => throw new \ParseError("Use `className` instead of `class`"),
				default => $this->tokens->tokenAtCursorIsWord()
				? $this->parseElementAttribute()
				: throw new \ParseError($this->unexpectedTokenMessage()),
			};
		}

		return $attributes;
	}

	private function parseElementAttribute(): array {
		$this->debugCurrentToken(__FUNCTION__);

		// Attribute names may be PHP keyword tokens (e.g. for, readonly), not just
		// T_STRING — the caller already guarantees a word token here.
		$name = [$this->tokens->tokenAtCursorAndForward()];

		if ($this->tokens->tokenAtCursorMatches(':')) {
			$name[] = $this->tokens->tokenAtCursorAndForward();
			if ($this->tokens->tokenAtCursorIsWord() === null) {
				throw new \ParseError($this->unexpectedTokenMessage('namespaced attribute name'));
			}
			$name[] = $this->tokens->tokenAtCursorAndForward();
		}

		if ($this->tokens->tokenAtCursorMatches('-')) {
			while ($this->tokens->tokenAtCursorMatches('-') && $this->tokens->tokenAtCursorIsWord(1)) {
				$name[] = $this->tokens->tokenAtCursorAndForward();
				$name[] = $this->tokens->tokenAtCursorAndForward();
			}
		}

		$assignment = null;
		$value = true;

		if ($this->tokens->tokenAtCursorMatches('=')) {
			$assignment = $this->tokens->tokenAtCursorAndForward();
			$value = match ($this->tokens->tokenAtCursor()->id) {
				T_CURLY_OPEN,
				TX_CURLY_BRACKET_OPEN => $this->parseParentheses(),
				T_CONSTANT_ENCAPSED_STRING => $this->tokens->tokenAtCursorAndForward(),
				default => throw new \ParseError($this->unexpectedTokenMessage('attribute "value" or {expression}')),
			};
		} else if (!(
			$this->tokens->tokenAtCursorMatches(T_WHITESPACE)
			|| $this->tokens->tokenAtCursorMatches('/')
			|| $this->tokens->tokenAtCursorMatches('>')
		)) {
			throw new \ParseError($this->unexpectedTokenMessage('"=" (attribute assignment)'));
		}

		return [
			'$$type' => NodeType::PHPX_ATTRIBUTE,
			'name' => $name,
			'assignment' => $assignment,
			'value' => $value,
		];
	}

	private function parseParentheses(): array {
		$this->debugCurrentToken(__FUNCTION__);
		$opener = $this->tokens->tokenAtCursorAndForward();

		$closerId = match ($opener->id) {
			T_CURLY_OPEN => TX_CURLY_BRACKET_CLOSE,
			T_DOLLAR_OPEN_CURLY_BRACES => TX_CURLY_BRACKET_CLOSE,
			TX_CURLY_BRACKET_OPEN => TX_CURLY_BRACKET_CLOSE,
			TX_PARENTHESIS_OPEN => TX_PARENTHESIS_CLOSE,
			TX_SQUARE_BRACKET_OPEN => TX_SQUARE_BRACKET_CLOSE,
			default => throw new \UnhandledMatchError("Unexpected parenthesis opener: {$opener}"),
		};

		$children = [];

		while ($this->tokens->exist() && !$this->tokens->tokenAtCursorMatches($closerId)) {
			$this->debugCurrentToken(__FUNCTION__);

			$children[] = match ($this->tokens->tokenAtCursor()->id) {
				T_CURLY_OPEN,
				T_DOLLAR_OPEN_CURLY_BRACES,
				TX_CURLY_BRACKET_OPEN,
				TX_PARENTHESIS_OPEN,
				TX_SQUARE_BRACKET_OPEN => $this->parseParentheses(),
				TX_FRAGMENT_ELEMENT_OPEN => $this->parseFragmentElement(),
				TX_ELEMENT_OPENING_OPEN => $this->tokens->tokenAtCursorIsNameStart(1)
				? $this->parseElement()
				: $this->tokens->tokenAtCursorAndForward(),
				TX_TEMPLATE_LITERAL => $this->parseTemplateLiteral(),
				default => $this->tokens->tokenAtCursorAndForward(),
			};
		}

		if (!$this->tokens->exist()) {
			$closerText = chr($closerId);
			throw new \ParseError("Unexpected end of input, expected closing '{$closerText}' for '{$opener->text}' from line {$opener->line}");
		}

		$this->debugCurrentToken(__FUNCTION__);
		$closer = $this->tokens->tokenAtCursorAndForward();

		return [
			'$$type' => NodeType::BLOCK,
			'opening' => $opener,
			'children' => $children,
			'closing' => $closer,
		];
	}

	private function unexpectedTokenMessage(?string $expected = null): string {
		$token = $this->tokens->tokenAtCursor();
		if ($token === null) {
			return "Unexpected end of input" . ($expected ? ", expected {$expected}" : '');
		}
		return "Unexpected token #{$this->tokens->index()} => {$token} at line {$token->line}" . (
			$expected ? ", expected {$expected} instead" : ''
		);
	}

	private function debugCurrentToken(string $method): void {
		$token = $this->tokens->tokenAtCursor();

		if ($token === null) {
			$this->logger?->debug("Token#{$this->tokens->index()}", [
				'text' => '(end of input)',
				'name' => '(end of input)',
				'method' => $method,
			]);
			return;
		}

		$this->logger?->debug("Token#{$this->tokens->index()}", [
			'text' => $token->text,
			'name' => $token->getTokenName(),
			'method' => $method,
		]);
	}
}
