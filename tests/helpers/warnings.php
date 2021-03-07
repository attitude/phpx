<?php declare(strict_types = 1);

set_error_handler(function ($severity, $message, $file, $line) {
	if (str_starts_with($file, getcwd().'/vendor/')) {
		return;
	}

	throw new \ErrorException($message, $severity, $severity, $file, $line);
});
