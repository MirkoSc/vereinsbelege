<?php

/**
 * Audit log (issue #21/M3-8, docs/spec/01-sicherheit.md section 6): the
 * filtered list and the integrity check of the hash chain.
 *
 * Only components from /admin/designsystem; the table scrolls sideways on
 * narrow screens like every other table. The check runs as a chain of
 * short requests, driven by public/js/audit.js - never by an inline script
 * (script-src 'self', CLAUDE.md section 4); the CSRF token travels as a
 * data-* attribute.
 *
 * @var string $csrf
 * @var \App\Service\Audit\AuditFilter $filter
 * @var list<\App\Domain\AuditAction> $aktionen
 * @var list<string> $entities
 * @var array<int, string> $namen
 * @var list<array{id: int, zeit: \DateTimeImmutable, benutzer: ?string, aktion: string, objekt: ?string, ip: ?string, details: list<string>|null}> $zeilen
 * @var string|null $naechste
 * @var bool $blaettert
 * @var bool $entsperrt
 * @var int $anzahl
 */
?>
<section>
    <h2>Audit-Log</h2>

    <p class="gedaempft">
        Jede Anmeldung, jede Änderung an Konten, Rollen, Freigaben und Einstellungen wird hier
        protokolliert. Einträge lassen sich nicht bearbeiten; jede nachträgliche Veränderung in der
        Datenbank erkennt die Integritätsprüfung.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Die Details der Einträge sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
        </p>
    <?php endif; ?>

    <form method="get" action="/app/audit" class="formular">
        <label for="audit-aktion">Aktion
            <select id="audit-aktion" name="aktion">
                <option value="">Alle</option>
                <?php foreach ($aktionen as $aktion): ?>
                    <option value="<?= e($aktion->value) ?>"<?= $filter->aktion === $aktion ? ' selected' : '' ?>><?= e($aktion->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="audit-benutzer">Ausgeführt von
            <select id="audit-benutzer" name="benutzer">
                <option value="">Alle</option>
                <?php foreach ($namen as $id => $name): ?>
                    <option value="<?= e((string) $id) ?>"<?= $filter->userId === $id ? ' selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="audit-von" class="feld-kurz">Von
            <input type="date" id="audit-von" name="von" value="<?= e($filter->von?->format('Y-m-d') ?? '') ?>">
        </label>

        <label for="audit-bis" class="feld-kurz">Bis
            <input type="date" id="audit-bis" name="bis" value="<?= e($filter->bis?->format('Y-m-d') ?? '') ?>">
        </label>

        <label for="audit-entity">Betrifft
            <select id="audit-entity" name="entity">
                <option value="">Alles</option>
                <?php foreach ($entities as $entity): ?>
                    <option value="<?= e($entity) ?>"<?= $filter->entity === $entity ? ' selected' : '' ?>><?= e(\App\Domain\AuditAction::entityLabel($entity)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label for="audit-entity-id" class="feld-kurz">Nummer
            <input type="text" id="audit-entity-id" name="entity_id" inputmode="numeric"
                   value="<?= e($filter->entityId === null ? '' : (string) $filter->entityId) ?>">
        </label>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Filtern</button>
            <?php if ($filter->aktiv()): ?>
                <a class="knopf" href="/app/audit">Filter zurücksetzen</a>
            <?php endif; ?>
        </p>
    </form>

    <?php if ($zeilen === []): ?>
        <div class="leer"><?= $filter->aktiv() || $blaettert ? 'Keine passenden Einträge.' : 'Noch keine Einträge.' ?></div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption>Einträge, neueste zuerst</caption>
                <thead>
                    <tr>
                        <th scope="col">Nr.</th>
                        <th scope="col">Zeit</th>
                        <th scope="col">Ausgeführt von</th>
                        <th scope="col">Aktion</th>
                        <th scope="col">Betrifft</th>
                        <th scope="col">Details</th>
                        <th scope="col"><abbr title="Gekürzter, verschlüsselter Hash der IP-Adresse – gleiche Kennung heißt gleiche Adresse">IP-Kennung</abbr></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($zeilen as $zeile): ?>
                        <tr>
                            <td><?= e((string) $zeile['id']) ?></td>
                            <td><?= e($zeile['zeit']->format('d.m.Y H:i:s')) ?></td>
                            <td><?= e($zeile['benutzer'] ?? '–') ?></td>
                            <td><?= e($zeile['aktion']) ?></td>
                            <td><?= e($zeile['objekt'] ?? '–') ?></td>
                            <td>
                                <?php if ($zeile['details'] === null): ?>
                                    <span class="gedaempft">verschlüsselt</span>
                                <?php elseif ($zeile['details'] === []): ?>
                                    –
                                <?php else: ?>
                                    <?php foreach ($zeile['details'] as $detail): ?>
                                        <span class="klein"><?= e($detail) ?></span><br>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><?= $zeile['ip'] === null ? '–' : '<code>' . e($zeile['ip']) . '</code>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($naechste !== null || $blaettert): ?>
        <p class="knopfreihe">
            <?php if ($blaettert): ?>
                <a class="knopf knopf-still" href="/app/audit?<?= e(http_build_query($filter->toQuery())) ?>">Neueste Einträge</a>
            <?php endif; ?>
            <?php if ($naechste !== null): ?>
                <a class="knopf" href="<?= e($naechste) ?>">Ältere Einträge</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <div id="audit-pruefung" data-csrf="<?= e($csrf) ?>">
        <h3>Integrität prüfen</h3>
        <p class="gedaempft">
            Rechnet die Hash-Kette aller <?= e(number_format($anzahl, 0, ',', '.')) ?> Einträge nach.
            Am Ende steht ein Kontrollwert: Notieren Sie ihn (etwa im Kassenprüfungsbericht). Stimmt er
            bei der nächsten Prüfung nicht mehr mit einem damals notierten Eintrag überein, wurde das
            Protokoll nachträglich verkürzt oder neu geschrieben.
        </p>
        <p class="knopfreihe">
            <button type="button" id="audit-pruefen" class="knopf">Integrität prüfen</button>
        </p>
        <p id="audit-stand" aria-live="polite"></p>
        <p id="audit-ergebnis" class="hinweis" hidden></p>
    </div>
</section>
