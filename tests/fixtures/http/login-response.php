<?php

declare(strict_types=1);

// Router script for App\Tests\Http\ResponseSendTest, run under `php -S`.
// It does what a successful login does to the headers (issue #127): start
// the session, regenerate its id in Session::login(), then send a Response
// that sets the vault cookie and the trusted-device cookie on top.
//
// The first request (no cookie) only starts a session, so the second one
// has an existing id to regenerate - like the login form before the POST.

use App\Http\Cookie;
use App\Http\Response;
use App\Http\Session;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$sessionDir = getenv('RESPONSE_SEND_TEST_SESSION_DIR');
if (!is_string($sessionDir) || $sessionDir === '') {
    http_response_code(500);
    exit;
}
session_save_path($sessionDir);

$session = new Session();
$session->start();

if (($_SERVER['REQUEST_URI'] ?? '') !== '/anmelden') {
    new Response(200, [], 'form')->send();

    return;
}

$session->login(42);
Response::redirect('/app')
    ->withCookie(Cookie::vaultKey('vault-key', secure: false))
    ->withCookie(Cookie::trustedDevice('device-token', secure: false, days: 30))
    ->send();
