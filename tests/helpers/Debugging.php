<?php declare(strict_types = 1);

trait Debugging {
  private static int $debugging;

	public static function setDebugging(int|bool $active): void {
		if (is_int($active) && $active < 0) {
			throw new \InvalidArgumentException('Debugging level must be a positive integer or a boolean');
		}

		static::$debugging = (int) $active;
	}

	public static function getDebugging(): int {
		return static::$debugging;
	}

  public static function debugging(int $flags = 0): bool {
		return self::$debugging > 0;
	}

	public static function pauseDebugging(): void {
		static::$debugging = -1 * abs(static::$debugging);
	}

	public static function resumeDebugging(): void {
		static::$debugging = abs(static::$debugging);
	}

  protected function __log(string|callable|null $message = null): void {
		static $stackSize;

    if (is_callable($message)) {
			$message = $message();
		}

		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 0);
		array_shift($trace);
		array_shift($trace);

		foreach ($trace as $i => &$step) {
			if (isset($step['file'])) {
				$step['file'] = str_replace(getcwd(), '', $step['file'])
					.':'.$step['line'];

				if (preg_match('/^\/(vendor|tests)\//', $step['file'])) {
					unset($trace[$i]);
				} else if (isset($step['class'])) {
					if (str_ends_with($step['class'], 'LogProxy')) {
						$step['class'] = str_ireplace('LogProxy', '', $step['class']);
					} else {
						$step['class'] = str_ireplace('PHPX\\PHPX\\', '', $step['class']);
					}

					$step['function'] = $step['class'].$step['type'].$step['function'];
					$step['file'] = str_pad("{$step['class']}::{$step['function']} ", 40, '-')."› ".$step['file'];
				}
			} else {
				$step['file'] = "fn => {$step['class']}::{$step['function']}";
			}
		}

		$trace = array_values($trace);

		$files = implode("\n - ", array_map(fn ($step) => $step['file'], $trace));

		[
			'class' => $class,
			'file' => $file,
			'function' => $function,
			'line' => $line,
			'type' => $type,
		] = [
			'class' => '',
			'type' => '',
			'line' => '',
			...array_shift($trace),
		];

    $functions = array_column($trace, 'function');
		$functions = array_filter($functions, fn ($function) => !strstr($function, '{closure}'));
		$functions = array_reverse($functions);
		$previousStackSize = $stackSize ?? null;
		$stackSize = count($functions);

		if ($previousStackSize !== null && $previousStackSize > $stackSize) {
			echo "\n\n";
		}

		$functions = implode(' › ', $functions);

		if (strstr($function, '->__return')) {
			$function = 'return';
		}

		echo "\033[34m{$functions}\033[0m\n‹ \033[32m{$function}\033[0m({$message})\033[32m\033[0m\033[90m\n - {$files}\033[0m\n";
  }
}
