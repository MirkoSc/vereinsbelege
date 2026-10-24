<?php

/**
 * User list (issue #20/M3-7, docs/spec/01-sicherheit.md sections 2 and 4).
 *
 * Only components from /admin/designsystem; the table scrolls sideways on
 * narrow screens like every other table. Addresses and names are decrypted
 * operating data (server key) - shown to an account with `admin.users`, and
 * nowhere else. An address of null is ciphertext under a lost server key
 * (restore without config.php, issue #154) - the row says "nicht lesbar"
 * and the form offers to set a new one.
 *
 * @var list<array{id: int, name: string, email: ?string, rollen: list<string>, status: array{0: string, 1: string}, tresor: array{0: string, 1: string}, ablauf: ?\DateTimeImmutable, letzteAnmeldung: ?\DateTimeImmutable}> $zeilen
 * @var int|null $eigeneId
 */
?>
<section>
    <h2>Benutzer</h2>

    <p class="gedaempft">
        Neue Benutzer werden per Mail eingeladen und legen ihr Passwort selbst fest. Belege sehen sie
        erst nach der Freigabe für den Tresor (Verwaltung → Tresor). Gesperrte und abgelaufene
        Zugänge können sich nicht anmelden.
    </p>

    <p class="knopfreihe">
        <a class="knopf knopf-primaer" href="/admin/benutzer/neu">Benutzer einladen</a>
    </p>

    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption>Alle Benutzer</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">E-Mail</th>
                    <th scope="col">Rollen</th>
                    <th scope="col">Status</th>
                    <th scope="col">Tresor</th>
                    <th scope="col">Zugang bis</th>
                    <th scope="col">Letzte Anmeldung</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($zeilen as $zeile): ?>
                    <tr>
                        <td>
                            <a href="/admin/benutzer/<?= e((string) $zeile['id']) ?>"><?= e($zeile['name']) ?></a>
                            <?php if ($zeile['id'] === $eigeneId): ?>
                                <span class="gedaempft">(Sie)</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($zeile['email'] === null): ?>
                                <span class="marke marke-warnung">nicht lesbar</span>
                            <?php else: ?>
                                <?= e($zeile['email']) ?>
                            <?php endif; ?>
                        </td>
                        <td><?= e(implode(', ', $zeile['rollen'])) ?></td>
                        <td><span class="marke <?= e($zeile['status'][1]) ?>"><?= e($zeile['status'][0]) ?></span></td>
                        <td><span class="marke <?= e($zeile['tresor'][1]) ?>"><?= e($zeile['tresor'][0]) ?></span></td>
                        <td><?= e($zeile['ablauf']?->format('d.m.Y') ?? '–') ?></td>
                        <td><?= e($zeile['letzteAnmeldung']?->format('d.m.Y H:i') ?? '–') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
