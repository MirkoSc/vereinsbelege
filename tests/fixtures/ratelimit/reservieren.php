<?php

declare(strict_types=1);

// Child process of App\Tests\Service\Worker\MailEingangGrenzeTest (issue
// #243, N-17): reserves on its own connection as fast as it can and prints
// how many reservations were granted. Several of these run at the same time.

use App\Repository\RateLimitRepository;
use App\Service\RateLimiter;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

[, $dsn, $user, $password, $runden, $menge, $grenze, $start] = $argv;
$pdo = new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$limiter = new RateLimiter(new RateLimitRepository($pdo), RateLimiter::SUBMISSION_WINDOW_SECONDS);
$jetzt = new DateTimeImmutable('@' . $start);
// Start together: wait for the agreed second.
while (microtime(true) < (float) $start + 1.0) {
    usleep(1000);
}
$gewaehrt = 0;
for ($i = 0; $i < (int) $runden; ++$i) {
    if ($limiter->reserve('parallel', 'w1', (int) $menge, (int) $grenze, $jetzt)) {
        ++$gewaehrt;
    }
}
echo $gewaehrt;
