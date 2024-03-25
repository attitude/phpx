<?php declare(strict_types = 1);

namespace PHPX\PHPX;

use Psr\Log\LoggerInterface;

final class Compiler {
	public ?LoggerInterface $logger = null;
	protected TokensList $tokens;
	protected Parser $parser;
	protected Formatter $formatter;
	protected string $source;
	protected array $ast;
	protected string $compiled;

	public function __construct(
		Parser $parser = null,
		Formatter $formatter = null,
	) {
		$this->parser = $parser ?? new Parser();
		$this->formatter = $formatter ?? new Formatter();
	}

	public function compile(string $source): string {
		$this->tokens = new TokensList(Token::tokenize($source));
		$this->ast = $this->parser->parse($this->tokens);
		$this->compiled = '';

		foreach ($this->ast as $node) {
			$this->compiled .= match($node['$$type']) {
				NodeType::BLOCK => $this->compileBlock($node),
				NodeType::EXPRESSION => $this->compileExpression($node),
				NodeType::TEMPLATE_LITERAL => $this->compileTemplateLiteral($node),
				NodeType::PHPX_ELEMENT => $this->compilePHPXElement($node),
				NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($node),
				NodeType::PHPX_ATTRIBUTE => $this->compilePHPXAttribute($node),
				default => throw new \RuntimeException("Unknown node type: {$node['$$type']}"),
			};
		}
		return $this->compiled;
	}

	protected function compileExpression(array $node): string {
		$this->logger?->debug('compileExpression', $node);

		['value' => $value] = $node;

		return $value->text;
	}

	protected static function trimChildren(string $string): string {
		if (strstr($string, "\n")) {
			return strtr($string, [", \n" => ",\n"]);
		} else {
			return trim($string, ', ');
		}
	}

	protected function compilePHPXChildrenArray(array $children): string {
		$this->logger?->debug('compilePHPXChildrenArray', $children);

		return '['.self::trimChildren(implode('', array_map(fn(array|Token $child) => match($child['$$type']) {
			NodeType::BLOCK => $this->compileBlock($child).', ',
			NodeType::PHPX_ELEMENT => $this->compilePHPXElement($child).', ',
			NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($child).', ',
			NodeType::PHPX_TEXT => $this->compilePHPXText($child),
			NodeType::PHPX_EXPRESSION_CONTAINER => $this->compilePHPXExpressionContainer($child).', ',
			NodeType::PHPX_COMMENT => $this->compilePHPXComment($child),
			default => throw new \RuntimeException("Unknown child type: {$child['$$type']->value}"),
		}, $children))).']';
	}

	protected function compilePHPXFragmentElement(array $node): string {
		$this->logger?->debug('compilePHPXFragmentElement', $node);
		assert($node['$$type'] === NodeType::PHPX_FRAGMENT);

		return $this->formatter->formatFragment(
			$this->compilePHPXChildrenArray($node['children'])
		);
	}

	protected function compilePHPXAttribute(array $node): string {
		$this->logger?->debug('compilePHPXAttribute', $node);

		[
			'name' => $name,
			'assignment' => $assignment,
			'value' => $value,
		] = $node;

		if (!$assignment) {
			assert($value === true);
			return $this->formatter->formatAttributeExpression($name->text, 'true');
		} else if ($assignment->text === '=') {
			if ($value instanceof Token) {
				return $this->formatter->formatAttributeExpression($name->text, $value->text);
			} else {
				$expression = $this->compileBlock($value, '(', ')');

				return $this->formatter->formatAttributeExpression($name->text, $expression);
			}
		} else {
			throw new \RuntimeException("Unknown assignment type: {$assignment->text}");
		}
	}

	protected function compilePHPXAttributesPropsExpression(array $node): string {
		$this->logger?->debug('compilePHPXAttributesPropsExpression', $node);
		assert($node['$$type'] === NodeType::BLOCK);

		['children' => $children] = $node;

		if (count($children) === 1) {
			$child = $children[0];

			if ($child instanceof Token) {
				// Prop punning:
				if ($child->id === T_VARIABLE) {
					$attribute = strtolower(substr($child->text, 1));

					return $this->formatter->formatAttributeExpression($attribute, $child->text);
				} else {
					throw new \RuntimeException("Unknown block child type: {$child}");
				}
			} else {
				throw new \RuntimeException("Unknown block child type: ".gettype($child));
			}
		} else {
			return $this->compileBlock($node, '', '');
		}
	}

	protected function compilePHPXAttributes(array $attributes): string {
		$this->logger?->debug('compilePHPXAttributes', $attributes);

		return '['.trim(implode('', array_map(fn (array|Token $value) => match($value instanceof Token) {
			true => $value->text,
			false => match($value['$$type']) {
				NodeType::PHPX_ATTRIBUTE => $this->compilePHPXAttribute($value).',',
				NodeType::BLOCK => $this->compilePHPXAttributesPropsExpression($value).',',
				default => throw new \RuntimeException("Unknown attribute type: {$value['$$type']->value}"),
			}
		}, $attributes)), ' ,').']';
	}

	protected function compilePHPXElement(array $node): string {
		$this->logger?->debug('compilePHPXElement', $node);

		[
			'$$type' => $type,
			'openingElement' => [, $name],
			'selfClosing' => $selfClosing,
			'attributes' => $attributes,
			'children' => $children,
			'closingElement' => $closingElement,
		] = ['children' => [], ...$node];

		return $this->formatter->formatElement(
			$name->text,
			(!empty($attributes) ? $this->compilePHPXAttributes($attributes) : null),
			(!empty($children) ? $this->compilePHPXChildrenArray($children) : null),
		);
	}

	protected static function combineStringArrayMembers(array $array): array {
		$combinedArray = [];
		$currentString = '';

		foreach ($array as $item) {
			if (is_string($item)) {
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

	protected function compilePHPXExpressionContainer(array $node): string {
		$this->logger?->debug('compilePHPXExpressionContainer', $node);

		['expression' => $expression] = $node;
		assert(is_array($expression) && $expression['$$type'] === NodeType::BLOCK);
		['children' => $children] = $expression;

		$code = '';

		$children = self::combineStringArrayMembers(array_map(fn (mixed $child) => match($child instanceof Token) {
			true => $child->text,
			false => $child,
		}, $children));

		foreach ($children as $i => $child) {
			if (is_string($child)) {
				$code .= $child;
			} else {
				$code .= match($child['$$type']) {
					NodeType::BLOCK => $this->compileBlock($child),
					NodeType::PHPX_ELEMENT => $this->compilePHPXElement($child),
					NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($child),
					default => throw new \RuntimeException("Unknown child type: {$child['$$type']}"),
				};
			}
		}

		return "({$code})";
	}

	protected function compileBlock(array $node, ?string $replaceOpening =null, ?string $replaceClosing = null): string {
		$this->logger?->debug('compileBlock', $node);
		assert($node['$$type'] === NodeType::BLOCK);

		['opening' => $opening, 'children' => $children, 'closing' => $closing] = $node;

		assert($opening instanceof Token);
		assert(is_array($children));
		assert($closing instanceof Token);

		return ($replaceOpening !== null ? $replaceOpening : $opening->text)
			.implode('', array_map(fn(array|Token $value) => match($value instanceof Token) {
				true => $value->text,
				default => match($value['$$type']) {
					NodeType::BLOCK => $this->compileBlock($value),
					NodeType::PHPX_ELEMENT => $this->compilePHPXElement($value),
					NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($value),
					NodeType::PHPX_EXPRESSION_CONTAINER => $this->compilePHPXExpressionContainer($value),
					default => throw new \RuntimeException("Unknown child type: {$value['$$type']}"),
				},
			}, $children))
			.($replaceClosing !== null ? $replaceClosing : $closing->text);
	}

	protected function compileTemplateLiteral(array $node): string {
		$this->logger?->debug('compileTemplateLiteral', $node);

		['children' => $children] = $node;

		$children = array_map(fn (mixed $child) => match($child instanceof Token) {
			true => "'{$child->text}'",
			false => match($child['$$type']) {
				NodeType::BLOCK => $this->compileBlock($child, '(', ')'),
				default => throw new \RuntimeException("Unknown child type: {$child['$$type']}"),
			},
		}, $children);

		return implode('.', $children);
	}

	protected function compilePHPXComment(array $node): string {
		$this->logger?->debug('compilePHPXComment', $node);

		['comment' => $comment] = $node;

		return $comment->text;
	}

	protected function compilePHPXText(array $node): string {
		$this->logger?->debug('compilePHPXText', [$node]);

		['tokens' => $tokens] = $node;

		if (count($tokens) === 1) {
			$token = $tokens[0];

			if ($token->id === T_WHITESPACE) {
				return $token->text;
			} else {
				return '\''.$token->text.'\', ';
			}
		} else {
			return '\''.implode('', array_map(fn($token) => $token->text, $tokens)).'\', ';
		}
	}

	public function __toString(): string {
		return $this->compiled;
	}

	public function getAST(): array {
		return $this->ast;
	}

	public function getCompiled(): string {
		return $this->compiled;
	}

	public function getSource(): string {
		return $this->source;
	}

	public function getTokens(): TokensList {
		return $this->tokens;
	}
}
