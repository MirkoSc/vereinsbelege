<?php

// Development configuration for the docker environment ONLY.
// In production this file is created by the installer in /shared.
return [
    'debug' => true,
    'db' => [
        'host' => 'db',
        'port' => 3306,
        'name' => 'vereinsbelege',
        'user' => 'vereinsbelege',
        'password' => 'dev-password',
    ],
    'cron_token' => 'dev-cron-token',
];
