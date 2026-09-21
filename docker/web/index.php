<?php
// Docroot shim - written by setup.php on a fresh install and kept up
// to date by the updater. Do not edit.
$basis = dirname(__DIR__);
$release = $basis . '/current/public/index.php';
$pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

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
