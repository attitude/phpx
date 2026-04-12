/**
 * Grammar structure validation.
 *
 * Tokenization is tested via `pnpm test:grammar` which runs vscode-tmgrammar-test
 * against the fixture files in src/test/grammar/. That tool actually tokenizes
 * the grammar and compares scopes — it cannot run inside the VS Code extension host.
 */

import * as path from 'path';
import * as fs from 'fs';
import * as assert from 'assert';

function validateGrammarStructure(): void {
	const grammarPath = path.resolve(
		__dirname,
		'../../syntaxes/phpx.tmLanguage.json',
	);
	const grammarContent = fs.readFileSync(grammarPath, 'utf-8');

	let grammar: any;
	try {
		grammar = JSON.parse(grammarContent);
	} catch (e) {
		throw new Error(`Invalid grammar JSON: ${e}`);
	}

	assert.ok(grammar.name, 'Grammar must have a name');
	assert.ok(grammar.scopeName, 'Grammar must have a scopeName');
	assert.ok(Array.isArray(grammar.patterns) && grammar.patterns.length > 0, 'Grammar must have a non-empty patterns array');
	assert.ok(grammar.repository && Object.keys(grammar.repository).length > 0, 'Grammar must have a non-empty repository');
}

suite('PHPX Grammar', () => {
	test('grammar JSON has valid structure', () => {
		validateGrammarStructure();
	});
});
