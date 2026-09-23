<?php

/**
 * Banner "N Freigaben ausstehend" (docs/spec/01-sicherheit.md section 2
 * "Freigabe", issue #20/M3-7). Only for an account that may grant the vault
 * (App\Http\LoginGuard counts only then), on every page behind the login
 * except the grant page itself, which lists them anyway.
 *
 * @var int $ausstehendeFreigaben
 * @var string $pfad
 */
$anzahl = $ausstehendeFreigaben ?? 0;
?>
<?php if ($anzahl > 0 && $pfad !== '/admin/tresor'): ?>
    <p class="hinweis hinweis-warnung">
        <?= e($anzahl === 1 ? '1 Freigabe ausstehend' : $anzahl . ' Freigaben ausstehend') ?>:
        <?= e($anzahl === 1 ? 'Ein Zugang wartet' : 'Zugänge warten') ?> auf die Freigabe für den Tresor.
        <a href="/admin/tresor">Jetzt prüfen</a>
    </p>
<?php endif; ?>
