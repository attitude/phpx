<?php declare(strict_types=1);

namespace Attitude\PHPX\Modules;

/** @internal shared loader state; state(reset: true) exists for tests. */
function state(bool $reset = false): \stdClass {
	static $state = null;

	if ($state === null || $reset) {
		$state = (object) ['exports' => [], 'loading' => [], 'resolver' => null];
	}

	return $state;
}

function resolver(callable $resolver): void {
	state()->resolver = $resolver;
}

function load(string $id): array {
	$state = state();

	if (array_key_exists($id, $state->exports)) {
		return $state->exports[$id];
	}

	if (in_array($id, $state->loading, true)) {
		throw new \RuntimeException('Circular import: ' . implode(' → ', [...$state->loading, $id]));
	}

	$resolver = $state->resolver ?? throw new \RuntimeException(
		'No module resolver configured. Call \Attitude\PHPX\Modules\resolver(fn (string $id): string => ...) first.',
	);

	$state->loading[] = $id;

	try {
		$exports = require $resolver($id);
	} finally {
		array_pop($state->loading);
	}

	if (!is_array($exports)) {
		throw new \RuntimeException("Module '{$id}' did not return an exports array. A PHPX module must end with `return ['Name' => \$value, ...];` — compile it with \Attitude\PHPX\Modules\compile().");
	}

	$state->exports[$id] = $exports;

	return $exports;
}

function module(string $id): Module {
	return new Module($id);
}

function compile(
	string $source,
	string $sourceFile,
	string $rootDir,
	array $aliases = [],
	array $packages = [],
	?\Closure $assetHandler = null,
	?\Attitude\PHPX\Compiler\FormatterInterface $formatter = null,
): string {
	$visitor = new ImportExportVisitor($sourceFile, $rootDir, $aliases, $packages, $assetHandler);

	$compiler = new \Attitude\PHPX\Compiler\Compiler(
		parser: new \Attitude\PHPX\Parser\Parser(recognizers: [new ImportExportRecognizer()]),
		formatter: $formatter,
		visitors: [$visitor],
	);

	$php = $compiler->compile($source);
	$exports = $visitor->exports();

	if ($exports !== []) {
		$entries = [];
		foreach ($exports as $name => $expression) {
			$entries[] = var_export($name, true) . ' => ' . $expression;
		}

		$php .= "\nreturn [" . implode(', ', $entries) . "];\n";
	}

	return $php;
}
