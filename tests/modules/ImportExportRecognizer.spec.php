<?php declare(strict_types=1);

use Attitude\PHPX\Modules\ImportExportRecognizer;
use Attitude\PHPX\Parser\Parser;
use Attitude\PHPX\Parser\Token;
use Attitude\PHPX\Parser\TokensList;

/** Parse a PHPX snippet with the ImportExportRecognizer registered. */
function parseImportExport(string $source): array {
	return (new Parser(recognizers: [new ImportExportRecognizer()]))->parse(new TokensList(Token::tokenize($source)));
}

/** Recursively collect every 'ImportDeclaration'/'ExportDeclaration' $$type found in the AST. */
function declarationTypesIn(array $ast): array {
	$found = [];

	$walk = function (mixed $node) use (&$walk, &$found): void {
		if (!is_array($node)) {
			return;
		}

		if (isset($node['$$type']) && in_array($node['$$type'], ['ImportDeclaration', 'ExportDeclaration'], true)) {
			$found[] = $node['$$type'];
		}

		foreach ($node as $value) {
			if (is_array($value)) {
				$walk($value);
			}
		}
	};

	foreach ($ast as $node) {
		$walk($node);
	}

	return $found;
}

describe('ImportExportRecognizer', function () {
	it('parses a named import with an alias and multiple specifiers', function () {
		$ast = parseImportExport("import { Store as TodoStore, Item } from './store';");

		expect($ast)->toHaveCount(1);
		expect($ast[0])->toBe([
			'$$type' => 'ImportDeclaration',
			'kind' => 'named',
			'specifiers' => [
				['imported' => 'Store', 'local' => 'TodoStore'],
				['imported' => 'Item', 'local' => 'Item'],
			],
			'source' => './store',
			'line' => 1,
		]);
	});

	it('parses a namespace import', function () {
		$ast = parseImportExport("import * as todo from './todo';");

		expect($ast[0])->toBe([
			'$$type' => 'ImportDeclaration',
			'kind' => 'namespace',
			'local' => 'todo',
			'source' => './todo',
			'line' => 1,
		]);
	});

	it('parses a default import', function () {
		$ast = parseImportExport("import logo from './logo';");

		expect($ast[0])->toBe([
			'$$type' => 'ImportDeclaration',
			'kind' => 'default',
			'local' => 'logo',
			'source' => './logo',
			'line' => 1,
		]);
	});

	it('parses a named export', function () {
		$ast = parseImportExport('export $TodoItem;');

		expect($ast[0])->toBe([
			'$$type' => 'ExportDeclaration',
			'kind' => 'named',
			'name' => 'TodoItem',
			'line' => 1,
		]);
	});

	it('parses a default export', function () {
		$ast = parseImportExport('export default $Root;');

		expect($ast[0])->toBe([
			'$$type' => 'ExportDeclaration',
			'kind' => 'default',
			'value' => 'Root',
			'line' => 1,
		]);
	});

	it('parses a re-export with an alias', function () {
		$ast = parseImportExport("export { Store as S } from './store';");

		expect($ast[0])->toBe([
			'$$type' => 'ExportDeclaration',
			'kind' => 'reexport',
			'specifiers' => [
				['imported' => 'Store', 'exported' => 'S'],
			],
			'source' => './store',
			'line' => 1,
		]);
	});

	it('does not claim the runtime import(...) call', function () {
		$ast = parseImportExport("import('./x');");

		expect(declarationTypesIn($ast))->toBe([]);
	});

	it('does not claim $x->import', function () {
		$ast = parseImportExport('$x->import;');

		expect(declarationTypesIn($ast))->toBe([]);
	});

	it('does not claim Foo::import()', function () {
		$ast = parseImportExport('Foo::import();');

		expect(declarationTypesIn($ast))->toBe([]);
	});

	it('does not claim $obj->export', function () {
		$ast = parseImportExport('$obj->export;');

		expect(declarationTypesIn($ast))->toBe([]);
	});

	it('does not claim a plain $import variable', function () {
		$ast = parseImportExport('$import;');

		expect(declarationTypesIn($ast))->toBe([]);
	});

	it('throws a ParseError with the line when the terminating ; is missing', function () {
		expect(fn() => parseImportExport("import { X } from './x'"))
			->toThrow(\ParseError::class, "Expected ';'");
	});

	it('throws a ParseError with the line when from is missing after an import brace list', function () {
		expect(fn() => parseImportExport('import { X };'))
			->toThrow(\ParseError::class, "Expected 'from'");
	});

	it('throws a ParseError when export { X }; has no from source', function () {
		expect(fn() => parseImportExport('export { X };'))
			->toThrow(\ParseError::class, 'export { … } requires a from source; export the variable directly instead: export $X;');
	});

	it('throws a ParseError when import braces are empty', function () {
		expect(fn() => parseImportExport("import { } from './x';"))
			->toThrow(\ParseError::class, 'Expected at least one name inside { … } (line 1).');
	});

	it('throws a ParseError when export braces are empty', function () {
		expect(fn() => parseImportExport("export { } from './x';"))
			->toThrow(\ParseError::class, 'Expected at least one name inside { … } (line 1).');
	});
});
