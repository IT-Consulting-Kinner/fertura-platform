#!/usr/bin/php -q
<?php
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Application;
use App\Console\AppCommandRunner;

// Build the runner with an application and root executable name. AppCommandRunner
// turns a unique-constraint violation (23505) in any command into a warning +
// non-zero exit instead of a stack trace (mirrors UniqueViolationMiddleware on web).
$runner = new AppCommandRunner(new Application(dirname(__DIR__) . '/config'), 'cake');
exit($runner->run($argv));
