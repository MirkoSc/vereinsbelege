<?php

/**
 * Role list (M3-6, issue #19, docs/spec/01-sicherheit.md section 4).
 *
 * Only components from /admin/designsystem; the table scrolls sideways on
 * narrow screens like every other table.
 *
 * @var list<\App\Domain\Role> $rollen
 * @var array<int, int> $anzahl role id => number of accounts
 */
?>
<section>
    <h2>Rollen</h2>

    <p class="gedaempft">
        Eine Rolle ist eine Menge von Rechten; ein Benutzer kann mehrere Rollen
        haben und darf dann, was eine davon erlaubt. Die sechs mitgelieferten
        Rollen lassen sich nicht umbenennen oder löschen, ihre Rechte aber
        anpassen – außer bei „Admin“, der immer alle Rechte hat. Welche Rolle
        ein Benutzer hat, wird in der Benutzerverwaltung festgelegt.
    </p>

    <p class="knopfreihe">
        <a class="knopf knopf-primaer" href="/admin/rollen/neu">Neue Rolle</a>
    </p>

    <div class="tabelle-rahmen">
        <table class="tabelle">
            <caption>Alle Rollen</caption>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Art</th>
                    <th scope="col" class="zahl">Rechte</th>
                    <th scope="col" class="zahl">Benutzer</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rollen as $rolle): ?>
                    <tr>
                        <td><a href="/admin/rollen/<?= e((string) $rolle->id) ?>"><?= e($rolle->name) ?></a></td>
                        <td>
                            <?php if ($rolle->istSystem()): ?>
                                <span class="marke">Mitgeliefert</span>
                            <?php endif; ?>
                            <?php if ($rolle->extern): ?>
                                <span class="marke marke-warnung">Extern</span>
                            <?php endif; ?>
                        </td>
                        <td class="zahl"><?= e($rolle->istAdmin() ? 'alle' : (string) count($rolle->rechte())) ?></td>
                        <td class="zahl"><?= e((string) ($anzahl[$rolle->id] ?? 0)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
