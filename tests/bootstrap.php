<?php

declare(strict_types=1);

// Same first line as app/src/bootstrap.php and public/index.php (issue #97):
// the tests must not be the one place where a trace keeps its arguments.
// ExceptionArgsTest flips it on purpose and restores it afterwards.
ini_set('zend.exception_ignore_args', '1');
ini_set('zend.exception_string_param_max_len', '0');

error_reporting(E_ALL);
date_default_timezone_set('Europe/Berlin');

require dirname(__DIR__) . '/vendor/autoload.php';
