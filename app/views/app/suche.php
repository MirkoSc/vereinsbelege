<?php

/**
 * The full-text search (issue #94, docs/spec/03-erfassung-und-ki.md
 * section 10). Only components from /admin/designsystem; the hits table
 * scrolls sideways on narrow screens like every other table.
 *
 * The search is a POST form - search terms are club data and stay out of
 * URLs. While the session index is not up to date, #suche-index carries the
 * CSRF token for public/js/suche.js, which runs the build step by step and
 * then sends the form again; never an inline script (CLAUDE.md section 4).
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var \App\Service\Suche\Suchanfrage|null $anfrage
 * @var \App\Service\Suche\SuchindexStand|null $stand
 * @var \App\Service\Suche\Suchergebnis|null $ergebnis
 * @var array<int, string> $kostenstellen every one, by id
 * @var \DateTimeImmutable $heute
 */

$heute ??= new \DateTimeImmutable('today');
?>
<section>
    <h2>Volltextsuche</h2>

    <p class="gedaempft">
        Findet Belege über ihren erkannten Text: die Textebene von PDFs, die Angaben einer E-Rechnung
        und was die KI ausgelesen hat. Alle Suchbegriffe müssen vorkommen; Wörter in
        Anführungszeichen werden als Ganzes gesucht. Groß- und Kleinschreibung spielt keine Rolle.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Die Texte der Belege sind verschlüsselt und nur mit entsperrtem Tresor durchsuchbar.
            Melden Sie sich neu an, um zu suchen.
        </p>
    <?php else: ?>
        <form method="post" action="/app/suche" class="formular" id="suche-formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <label for="suche-q">Suchbegriffe
                <input type="search" id="suche-q" name="q" maxlength="<?= e((string) \App\Service\Suche\Suchanfrage::MAX_ZEICHEN) ?>"
                       value="<?= e($anfrage->eingabe ?? '') ?>" autocomplete="off" required>
            </label>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Suchen</button>
            </p>
        </form>

        <?php if ($stand !== null && !$stand->aktuell()): ?>
            <div id="suche-index" data-csrf="<?= e($csrf) ?>" data-offen="<?= e((string) $stand->offen) ?>"
                 data-schritt="<?= e((string) \App\Service\Suche\Volltextsuche::SCHRITT_DOKUMENTE) ?>"
                 data-erneut="<?= $anfrage !== null && !$anfrage->leer() ? '1' : '0' ?>">
                <p class="hinweis hinweis-info" id="suche-stand" aria-live="polite">
                    Der Suchindex dieser Sitzung wird aufgebaut – noch
                    <?= e(number_format($stand->offen, 0, ',', '.')) ?> <?= $stand->offen === 1 ? 'Beleg' : 'Belege' ?> …
                </p>
                <p class="hinweis hinweis-fehler" id="suche-fehler" hidden></p>
                <noscript>
                    <p class="hinweis hinweis-warnung">Zum Aufbau des Suchindex wird JavaScript benötigt.</p>
                </noscript>
            </div>
        <?php elseif ($anfrage !== null && $anfrage->leer()): ?>
            <p class="hinweis hinweis-warnung">
                Bitte mindestens einen Suchbegriff mit <?= e((string) \App\Service\Suche\Suchanfrage::MIN_ZEICHEN_JE_BEGRIFF) ?> Zeichen eingeben.
            </p>
        <?php endif; ?>

        <?php if ($ergebnis !== null): ?>
            <?php if ($ergebnis->treffer === []): ?>
                <div class="leer">Keine Belege gefunden.</div>
            <?php else: ?>
                <div class="tabelle-rahmen">
                    <table class="tabelle">
                        <caption>Treffer, neueste zuerst</caption>
                        <thead>
                            <tr>
                                <th scope="col">Referenz</th>
                                <th scope="col">Eingang</th>
                                <th scope="col">Status</th>
                                <th scope="col">Mannschaft/Bereich</th>
                                <th scope="col">Fundstelle</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ergebnis->treffer as $treffer): ?>
                                <?php $document = $treffer->item->document; ?>
                                <tr>
                                    <td>
                                        <a href="/app/posteingang/<?= e((string) $document->id) ?>"><?= e($treffer->item->referenz ?? '#' . $document->id) ?></a>
                                    </td>
                                    <td><?= e($treffer->item->eingegangenAm?->format('d.m.Y') ?? '–') ?></td>
                                    <td><?php require __DIR__ . '/posteingang-status.php'; ?></td>
                                    <td><?= e($document->costCenterId === null ? '–' : ($kostenstellen[$document->costCenterId] ?? '?')) ?></td>
                                    <td class="klein"><?php foreach ($treffer->ausschnitt as [$text, $fund]): ?><?= $fund ? '<mark>' . e($text) . '</mark>' : e($text) ?><?php endforeach; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($ergebnis->mehr): ?>
                    <p class="gedaempft">
                        Es gibt weitere Treffer – angezeigt sind die <?= e((string) count($ergebnis->treffer)) ?> neuesten.
                        Bitte die Suche mit weiteren Begriffen eingrenzen.
                    </p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <form method="post" action="/app/suche/neu" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <p class="gedaempft">
                Der Suchindex gilt nur für diese Sitzung, liegt verschlüsselt auf dem Server und wird beim
                Abmelden gelöscht. Neue Belege kommen bei der nächsten Suche von selbst hinzu.
            </p>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-still">Suchindex neu aufbauen</button>
            </p>
        </form>
    <?php endif; ?>
</section>
