<?php

// End-to-end test configuration (tests/e2e/worker/run.sh) - no secret: the
// database exists only inside the test's compose network for one run, the
// server key is a fixed test value like the dev one in docker/shared/.
return [
    'debug' => true,
    'db' => [
        'host' => 'db',
        'port' => 3306,
        'name' => 'vereinsbelege_e2e',
        'user' => 'root',
        'password' => 'e2e-root',
    ],
    'server_key' => 'ZTJlLXNlcnZlci1rZXktMzItYnl0ZXMtbG9uZyEhISE=',
    'cron_token' => 'e2e-cron-token',
];
