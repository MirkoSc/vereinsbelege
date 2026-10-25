<?php
// Docroot shim - written by setup.php on a fresh install and kept up
// to date by the updater. Do not edit.
$basis = dirname(__DIR__);
$release = $basis . '/current/public/index.php';
$pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Plain HTTP -> HTTPS (security review N-11, docs/betrieb.md section 2).
// Only GET and HEAD - a redirected POST would lose its body - and never
// /cron, which a hoster's cron may call over plain HTTP. Local hosts stay
// on HTTP (docker compose). The request counts as HTTPS when the web
// server says so (HTTPS, REQUEST_SCHEME, port 443) or a proxy in front
// sends X-Forwarded-Proto: https - that header can only switch the
// redirect off for the request carrying it, never mark a request as
// secure for the application, so it cannot be abused. If a host still
// loops, shared/https_umleitung_aus.flag (created by FTP) switches it off.
$https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
$host = (string) ($_SERVER['HTTP_HOST'] ?? '');
if (($https === '' || $https === 'off')
    && (string) ($_SERVER['SERVER_PORT'] ?? '') !== '443'
    && strtolower((string) ($_SERVER['REQUEST_SCHEME'] ?? '')) !== 'https'
    && !str_contains(strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')), 'https')
    && in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)
    && rtrim($pfad, '/') !== '/cron'
    && preg_match('/^([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*)(?::\d{1,5})?$/D', $host, $teile) === 1
    && !in_array(strtolower($teile[1]), ['localhost', '127.0.0.1'], true)
    && !is_file($basis . '/shared/https_umleitung_aus.flag')) {
    header('Location: https://' . $teile[1] . ($_SERVER['REQUEST_URI'] ?? '/'), true, 301);
    exit;
}

// /admin stays reachable while the flag is set - the update step chain runs
// there - and so do its stylesheet and scripts, otherwise the page that is
// supposed to end the maintenance would arrive unstyled and without the
// JavaScript that drives the steps.
$durchlassen = str_starts_with($pfad, '/admin')
    || str_starts_with($pfad, '/css/')
    || str_starts_with($pfad, '/js/');

// Missing release = mid-switch or a crashed one; the flag = update in
// progress. Nothing gets through while the release itself is gone - there is
// nothing to serve it with.
if (!is_file($release)
    || (is_file($basis . '/shared/maintenance.flag') && !$durchlassen)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Retry-After: 30');
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
        . '<body><h1>Kurze Wartungspause</h1><p>Die Belegverwaltung wird gerade aktualisiert. '
        . 'Bitte in einer Minute erneut laden.</p></body></html>';
    exit;
}

require $release;
