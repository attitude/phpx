<?php declare(strict_types=1);

use Attitude\PHPX\Compiler\Compiler;

// First argument is the path to the file
if ($argc < 2) {
    echo "Usage: php compile.php <path>\n";
    exit(1);
}

// Read the file
$file = $argv[1];

// Check if extension is .phpx
if (pathinfo($file, PATHINFO_EXTENSION) !== 'phpx') {
    echo "File must have a .phpx extension\n";
    exit(1);
}

$content = file_get_contents($file);

require_once 'vendor/autoload.php';

$compiler = new Compiler();
$compiled = $compiler->compile($content);

// Write the compiled content to a new file with the same name but .php extension
file_put_contents(substr($file, 0, -1), $compiled);
