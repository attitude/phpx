<?php declare(strict_types=1);

use Attitude\PHPX\Modules\Module;

use function Attitude\PHPX\Modules\compile;
use function Attitude\PHPX\Modules\load;
use function Attitude\PHPX\Modules\module;
use function Attitude\PHPX\Modules\resolver;
use function Attitude\PHPX\Modules\state;

/** Configure the loader to resolve ids as files in fixtures/runtime/. */
function useRuntimeFixturesResolver(): void {
	resolver(fn(string $id): string => __DIR__ . '/fixtures/runtime/' . $id . '.php');
}

describe('Modules loader', function () {
	beforeEach(fn() => state(reset: true));

	it('throws when no resolver is configured', function () {
		expect(fn() => load('anything'))
			->toThrow(\RuntimeException::class, 'No module resolver configured.');
	});

	it('returns the exports array of a module fixture', function () {
		useRuntimeFixturesResolver();

		$exports = load('returns-array');

		expect($exports)->toHaveKey('A');
		expect($exports['A'])->toBeInstanceOf(\Closure::class);
		expect($exports['A']())->toBe('a');
	});

	it('evaluates a module only once and returns the same exports array', function () {
		useRuntimeFixturesResolver();
		unset($GLOBALS['phpx_modules_test_eval_count']);

		$first = load('counts-evaluations');
		$second = load('counts-evaluations');

		expect($GLOBALS['phpx_modules_test_eval_count'])->toBe(1);
		expect($second)->toBe($first);
	});

	it('throws when a module does not return an exports array', function () {
		useRuntimeFixturesResolver();

		expect(fn() => load('no-return'))
			->toThrow(\RuntimeException::class, "Module 'no-return' did not return an exports array.");
	});

	it('detects circular imports', function () {
		useRuntimeFixturesResolver();

		expect(fn() => load('a'))
			->toThrow(\RuntimeException::class, 'Circular import: a → b → a');
	});

	describe('Module', function () {
		it('does not evaluate the module until first access', function () {
			useRuntimeFixturesResolver();
			unset($GLOBALS['phpx_modules_test_module_eval_count']);

			$module = module('module-target');

			expect($module)->toBeInstanceOf(Module::class);
			expect($GLOBALS['phpx_modules_test_module_eval_count'] ?? 0)->toBe(0);

			expect(isset($module['Foo']))->toBeTrue();
			expect($GLOBALS['phpx_modules_test_module_eval_count'])->toBe(1);
			expect($module['Foo'])->toBe('bar');
		});

		it('throws OutOfBoundsException for an unknown export', function () {
			useRuntimeFixturesResolver();

			$module = module('module-target');

			expect(fn() => $module['Missing'])
				->toThrow(\OutOfBoundsException::class, "Module 'module-target' has no export 'Missing'.");
		});

		it('is read-only', function () {
			useRuntimeFixturesResolver();

			$module = module('module-target');

			expect(fn() => $module['Foo'] = 'nope')
				->toThrow(\LogicException::class, 'Module exports are read-only.');
		});
	});

	describe('integration', function () {
		it('round-trips compile → require → exports', function () {
			$source = "<?php\n\$Greet = fn () => 'hello';\nexport \$Greet;\n";
			$php = compile($source, sourceFile: __DIR__ . '/fixtures/src/App.phpx', rootDir: __DIR__ . '/fixtures');

			$base = tempnam(sys_get_temp_dir(), 'phpx-module-');
			$file = $base . '.php';
			file_put_contents($file, $php);

			try {
				resolver(fn(string $id): string => $file);

				$exports = load('greeter');

				expect($exports['Greet'])->toBeInstanceOf(\Closure::class);
				expect($exports['Greet']())->toBe('hello');
			} finally {
				unlink($file);
				unlink($base);
			}
		});
	});
});
