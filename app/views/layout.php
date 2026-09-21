<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php $seitentitel = ($title ?? '') !== '' ? $title . ' – ' . $appName : $appName; ?>
    <title><?= e($seitentitel) ?></title>
    <link rel="stylesheet" href="/css/app.css?v=<?= e($version) ?>">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <?php /* Only external files: script-src 'self' without 'unsafe-inline'
             rules out inline scripts and on* attributes (CLAUDE.md §4).
             Values a script needs travel as data-* attributes. */ ?>
    <?php foreach (($scripts ?? []) as $skript): ?>
        <script src="<?= e($skript) ?>?v=<?= e($version) ?>" defer></script>
    <?php endforeach; ?>
</head>
<body>
<header class="site-header">
    <h1><a href="/" class="brand"><?= e($appName) ?></a></h1>
</header>
<main>
    <?= $content ?>
</main>
<footer class="site-footer">
    <span><?= e($appName) ?></span>
    <span class="version"><?= e($version) ?></span>
</footer>
</body>
</html>
