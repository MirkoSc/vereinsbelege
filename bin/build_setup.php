<?php

declare(strict_types=1);

// Generates setup.php (repo root) from bin/setup.template.php by inlining
// the shared classes - the download/verify/extract code of setup.php and the
// self-updater is literally the same, and so is the .htaccess that locks
// shared/ and releases/ (docs/spec/06-betrieb.md section 1). CI fails when
// setup.php is stale.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
// All of them live in App\Service\Update and depend on nothing else.
$classFiles = [
    $root . '/app/src/Service/Update/ReleaseDownloader.php',
    $root . '/app/src/Service/Update/DenyHtaccess.php',
];
$templateFile = __DIR__ . '/setup.template.php';
$targetFile = $root . '/setup.php';

$template = file_get_contents($templateFile);
if ($template === false) {
    fwrite(STDERR, "Cannot read input files.\n");
    exit(1);
}

$classBodies = [];
foreach ($classFiles as $classFile) {
    $classSource = file_get_contents($classFile);
    if ($classSource === false) {
        fwrite(STDERR, "Cannot read input files.\n");
        exit(1);
    }
    // strip opening tag, strict_types and the namespace declaration; the
    // template provides its own namespace block
    $classBodies[] = trim((string) preg_replace(
        ['/^<\?php\s*/', '/declare\(strict_types=1\);\s*/', '/namespace\s+App\\\\Service\\\\Update;\s*/'],
        '',
        $classSource,
    ));
}

$setup = str_replace('//__SHARED_CODE__', implode("\n\n", $classBodies), $template);

file_put_contents($targetFile, $setup);
echo 'setup.php generated (' . strlen($setup) . " bytes)\n";
exit(0);
