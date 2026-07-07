<?php declare(strict_types=1);

namespace Attitude\PHPX\Parser;

/**
 * Pluggable syntax recognizer: the parser consults recognizers at every
 * node boundary before falling back to its built-in dispatch, so a construct
 * like JSX becomes just one of the options.
 */
interface SyntaxRecognizer {
	/**
	 * Return true when the tokens at the cursor start a construct this recognizer parses.
	 * MUST NOT move the cursor.
	 */
	public function claims(TokensList $tokens): bool;

	/**
	 * Parse the claimed construct into a node. MUST advance the cursor past the construct.
	 *
	 * The returned node carries '$$type' => NodeType|string. A string '$$type' is a custom
	 * node kind: it travels through NodeTraverser like any node, but must be lowered to a
	 * built-in NodeType node by a NodeVisitor before compilation.
	 */
	public function parse(TokensList $tokens, Parser $parser): array;
}
