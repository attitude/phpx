<?php declare(strict_types=1);

use function Attitude\PHPX\Modules\compile;

/** Compile a fixture snippet with the shared App.phpx/aliases/packages used across this spec. */
function compileFixture(string $source): string {
	return compile(
		$source,
		sourceFile: __DIR__ . '/fixtures/src/App.phpx',
		rootDir: __DIR__ . '/fixtures',
		aliases: ['#/' => 'src/'],
		packages: ['attitude/phpx-server' => 'Attitude\\PHPX\\Server'],
	);
}

describe('Modules\compile', function () {
	it('lowers a named import', function () {
		$php = compileFixture("<?php\nimport { TodoItem, TodoList } from './components';\n");

		expect($php)->toBe("<?php\n['TodoItem' => \$TodoItem, 'TodoList' => \$TodoList] = \\Attitude\\PHPX\\Modules\\load('src/components.phpx');\n");
	});

	it('lowers an aliased named import', function () {
		$php = compileFixture("<?php\nimport { Store as TodoStore } from './store';\n");

		expect($php)->toBe("<?php\n['Store' => \$TodoStore] = \\Attitude\\PHPX\\Modules\\load('src/store.phpx');\n");
	});

	it('lowers a namespace import', function () {
		$php = compileFixture("<?php\nimport * as todo from './todo';\n");

		expect($php)->toBe("<?php\n\$todo = \\Attitude\\PHPX\\Modules\\module('src/todo.phpx');\n");
	});

	it('lowers a default import from a module', function () {
		$php = compileFixture("<?php\nimport logo from './logo';\n");

		expect($php)->toBe("<?php\n['default' => \$logo] = \\Attitude\\PHPX\\Modules\\load('src/logo.phpx');\n");
	});

	it('lowers an asset default import with the default handler', function () {
		$php = compileFixture("<?php\nimport viteLogo from './assets/vite.svg';\n");

		expect($php)->toBe("<?php\n\$viteLogo = 'src/assets/vite.svg';\n");
	});

	it('lowers a package import to a first-class-callable assignment', function () {
		$php = compileFixture("<?php\nimport { Suspense } from 'attitude/phpx-server';\n");

		expect($php)->toBe("<?php\n\$Suspense = \\Attitude\\PHPX\\Server\\Suspense(...);\n");
	});

	it('lowers a named import of a keyword-cased name', function () {
		$php = compileFixture("<?php\nimport { List } from './components';\n");

		expect($php)->toBe("<?php\n['List' => \$List] = \\Attitude\\PHPX\\Modules\\load('src/components.phpx');\n");
	});

	it('lowers a default import with a keyword-cased local', function () {
		$php = compileFixture("<?php\nimport List from './logo';\n");

		expect($php)->toBe("<?php\n['default' => \$List] = \\Attitude\\PHPX\\Modules\\load('src/logo.phpx');\n");
	});

	it('resolves the #/ alias', function () {
		$php = compileFixture("<?php\nimport { Store } from '#/store';\n");

		expect($php)->toBe("<?php\n['Store' => \$Store] = \\Attitude\\PHPX\\Modules\\load('src/store.phpx');\n");
	});

	it('vanishes export statements and appends a single return with named, default and re-export entries', function () {
		$php = compileFixture("<?php\nexport \$TodoItem;\nexport default \$Root;\nexport { Store } from './store';\n");

		expect($php)->toBe(
			"<?php\n\n\n\n"
			. "\nreturn ['TodoItem' => \$TodoItem, 'default' => \$Root, 'Store' => \\Attitude\\PHPX\\Modules\\load('src/store.phpx')['Store']];\n"
		);
	});

	it('compiles an import alongside JSX', function () {
		$php = compileFixture("<?php\nimport { TodoItem } from './components';\n<TodoItem name={\$n} />;\n");

		expect($php)->toContain("\\Attitude\\PHPX\\Modules\\load('src/components.phpx')");
		expect($php)->toContain("'\$'");
	});

	it('throws when a module cannot be found', function () {
		expect(fn() => compileFixture("<?php\nimport { X } from './nope';\n"))
			->toThrow(\RuntimeException::class, "Module not found: './nope' imported from src/App.phpx:2");
	});

	it('throws for an unknown package specifier', function () {
		expect(fn() => compileFixture("<?php\nimport { X } from 'unknown/pkg';\n"))
			->toThrow(\RuntimeException::class, "Unknown package specifier 'unknown/pkg' imported from src/App.phpx:2");
	});

	it('throws when import/export is nested inside a function body', function () {
		expect(fn() => compileFixture("<?php\nfunction f() {\n\timport { X } from './components';\n}\n"))
			->toThrow(\RuntimeException::class, 'import/export must be at the top level of the module (src/App.phpx:3)');
	});

	it('throws on a duplicate export name', function () {
		expect(fn() => compileFixture("<?php\nexport \$X;\nexport \$X;\n"))
			->toThrow(\RuntimeException::class, "Duplicate export 'X' in src/App.phpx:3");
	});

	it('throws when a namespace import targets an asset', function () {
		expect(fn() => compileFixture("<?php\nimport * as logo from './assets/vite.svg';\n"))
			->toThrow(\RuntimeException::class, "Only a default import is allowed for asset './assets/vite.svg' imported from src/App.phpx:2");
	});
});
