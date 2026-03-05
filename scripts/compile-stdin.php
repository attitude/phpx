<?php declare(strict_types=1);

/**
 * PHPX Compiler - stdin/stdout mode
 *
 * Reads PHPX source from stdin and writes compiled PHP to stdout.
 * Used by the PHPX VS Code extension for real-time compilation.
 *
 * Usage: echo '<phpx source>' | php compile-stdin.php
 */

// Try autoload from this project first, then from a parent project's vendor
$autoloadPaths = [
    __DIR__.'/../vendor/autoload.php',
    __DIR__.'/../../../../autoload.php', // When installed as a Composer dependency
];

$autoloaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    fwrite(STDERR, "Could not find vendor/autoload.php\n");
    exit(1);
}

use Attitude\PHPX\Compiler\Compiler;

$input = file_get_contents('php://stdin');

if ($input === false || $input === '') {
    fwrite(STDERR, "No input provided\n");
    exit(1);
}

$compiler = new Compiler();

try {
    $compiled = $compiler->compile($input);
    echo $compiled;
} catch (\Throwable $e) {
    fwrite(STDERR, json_encode([
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'file' => $e->getFile(),
    ]));
    exit(1);
}
