<?php

/**
 * The DATEV export (issue #93, E-49, docs/spec/05-auswertung-und-export.md
 * section 4): the period as a plain GET form, what the Buchungsstapel would
 * hold and what is still missing, and "Herunterladen" as a POST form
 * carrying the period as hidden fields plus the CSRF token - so the token
 * never lands in a URL.
 *
 * Only components from /admin/designsystem, no page-specific CSS or
 * JavaScript. Account names need the vault; without it the page shows the
 * notice only.
 *
 * @var bool $entsperrt
 * @var \App\Service\Export\DatevZeitraum $zeitraum
 * @var string|null $fehler what is wrong with the period
 * @var \App\Service\Export\DatevPlan|null $plan null without vault or with $fehler
 * @var \App\Service\Export\DatevEinstellungen $einstellungen
 * @var string $csrf
 * @var bool $darfEinstellen holds `admin.settings`
 */

?>
<section>
    <h2>DATEV-Export</h2>

    <p class="gedaempft">
        Lädt die Buchungen aller Konten und Kassen eines Zeitraums als
        DATEV-Buchungsstapel (CSV) für den Steuerberater herunter. Die Datei
        entsteht beim Herunterladen; auf dem Server bleibt keine Kopie.
        Die Belege selbst gibt es im <a href="/app/export">ZIP-Export</a>.
    </p>

    <?php if (!$einstellungen->istVollstaendig()): ?>
        <p class="hinweis hinweis-warnung">
            Berater- und Mandantennummer fehlen noch.
            <?php if ($darfEinstellen): ?>
                <a href="/admin/datev">DATEV-Einstellungen öffnen</a>
            <?php else: ?>
                Bitte wenden Sie sich an die Administration.
            <?php endif; ?>
        </p>
    <?php elseif ($darfEinstellen): ?>
        <p><a href="/admin/datev">Sachkonten und Nummern ändern</a></p>
    <?php endif; ?>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Buchungen sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um sie zu exportieren.
        </p>
    <?php else: ?>
        <form method="get" action="/app/datev" class="formular">
            <label for="datev-von" class="feld-kurz">Von
                <input type="date" id="datev-von" name="von" value="<?= e($zeitraum->von->format('Y-m-d')) ?>" required>
            </label>
            <label for="datev-bis" class="feld-kurz">Bis
                <input type="date" id="datev-bis" name="bis" value="<?= e($zeitraum->bis->format('Y-m-d')) ?>" required>
            </label>
            <p class="feld-hilfe">Buchungsdatum, innerhalb eines Kalenderjahres. Ohne Angabe gilt das laufende Jahr.</p>

            <p class="knopfreihe">
                <button type="submit" class="knopf">Vorschau aktualisieren</button>
            </p>
        </form>

        <?php if ($fehler !== null): ?>
            <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
        <?php elseif ($plan !== null): ?>
            <h3>Inhalt des Exports</h3>
            <?php if ($plan->zeilen === [] && $plan->fehlend() === 0): ?>
                <div class="leer">In diesem Zeitraum gibt es keine Buchungen.</div>
            <?php else: ?>
                <div class="karte">
                    <h4><code><?= e($zeitraum->dateiname()) ?></code></h4>
                    <p>
                        <strong><?= e((string) count($plan->zeilen)) ?> Buchungen</strong>
                    </p>
                    <?php if ($plan->gegenbuchungen > 0 || $plan->nullbetraege > 0): ?>
                        <p class="gedaempft">
                            <?php if ($plan->gegenbuchungen > 0): ?>
                                <?= e((string) $plan->gegenbuchungen) ?> eingehende Buchung(en) von Umbuchungen zwischen eigenen Konten fehlen bewusst:
                                Eine Umbuchung steht einmal im Stapel – mit ihrer abgehenden Buchung, gegen das empfangende Konto,
                                im Zeitraum, in dem das Geld abging. Mit einem Geldtransitkonto wird jede Hälfte an ihrem Tag gebucht.
                            <?php endif; ?>
                            <?php if ($plan->nullbetraege > 0): ?>
                                <?= e((string) $plan->nullbetraege) ?> Buchung(en) über 0,00 € sind nicht enthalten – DATEV nimmt sie nicht an.
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($plan->ohneFinanzkonto !== []): ?>
                    <p class="hinweis hinweis-warnung">Diesen Konten ist noch kein Sachkonto zugeordnet:</p>
                    <ul>
                        <?php foreach ($plan->ohneFinanzkonto as $kontoId => $anzahl): ?>
                            <li><?= e($plan->kontoNamen[$kontoId] ?? 'Konto Nr. ' . $kontoId) ?> – <?= e((string) $anzahl) ?> Buchung(en)</li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($plan->ohneGegenkonto !== []): ?>
                    <p class="hinweis hinweis-warnung">Für diese Kategorien fehlt das Sachkonto:</p>
                    <ul>
                        <?php foreach ($plan->ohneGegenkonto as $kategorieId => $anzahl): ?>
                            <li>
                                <?php if ($kategorieId === 0): ?>
                                    ohne Kategorie – <?= e((string) $anzahl) ?> Buchung(en); in den <a href="/app/buchungen?<?= e(http_build_query([...$zeitraum->alsFelder(), 'kategorie' => 'ohne'])) ?>">Buchungen</a> einordnen oder ein Klärungskonto festlegen
                                <?php else: ?>
                                    <?= e($plan->kategorieNamen[$kategorieId] ?? 'Kategorie Nr. ' . $kategorieId) ?> – <?= e((string) $anzahl) ?> Buchung(en)
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <?php if ($plan->fremdwaehrung > 0): ?>
                    <p class="hinweis hinweis-warnung">
                        <?= e((string) $plan->fremdwaehrung) ?> Buchung(en) in fremder Währung kann der Export nicht schreiben.
                        Bitte mit dem Steuerberater klären.
                    </p>
                <?php endif; ?>

                <?php if ($plan->zuGross()): ?>
                    <p class="hinweis hinweis-fehler">Zu viele Buchungen für einen Stapel – bitte den Zeitraum verkleinern.</p>
                <?php endif; ?>

                <?php if ($plan->istExportierbar()): ?>
                    <form method="post" action="/app/datev/datei" class="formular">
                        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                        <?php foreach ($zeitraum->alsFelder() as $name => $wert): ?>
                            <input type="hidden" name="<?= e($name) ?>" value="<?= e($wert) ?>">
                        <?php endforeach; ?>
                        <p class="knopfreihe">
                            <button type="submit" class="knopf knopf-primaer">Herunterladen</button>
                        </p>
                        <p class="feld-hilfe">
                            Windows-1252, Semikolon, Format „Buchungsstapel“ (EXTF, Version 13).
                            Der Stapel ist nicht festgeschrieben – der Steuerberater prüft ihn vor dem Buchen.
                        </p>
                    </form>
                <?php elseif ($plan->kopf !== null && $plan->fehlend() > 0): ?>
                    <p class="gedaempft">Der Export lässt sich herunterladen, sobald jede Buchung ein Konto und ein Gegenkonto hat.</p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</section>
