<?php

/**
 * Vault grants (issue #20/M3-7, docs/spec/01-sicherheit.md section 2
 * "Freigabe"): accounts waiting for access, accounts that have it.
 *
 * Only components from /admin/designsystem, no JavaScript. Each button is
 * its own small POST form with the CSRF token. Granting is offered only
 * when this session's own vault is unlocked - it is what gets sealed.
 *
 * @var string $csrf
 * @var list<array{id: int, name: string, email: string}> $wartend
 * @var list<array{id: int, name: string, email: string, seit: \DateTimeImmutable, durch: string, aktiv: bool}> $freigegeben
 * @var bool $entsperrt
 * @var int|null $eigeneId
 */
?>
<section>
    <h2>Tresor-Freigaben</h2>

    <p class="gedaempft">
        Erst mit einer Freigabe kann ein Zugang Belege und andere fachliche Daten lesen. Die Freigabe
        versiegelt den Tresor-Schlüssel an den persönlichen Schlüssel des Kontos – dazu muss Ihr eigener
        Tresor in dieser Sitzung entsperrt sein. Nach „Passwort vergessen“ oder einem Entsperren ist
        eine neue Freigabe nötig.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-warnung">
            Ihr Tresor ist in dieser Sitzung nicht entsperrt. Melden Sie sich ab und wieder an, um
            Freigaben zu erteilen. Hat Ihr Zugang selbst keine Freigabe, hilft nur der
            <a href="/admin/wiederherstellen">Wiederherstellungsschlüssel</a> des Vereins.
        </p>
    <?php endif; ?>

    <h3>Ausstehend</h3>
    <?php if ($wartend === []): ?>
        <p class="leer">Keine Freigaben ausstehend.</p>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption>Zugänge, die auf die Freigabe warten</caption>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">E-Mail</th>
                        <th scope="col"><span class="visuell-versteckt">Aktion</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($wartend as $zeile): ?>
                        <tr>
                            <td><a href="/admin/benutzer/<?= e((string) $zeile['id']) ?>"><?= e($zeile['name']) ?></a></td>
                            <td><?= e($zeile['email']) ?></td>
                            <td>
                                <?php if ($entsperrt): ?>
                                    <form method="post" action="/admin/tresor/<?= e((string) $zeile['id']) ?>/freigeben">
                                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                        <button type="submit" class="knopf knopf-primaer">Freigeben</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <h3>Freigegeben</h3>
    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption>Zugänge mit Freigabe</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">E-Mail</th>
                    <th scope="col">Seit</th>
                    <th scope="col">Durch</th>
                    <th scope="col"><span class="visuell-versteckt">Aktion</span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($freigegeben as $zeile): ?>
                    <tr>
                        <td>
                            <a href="/admin/benutzer/<?= e((string) $zeile['id']) ?>"><?= e($zeile['name']) ?></a>
                            <?php if (!$zeile['aktiv']): ?>
                                <span class="marke marke-fehler">Abgelaufen</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($zeile['email']) ?></td>
                        <td><?= e($zeile['seit']->format('d.m.Y')) ?></td>
                        <td><?= e($zeile['durch']) ?></td>
                        <td>
                            <?php if ($zeile['id'] === $eigeneId): ?>
                                <span class="gedaempft">(Sie)</span>
                            <?php else: ?>
                                <form method="post" action="/admin/tresor/<?= e((string) $zeile['id']) ?>/entziehen">
                                    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                                    <button type="submit" class="knopf knopf-gefahr">Entziehen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
