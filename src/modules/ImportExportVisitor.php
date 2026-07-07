<?php declare(strict_types=1);

namespace Attitude\PHPX\Modules;

use Attitude\PHPX\Compiler\AbstractNodeVisitor;
use Attitude\PHPX\Parser\NodeType;
use Attitude\PHPX\Parser\Token;

/**
 * Lowers 'ImportDeclaration'/'ExportDeclaration' custom nodes (produced by
 * ImportExportRecognizer) into plain PHP EXPRESSION nodes, and records what a
 * module exports so functions::compile() can append the module's `return [...]`.
 */
final class ImportExportVisitor extends AbstractNodeVisitor {
	private int $depth = 0;
	private array $exports = [];

	public function __construct(
		private string $sourceFile,
		private string $rootDir,
		private array $aliases = [],
		private array $packages = [],
		private ?\Closure $assetHandler = null,
	) {
		$rootDirReal = realpath($this->rootDir);
		if ($rootDirReal === false || !is_dir($rootDirReal)) {
			throw new \InvalidArgumentException("rootDir '{$this->rootDir}' must be an existing directory.");
		}

		$sourceFileReal = realpath($this->sourceFile);
		if ($sourceFileReal === false || !is_file($sourceFileReal)) {
			throw new \InvalidArgumentException("sourceFile '{$this->sourceFile}' must be an existing file.");
		}

		$this->rootDir = $rootDirReal;
		$this->sourceFile = $sourceFileReal;
	}

	public function exports(): array {
		return $this->exports;
	}

	public function enterNode(array $node): array|int|null {
		$isTopLevel = $this->depth === 0;
		$this->depth++;

		$type = $node['$$type'] ?? null;
		if ($type !== 'ImportDeclaration' && $type !== 'ExportDeclaration') {
			return null;
		}

		if (!$isTopLevel) {
			throw new \RuntimeException("import/export must be at the top level of the module ({$this->relativeSourceFile()}:{$node['line']})");
		}

		return $type === 'ImportDeclaration'
			? $this->lowerImport($node)
			: $this->lowerExport($node);
	}

	public function leaveNode(array $node): array|int|null {
		$this->depth--;

		return null;
	}

	private function relativeSourceFile(): string {
		return $this->relativePath($this->sourceFile);
	}

	private function relativePath(string $absolutePath): string {
		$relative = ltrim(substr($absolutePath, strlen($this->rootDir)), '/\\');

		return str_replace('\\', '/', $relative);
	}

	private function toExpression(string $php, int $line): array {
		return [
			'$$type' => NodeType::EXPRESSION,
			'value' => new Token(T_STRING, $php, $line, 0),
		];
	}

	// Resolution shared by imports and re-exports.

	private function isRelativeOrAliased(string $source): bool {
		if (str_starts_with($source, './') || str_starts_with($source, '../')) {
			return true;
		}

		foreach (array_keys($this->aliases) as $prefix) {
			if (str_starts_with($source, $prefix)) {
				return true;
			}
		}

		return false;
	}

	private function resolvePath(string $source): string {
		if (str_starts_with($source, './') || str_starts_with($source, '../')) {
			return dirname($this->sourceFile) . DIRECTORY_SEPARATOR . $source;
		}

		$longestPrefix = null;
		foreach (array_keys($this->aliases) as $prefix) {
			if (str_starts_with($source, $prefix) && ($longestPrefix === null || strlen($prefix) > strlen($longestPrefix))) {
				$longestPrefix = $prefix;
			}
		}

		$mapped = $this->aliases[$longestPrefix] . substr($source, strlen($longestPrefix));

		return $this->rootDir . DIRECTORY_SEPARATOR . $mapped;
	}

	/** @return array{type: 'package', namespace: string}|array{type: 'module'|'asset', moduleId: string} */
	private function resolve(string $source, int $line): array {
		if (!$this->isRelativeOrAliased($source)) {
			if (!array_key_exists($source, $this->packages)) {
				throw new \RuntimeException("Unknown package specifier '{$source}' imported from {$this->relativeSourceFile()}:{$line}.");
			}

			return ['type' => 'package', 'namespace' => $this->packages[$source]];
		}

		$path = $this->resolvePath($source);
		$resolvedRealPath = null;

		foreach ([$path, $path . '.phpx', $path . '.php'] as $candidate) {
			$real = realpath($candidate);
			if ($real !== false && is_file($real)) {
				$resolvedRealPath = $real;
				break;
			}
		}

		if ($resolvedRealPath === null) {
			throw new \RuntimeException("Module not found: '{$source}' imported from {$this->relativeSourceFile()}:{$line}");
		}

		if (!str_starts_with($resolvedRealPath, $this->rootDir . DIRECTORY_SEPARATOR)) {
			throw new \RuntimeException("Module '{$source}' imported from {$this->relativeSourceFile()}:{$line} resolves outside the project root.");
		}

		$moduleId = $this->relativePath($resolvedRealPath);
		$extension = strtolower(pathinfo($resolvedRealPath, PATHINFO_EXTENSION));

		return $extension === 'phpx' || $extension === 'php'
			? ['type' => 'module', 'moduleId' => $moduleId]
			: ['type' => 'asset', 'moduleId' => $moduleId];
	}

	// Import lowering.

	private function lowerImport(array $node): array {
		$line = $node['line'];
		$kind = $node['kind'];
		$resolved = $this->resolve($node['source'], $line);

		if ($resolved['type'] === 'package') {
			if ($kind !== 'named') {
				throw new \RuntimeException("Namespace/default import from a package is not supported ({$this->relativeSourceFile()}:{$line}).");
			}

			$ns = $resolved['namespace'];
			$assignments = array_map(
				fn(array $specifier): string => '$' . $specifier['local'] . ' = \\' . $ns . '\\' . $specifier['imported'] . '(...);',
				$node['specifiers'],
			);

			return $this->toExpression(implode(' ', $assignments), $line);
		}

		if ($resolved['type'] === 'asset') {
			if ($kind !== 'default') {
				throw new \RuntimeException("Only a default import is allowed for asset '{$node['source']}' imported from {$this->relativeSourceFile()}:{$line}.");
			}

			$handler = $this->assetHandler ?? fn(string $id): string => var_export($id, true);

			return $this->toExpression('$' . $node['local'] . ' = ' . $handler($resolved['moduleId']) . ';', $line);
		}

		$moduleId = $resolved['moduleId'];
		$php = match ($kind) {
			'named' => $this->lowerNamedImport($node['specifiers'], $moduleId),
			'default' => "['default' => \${$node['local']}] = \\Attitude\\PHPX\\Modules\\load('{$moduleId}');",
			'namespace' => "\${$node['local']} = \\Attitude\\PHPX\\Modules\\module('{$moduleId}');",
		};

		return $this->toExpression($php, $line);
	}

	private function lowerNamedImport(array $specifiers, string $moduleId): string {
		$pairs = array_map(
			fn(array $specifier): string => "'{$specifier['imported']}' => \${$specifier['local']}",
			$specifiers,
		);

		return '[' . implode(', ', $pairs) . "] = \\Attitude\\PHPX\\Modules\\load('{$moduleId}');";
	}

	// Export lowering.

	private function lowerExport(array $node): array {
		$line = $node['line'];

		match ($node['kind']) {
			'named' => $this->recordExport($node['name'], '$' . $node['name'], $line),
			'default' => $this->recordExport('default', '$' . $node['value'], $line),
			'reexport' => $this->lowerReexport($node, $line),
		};

		return $this->toExpression('', $line);
	}

	private function recordExport(string $name, string $expression, int $line): void {
		if (array_key_exists($name, $this->exports)) {
			throw new \RuntimeException("Duplicate export '{$name}' in {$this->relativeSourceFile()}:{$line}");
		}

		$this->exports[$name] = $expression;
	}

	private function lowerReexport(array $node, int $line): void {
		$resolved = $this->resolve($node['source'], $line);

		if ($resolved['type'] !== 'module') {
			throw new \RuntimeException("Re-exporting from a non-module source '{$node['source']}' is not supported ({$this->relativeSourceFile()}:{$line}).");
		}

		$moduleId = $resolved['moduleId'];

		foreach ($node['specifiers'] as $specifier) {
			$expression = "\\Attitude\\PHPX\\Modules\\load('{$moduleId}')['{$specifier['imported']}']";
			$this->recordExport($specifier['exported'], $expression, $line);
		}
	}
}
