<?php declare(strict_types=1);

use Attitude\PHPX\Compiler\AbstractNodeVisitor;
use Attitude\PHPX\Compiler\Compiler;
use Attitude\PHPX\Parser\NodeType;
use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\SyntaxRecognizer;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;

/**
 * Demo recognizer for `%% ... %%`: claims the `%%` opener and consumes up to the
 * matching `%%` closer, producing a custom (string) node type.
 */
final class MacroRecognizer implements SyntaxRecognizer {
	public function claims(TokensList $tokens): bool {
		return $tokens->tokenAtCursorMatches(['%', '%']) !== null;
	}

	public function parse(TokensList $tokens, Parser $parser): array {
		$tokens->tokenAtCursorAndForward(); // %
		$tokens->tokenAtCursorAndForward(); // %

		$inner = [];
		while ($tokens->exist() && $tokens->tokenAtCursorMatches(['%', '%']) === null) {
			$inner[] = $tokens->tokenAtCursorAndForward();
		}

		$tokens->tokenAtCursorAndForward(); // %
		$tokens->tokenAtCursorAndForward(); // %

		return ['$$type' => 'TestMacro', 'tokens' => $inner];
	}
}

/** Lowers the custom `TestMacro` node to a built-in EXPRESSION emitting `time()`. */
final class MacroLowering extends AbstractNodeVisitor {
	public function enterNode(array $node): array|int|null {
		if (($node['$$type'] ?? null) !== 'TestMacro') {
			return null;
		}
		return ['$$type' => NodeType::EXPRESSION, 'value' => new Token(T_STRING, 'time()', 1, 0)];
	}
}

/** Demo recognizer for `@`: claims the marker, then recurses into standard parsing. */
final class AtRecognizer implements SyntaxRecognizer {
	public function claims(TokensList $tokens): bool {
		return $tokens->tokenAtCursorMatches('@') !== null;
	}

	public function parse(TokensList $tokens, Parser $parser): array {
		$marker = $tokens->tokenAtCursorAndForward(); // @
		return ['$$type' => 'AtWrapped', 'marker' => $marker, 'inner' => $parser->parseNext()];
	}
}

/** Broken recognizer: claims `~` but never advances the cursor. */
final class NonAdvancingRecognizer implements SyntaxRecognizer {
	public function claims(TokensList $tokens): bool {
		return $tokens->tokenAtCursorMatches('~') !== null;
	}

	public function parse(TokensList $tokens, Parser $parser): array {
		return ['$$type' => 'Never'];
	}
}

/** Parse PHPX source with the given recognizers. */
function recognizedAstOf(string $source, array $recognizers): array
{
	return (new Parser(recognizers: $recognizers))->parse(new TokensList(Token::tokenize($source)));
}

describe('SyntaxRecognizer', function () {
	it('recognizes a custom construct at the top level', function () {
		$ast = recognizedAstOf('%%time%%', [new MacroRecognizer()]);

		expect($ast)->toHaveCount(1);
		expect($ast[0]['$$type'])->toBe('TestMacro');
	});

	it('compiles a custom construct end-to-end once a visitor lowers it', function () {
		$compiler = new Compiler(
			parser: new Parser(recognizers: [new MacroRecognizer()]),
			visitors: [new MacroLowering()],
		);

		expect($compiler->compile('%%time%%'))->toBe('time()');
	});

	it('recognizes a custom construct inside a block', function () {
		$ast = recognizedAstOf('[%%time%%]', [new MacroRecognizer()]);

		expect($ast[0]['$$type'])->toBe(NodeType::BLOCK);
		expect($ast[0]['children'][0]['$$type'])->toBe('TestMacro');
	});

	it('recognizes a custom construct at a JSX child boundary', function () {
		$ast = recognizedAstOf('<div>{$a}%%x%%</div>', [new MacroRecognizer()]);

		$macros = array_filter(
			$ast[0]['children'],
			fn($child) => is_array($child) && ($child['$$type'] ?? null) === 'TestMacro',
		);

		expect($macros)->toHaveCount(1);
	});

	it('lets a recognizer recurse into standard parsing via parseNext()', function () {
		$ast = recognizedAstOf('@<div>x</div>', [new AtRecognizer()]);

		expect($ast[0]['$$type'])->toBe('AtWrapped');
		expect($ast[0]['inner']['$$type'])->toBe(NodeType::PHPX_ELEMENT);
	});

	it('throws when an unlowered custom node reaches the Compiler', function () {
		$compiler = new Compiler(parser: new Parser(recognizers: [new MacroRecognizer()]));

		expect(fn() => $compiler->compile('%%time%%'))
			->toThrow(\RuntimeException::class, 'must be lowered');
	});

	it('throws when a recognizer claims but does not advance the cursor', function () {
		expect(fn() => recognizedAstOf('~foo', [new NonAdvancingRecognizer()]))
			->toThrow(\LogicException::class, NonAdvancingRecognizer::class);
	});

	it('rejects non-SyntaxRecognizer entries at construction', function () {
		expect(fn() => new Parser(recognizers: ['not a recognizer']))
			->toThrow(\InvalidArgumentException::class, 'SyntaxRecognizer');
	});

	it('produces identical output with no recognizers', function () {
		$source = '<div id="x">hi</div>';
		$plain = (new Compiler())->compile($source);
		$empty = (new Compiler(parser: new Parser(recognizers: [])))->compile($source);

		expect($empty)->toBe($plain);
	});
});
