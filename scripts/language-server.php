#!/usr/bin/env php
<?php declare(strict_types=1);

/**
 * PHPX Language Server
 *
 * Communicates over stdin/stdout using the Language Server Protocol (JSON-RPC 2.0).
 * Intended to be spawned by a VS Code language client (Node.js bridge).
 */

// The server must block indefinitely on stdin — disable PHP's stream timeout
ini_set('default_socket_timeout', '-1');

// Find autoloader
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',                // This IS the PHPX project
    __DIR__ . '/../../../autoload.php',                 // Installed as Composer dependency (vendor/attitude/phpx/scripts → vendor/autoload.php)
];

$autoloaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require $autoloadPath;
        $autoloaded = true;
        break;
    }
}

if (!$autoloaded) {
    fwrite(STDERR, "Could not find autoload.php\n");
    exit(1);
}

use Attitude\PHPX\LanguageServer\Server;

// Log to stderr (stdout is reserved for LSP communication)
$logToStderr = in_array('--debug', $argv, true);

$logger = null;
if ($logToStderr) {
    $logger = new class implements \Psr\Log\LoggerInterface {
        use \Psr\Log\LoggerTrait;

        public function log($level, string|\Stringable $message, array $context = []): void
        {
            $contextStr = $context ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
            fwrite(STDERR, "[phpx-ls] [{$level}] {$message}{$contextStr}\n");
        }
    };
}

$server = new Server(logger: $logger);
$server->run();
