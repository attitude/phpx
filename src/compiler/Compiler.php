<?php declare(strict_types=1);

namespace Attitude\PHPX\Compiler;

use Attitude\PHPX\Compiler\Formatter;
use Attitude\PHPX\Parser\NodeType;
use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;
use Psr\Log\LoggerInterface;

final class Compiler {
	private TokensList $tokens;
	private Parser $parser;
	private FormatterInterface $formatter;
	/** @var NodeVisitor[] */
	private array $visitors;
	private string $source;
	private array $ast;
	private string $compiled;

	public function __construct(
		?Parser $parser = null,
		?FormatterInterface $formatter = null,
		private ?LoggerInterface $logger = null,
		array $visitors = [],
	) {
		$this->parser = $parser ?? new Parser(logger: $this->logger);
		$this->formatter = $formatter ?? new Formatter();

		foreach ($visitors as $visitor) {
			if (!$visitor instanceof NodeVisitor) {
				$given = get_debug_type($visitor);
				throw new \InvalidArgumentException("Compiler \$visitors must all be NodeVisitor instances, {$given} given.");
			}
		}
		$this->visitors = $visitors;
	}

	private static function unknownNodeTypeError(mixed $type): \RuntimeException {
		$name = $type instanceof NodeType ? $type->value : (is_string($type) ? $type : get_debug_type($type));
		return new \RuntimeException("Unhandled node type '{$name}'. Custom nodes produced by a SyntaxRecognizer must be lowered to built-in NodeType nodes by a NodeVisitor before compilation.");
	}

	public function compile(string $source): string {
		$this->source = $source;
		$this->tokens = new TokensList(Token::tokenize($source));
		$this->ast = $this->parser->parse($this->tokens);

		if ($this->visitors !== []) {
			$this->ast = (new NodeTraverser(...$this->visitors))->traverse($this->ast);
		}

		$this->compiled = '';

		foreach ($this->ast as $node) {
			$this->compiled .= match ($node['$$type']) {
				NodeType::BLOCK => $this->compileBlock($node),
				NodeType::EXPRESSION => $this->compileExpression($node),
				NodeType::TEMPLATE_LITERAL => $this->compileTemplateLiteral($node),
				NodeType::PHPX_ELEMENT => $this->compilePHPXElement($node),
				NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($node),
				NodeType::PHPX_ATTRIBUTE => $this->compilePHPXAttribute($node),
				default => throw self::unknownNodeTypeError($node['$$type']),
			};
		}
		return $this->compiled;
	}

	private function compileExpression(array $node): string {
		$this->logger?->debug('compileExpression', $node);

		['value' => $value] = $node;

		return $value->text;
	}

	private static function trimChildren(string $string): string {
		if (strstr($string, "\n")) {
			return strtr($string, [", \n" => ",\n"]);
		} else {
			return trim($string, ', ');
		}
	}

	private function compilePHPXChildrenArray(array $children): string {
		$this->logger?->debug('compilePHPXChildrenArray', $children);

		$childrenCount = count($children);
		$parts = [];

		foreach ($children as $index => $child) {
			$parts[] = match ($child['$$type']) {
				NodeType::BLOCK => $this->compileBlock($child) . ', ',
				NodeType::TEMPLATE_LITERAL => $this->compileTemplateLiteral($child) . ', ',
				NodeType::PHPX_ELEMENT => $this->compilePHPXElement($child) . ', ',
				NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($child) . ', ',
				NodeType::PHPX_TEXT => $this->compilePHPXText($child, $index, $childrenCount),
				NodeType::PHPX_EXPRESSION_CONTAINER => $this->compilePHPXExpressionContainer($child) . ', ',
				NodeType::PHPX_COMMENT => $this->compilePHPXComment($child),
				default => throw self::unknownNodeTypeError($child['$$type']),
			};
		}

		return '[' . self::trimChildren(implode('', $parts)) . ']';
	}

	private function compilePHPXFragmentElement(array $node): string {
		$this->logger?->debug('compilePHPXFragmentElement', $node);

		if (($node['$$type'] ?? null) !== NodeType::PHPX_FRAGMENT) {
			throw new \InvalidArgumentException('compilePHPXFragmentElement expected a PHPX_FRAGMENT node.');
		}

		return $this->formatter->formatFragment(
			$this->compilePHPXChildrenArray($node['children'])
		);
	}

	private function compilePHPXAttribute(array $node): string {
		$this->logger?->debug('compilePHPXAttribute', $node);

		[
			'name' => $name,
			'assignment' => $assignment,
			'value' => $value,
		] = $node;

		$nameText = match (is_array($name)) {
			true => implode('', array_map(fn(Token $token) => $token->text, $name)),
			false => $name->text,
		};

		if (!$assignment) {
			if ($value !== true) {
				throw new \InvalidArgumentException('A valueless PHPX attribute must carry value true.');
			}
			return $this->formatter->formatAttributeExpression($nameText, 'true');
		} else if ($assignment->text === '=') {
			if ($value instanceof Token) {
				// An attribute value is the literal characters between the quotes — no
				// escape interpretation (JSX/HTML semantics). Strip the source quotes and
				// re-emit as a single-quoted PHP string so nothing is reinterpreted.
				$literal = substr($value->text, 1, -1);
				return $this->formatter->formatAttributeExpression($nameText, '\'' . addcslashes($literal, "\\'") . '\'');
			} else {
				$expression = $this->compileBlock($value, '(', ')');

				return $this->formatter->formatAttributeExpression($nameText, $expression);
			}
		} else {
			throw new \RuntimeException("Unknown assignment type: {$assignment->text}");
		}
	}

	private function compilePHPXAttributesPropsExpression(array $node): string {
		$this->logger?->debug('compilePHPXAttributesPropsExpression', $node);

		if (($node['$$type'] ?? null) !== NodeType::BLOCK) {
			throw new \InvalidArgumentException('compilePHPXAttributesPropsExpression expected a BLOCK node.');
		}

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
				throw new \RuntimeException("Unknown block child type: " . gettype($child));
			}
		} else {
			return $this->compileBlock($node, '', '');
		}
	}

	private function compilePHPXAttributes(array $attributes): string {
		$this->logger?->debug('compilePHPXAttributes', $attributes);

		$attributes = implode('', array_map(fn(array|Token $value) => match ($value instanceof Token) {
			true => $value->text,
			false => match ($value['$$type']) {
					NodeType::PHPX_ATTRIBUTE => $this->compilePHPXAttribute($value) . ',',
					NodeType::BLOCK => $this->compilePHPXAttributesPropsExpression($value) . ',',
					default => throw new \RuntimeException("Unknown attribute type: {$value['$$type']->value}"),
				}
		}, $attributes));

		if (strstr($attributes, "\n")) {
			return '[' . trim($attributes, ',') . ']';
		} else {
			return '[' . trim($attributes, ' ,') . ']';
		}
	}

	private function compilePHPXElement(array $node): string {
		$this->logger?->debug('compilePHPXElement', $node);

		if (($node['$$type'] ?? null) !== NodeType::PHPX_ELEMENT || !isset($node['openingElement'][1]) || !is_array($node['openingElement'])) {
			throw new \InvalidArgumentException('compilePHPXElement expected a PHPX_ELEMENT node with an openingElement.');
		}

		[
			'$$type' => $type,
			'openingElement' => [, $name],
			'selfClosing' => $selfClosing,
			'attributes' => $attributes,
			'children' => $children,
			'closingElement' => $closingElement,
		] = ['children' => [], ...$node];

		$nameText = is_array($name)
			? implode('', array_map(fn(Token $t) => $t->text, $name))
			: $name->text;

		return $this->formatter->formatElement(
			$nameText,
			(!empty($attributes) ? $this->compilePHPXAttributes($attributes) : null),
			(!empty($children) ? $this->compilePHPXChildrenArray($children) : null),
		);
	}

	private static function concatenateStringMembers(array $array): array {
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

	private function compilePHPXExpressionContainer(array $node): string {
		$this->logger?->debug('compilePHPXExpressionContainer', $node);

		['expression' => $expression] = $node;

		if (!is_array($expression) || ($expression['$$type'] ?? null) !== NodeType::BLOCK) {
			throw new \InvalidArgumentException('A PHPX expression container must wrap a BLOCK node.');
		}
		['children' => $children] = $expression;

		$code = '';

		$children = self::concatenateStringMembers(array_map(fn(mixed $child) => match ($child instanceof Token) {
			true => $child->text,
			false => $child,
		}, $children));

		foreach ($children as $i => $child) {
			if (is_string($child)) {
				$code .= $child;
			} else {
				$code .= match ($child['$$type']) {
					NodeType::BLOCK => $this->compileBlock($child),
					NodeType::PHPX_ELEMENT => $this->compilePHPXElement($child),
					NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($child),
					default => throw self::unknownNodeTypeError($child['$$type']),
				};
			}
		}

		return "({$code})";
	}

	private function compileBlock(array $node, ?string $replaceOpening = null, ?string $replaceClosing = null): string {
		$this->logger?->debug('compileBlock', $node);

		if (($node['$$type'] ?? null) !== NodeType::BLOCK) {
			throw new \InvalidArgumentException('compileBlock expected a BLOCK node.');
		}

		['opening' => $opening, 'children' => $children, 'closing' => $closing] = $node;

		if (!$opening instanceof Token || !is_array($children) || !$closing instanceof Token) {
			throw new \InvalidArgumentException('A BLOCK node must have a Token opening, list children, and a Token closing.');
		}

		return ($replaceOpening !== null ? $replaceOpening : $opening->text)
			. implode('', array_map(fn(array|Token $value) => match ($value instanceof Token) {
				true => $value->text,
				default => match ($value['$$type']) {
						NodeType::BLOCK => $this->compileBlock($value),
						NodeType::PHPX_ELEMENT => $this->compilePHPXElement($value),
						NodeType::PHPX_FRAGMENT => $this->compilePHPXFragmentElement($value),
						NodeType::PHPX_EXPRESSION_CONTAINER => $this->compilePHPXExpressionContainer($value),
						NodeType::TEMPLATE_LITERAL => $this->compileTemplateLiteral($value),
						default => throw self::unknownNodeTypeError($value['$$type']),
					},
			}, $children))
			. ($replaceClosing !== null ? $replaceClosing : $closing->text);
	}

	private function compileTemplateLiteral(array $node): string {
		$this->logger?->debug('compileTemplateLiteral', $node);

		['children' => $children] = $node;

		$children = array_map(fn(mixed $child) => match ($child instanceof Token) {
			true => '\'' . addcslashes($child->text, "\\'") . '\'',
			false => match ($child['$$type']) {
					NodeType::BLOCK => $this->compileBlock($child, '(', ')'),
					default => throw new \RuntimeException("Unknown child type: {$child['$$type']}"),
				},
		}, $children);

		return implode('.', $children);
	}

	private function compilePHPXComment(array $node): string {
		$this->logger?->debug('compilePHPXComment', $node);

		['comment' => $comment] = $node;

		return $comment->text;
	}

	private function compilePHPXText(array $node, int $index, int $count): string {
		$this->logger?->debug('compilePHPXText', [$node]);

		['tokens' => $tokens] = $node;

		$isFirst = $index === 0;
		$isLast = $index === $count - 1;

		if (count($tokens) === 1 && $tokens[0]->id === T_WHITESPACE && ($isFirst || $isLast || strstr($tokens[0]->text, "\n"))) {
			return $tokens[0]->text;
		}

		$text = implode('', array_map(fn($t) => $t->text, $tokens));

		if ($isFirst) {
			$text = ltrim($text, ' ');
		} elseif ($isLast) {
			$text = rtrim($text, ' ');
		}

		return '\'' . addcslashes($text, "\\'") . '\', ';
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
