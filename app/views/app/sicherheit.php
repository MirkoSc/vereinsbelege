<?php
/**
 * Status page of the account's own second factor (issue #17/M3-4,
 * docs/spec/01-sicherheit.md section 3). Only components from
 * /admin/designsystem, no page-specific CSS, no JavaScript.
 *
 * @var string $csrf
 * @var \App\Service\Account\MfaMethod|null $mfaMethod
 * @var int $backupCodesUnused
 * @var list<\App\Domain\TrustedDevice> $geraete
 */
?>
<section class="schmal">
    <h2>Sicherheit</h2>

    <h3>Zweiter Faktor</h3>
    <p>
        Aktuelle Methode:
        <strong><?= e($mfaMethod?->bezeichnung() ?? 'nicht eingerichtet') ?></strong>
    </p>
    <p>
        Noch <?= e((string) $backupCodesUnused) ?> von 10 Backup-Codes gültig.
    </p>
    <p class="knopfreihe">
        <a class="knopf" href="/app/sicherheit/einrichten">Methode ändern</a>
        <form method="post" action="/app/sicherheit/backup-codes/neu">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <button type="submit" class="knopf">Backup-Codes neu erzeugen</button>
        </form>
    </p>

    <h3>Gemerkte Geräte</h3>
    <?php if ($geraete === []): ?>
        <div class="leer">Kein Gerät wird derzeit gemerkt.</div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption class="visuell-versteckt">Gemerkte Geräte</caption>
                <thead>
                    <tr>
                        <th scope="col">Gerät</th>
                        <th scope="col">Zuletzt verwendet</th>
                        <th scope="col">Gültig bis</th>
                        <th scope="col"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($geraete as $geraet): ?>
                        <tr>
                            <td><?= e($geraet->label) ?></td>
                            <td><?= e($geraet->lastUsedAt?->format('d.m.Y H:i') ?? 'noch nicht verwendet') ?></td>
                            <td><?= e($geraet->expiresAt->format('d.m.Y')) ?></td>
                            <td>
                                <form method="post" action="/app/sicherheit/geraete/<?= e((string) $geraet->id) ?>/widerrufen">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf knopf-still">Entfernen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
