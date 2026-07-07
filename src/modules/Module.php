<?php declare(strict_types=1);

namespace Attitude\PHPX\Modules;

/** Lazy namespace wrapper for `import * as local from '...'`: the module is not loaded until first access. */
final class Module implements \ArrayAccess {
	private ?array $exports = null;

	public function __construct(private string $id) {}

	private function exports(): array {
		return $this->exports ??= load($this->id);
	}

	public function offsetGet(mixed $offset): mixed {
		$exports = $this->exports();

		if (!array_key_exists($offset, $exports)) {
			throw new \OutOfBoundsException("Module '{$this->id}' has no export '{$offset}'.");
		}

		return $exports[$offset];
	}

	public function offsetExists(mixed $offset): bool {
		return array_key_exists($offset, $this->exports());
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		throw new \LogicException('Module exports are read-only.');
	}

	public function offsetUnset(mixed $offset): void {
		throw new \LogicException('Module exports are read-only.');
	}
}
