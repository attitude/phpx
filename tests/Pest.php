<?php

use Pest\Repositories\SnapshotRepository;
use Pest\TestSuite;

$suite = TestSuite::getInstance();

$phpVersion = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

$suite->snapshots = new SnapshotRepository(
    $suite->rootPath,
    implode(DIRECTORY_SEPARATOR, [$suite->rootPath, $suite->testPath]),
    implode(DIRECTORY_SEPARATOR, ['.pest', 'snapshots', $phpVersion]),
);
