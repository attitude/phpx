<?php

use Pest\Repositories\SnapshotRepository;
use Pest\TestSuite;

require_once __DIR__ . '/helpers/warnings.php';
if (!class_exists(\Attitude\PHPX\Logger::class)) {
    require_once __DIR__ . '/helpers/Logger.php';
}
require_once __DIR__ . '/language-server/helpers.php';

$suite = TestSuite::getInstance();

$phpVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

$suite->snapshots = new SnapshotRepository(
    $suite->rootPath,
    implode(DIRECTORY_SEPARATOR, [$suite->rootPath, $suite->testPath]),
    implode(DIRECTORY_SEPARATOR, ['.pest', 'snapshots', $phpVersion]),
);
