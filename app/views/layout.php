<?php

/**
 * The one shell around every page. What differs per area comes from
 * $bereich (App\View\Area): navigation, content width, and whether a CSRF
 * token travels with htmx requests.
 *
 * @var \App\View\Area $bereich
 * @var string $content
 * @var string $partialsDir
 */
$seitentitel = ($title ?? '') !== '' ? $title . ' – ' . $appName : $appName;
// The public pages must not start a session (App\Http\Session), so they
// have no token - and then the body carries no hx-headers at all.
$csrfToken = ($bereich !== \App\View\Area::Oeffentlich && ($csrf ?? '') !== '') ? (string) $csrf : null;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($seitentitel) ?></title>
    <?php /* htmx is configured through this meta element and NOT through a
             script: script-src 'self' without 'unsafe-inline' rules inline
             scripts out (CLAUDE.md §4, E-07). The content is JSON that htmx
             parses - nothing is executed.
             includeIndicatorStyles off: htmx would otherwise inject a
             <style> element, which style-src 'self' drops silently; the
             .htmx-indicator rules live in app.css instead.
             selfRequestsOnly: an htmx attribute that ever pointed at a
             foreign host would be a receipt leaving the installation. */ ?>
    <meta name="htmx-config" content='{"includeIndicatorStyles":false,"selfRequestsOnly":true}'>
    <link rel="stylesheet" href="/css/app.css?v=<?= e($version) ?>">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <?php /* Only external files: script-src 'self' without 'unsafe-inline'
             rules out inline scripts and on* attributes (CLAUDE.md §4).
             Values a script needs travel as data-* attributes. */ ?>
    <script src="/js/vendor/htmx.min.js?v=<?= e($version) ?>" defer></script>
    <?php foreach (($scripts ?? []) as $skript): ?>
        <script src="<?= e($skript) ?>?v=<?= e($version) ?>" defer></script>
    <?php endforeach; ?>
</head>
<?php /* hx-headers is plain JSON read by htmx, not code - the CSRF check in
         App\Http\Session accepts exactly this header, so every htmx POST on
         a page with a session is covered without a line of JavaScript. */ ?>
<body class="bereich-<?= e($bereich->value) ?>"<?php
    if ($csrfToken !== null) {
        echo ' hx-headers=\'{"X-CSRF-Token":"' . e($csrfToken) . '"}\'';
    }
?>>
<a class="sprungmarke" href="#inhalt">Zum Inhalt springen</a>

<?php require $partialsDir . '/kopf.php'; ?>

<main id="inhalt" class="inhalt">
    <?php require $partialsDir . '/flash.php'; ?>
    <?= $content ?>
</main>

<?php require $partialsDir . '/fuss.php'; ?>
</body>
</html>
