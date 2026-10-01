<?php

/**
 * Create or change one supplier or payer (M6-2, issue #36,
 * docs/spec/03-erfassung-und-ki.md section 7).
 *
 * Only components from /admin/designsystem, no JavaScript. Several
 * aliases, IBANs and mandate references are textareas with one value per
 * line - that works the same at 360 px and needs no script to add rows.
 *
 * @var string $csrf
 * @var \App\Domain\Supplier|null $lieferant null for a new one
 * @var array<string, string> $felder
 * @var string|null $fehler
 * @var int|null $konfliktId the other supplier of a duplicate identifier
 * @var array<string, array<int, string>> $kategorien group => id => name
 * @var int $verwendungen
 * @var list<\App\Domain\Supplier> $andere the suppliers this one could be merged into
 */

use App\Domain\Iban;
use App\Domain\SupplierRole;
use App\Service\MasterData\SupplierService;

$neu = $lieferant === null;
$ziel = $neu ? '/app/lieferanten' : '/app/lieferanten/' . $lieferant->id;
$text = static fn(string $feld, string $label, int $max = SupplierService::TEXT_MAX, string $extra = ''): string => sprintf(
    '<label for="lieferant-%1$s">%2$s<input type="text" id="lieferant-%1$s" name="%3$s" value="%4$s" maxlength="%5$d"%6$s></label>',
    e(str_replace('_', '-', $feld)),
    e($label),
    e($feld),
    e($felder[$feld] ?? ''),
    $max,
    $extra,
);
?>
<section class="schmal">
    <h2><?= e($neu ? 'Neuer Lieferant' : $lieferant->data->name) ?></h2>

    <p><a href="/app/lieferanten">← Alle Lieferanten</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert">
            <?= e($fehler) ?>
            <?php if ($konfliktId !== null): ?>
                <a href="/app/lieferanten/<?= e((string) $konfliktId) ?>">Zum anderen Lieferanten</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if (!$neu && $lieferant->needsReview): ?>
        <p class="hinweis hinweis-warnung">
            Von der KI angelegt – bitte Angaben prüfen und speichern.
        </p>
    <?php endif; ?>

    <form method="post" action="<?= e($ziel) ?>" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <label for="lieferant-name">Name <span class="pflicht" aria-hidden="true">*</span>
            <input type="text" id="lieferant-name" name="name" value="<?= e($felder['name']) ?>"
                   maxlength="<?= e((string) SupplierService::NAME_MAX) ?>" required>
        </label>

        <label for="lieferant-rolle">Rolle <span class="pflicht" aria-hidden="true">*</span>
            <select id="lieferant-rolle" name="rolle" required aria-describedby="lieferant-rolle-hilfe">
                <?php foreach (SupplierRole::cases() as $rolle): ?>
                    <option value="<?= e($rolle->value) ?>"<?= $felder['rolle'] === $rolle->value ? ' selected' : '' ?>><?= e($rolle->label()) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="feld-hilfe" id="lieferant-rolle-hilfe">
            Lieferanten schicken Rechnungen (Ausgaben), Zahler zahlen an den Verein (Einnahmen).
        </p>

        <label for="lieferant-kategorie">Standard-Kategorie
            <select id="lieferant-kategorie" name="kategorie" aria-describedby="lieferant-kategorie-hilfe">
                <option value=""<?= $felder['kategorie'] === '' ? ' selected' : '' ?>>Keine</option>
                <?php foreach ($kategorien as $gruppe => $eintraege): ?>
                    <optgroup label="<?= e($gruppe) ?>">
                        <?php foreach ($eintraege as $id => $name): ?>
                            <option value="<?= e((string) $id) ?>"<?= $felder['kategorie'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </label>
        <p class="feld-hilfe" id="lieferant-kategorie-hilfe">
            Wird für neue Belege dieses Partners vorgeschlagen. Ein Lieferant bekommt eine Ausgaben-,
            ein Zahler eine Einnahmen-Kategorie.
        </p>

        <label for="lieferant-aliases">Weitere Namen (Aliasse)
            <textarea id="lieferant-aliases" name="aliases" rows="3"
                      aria-describedby="lieferant-aliases-hilfe"><?= e($felder['aliases']) ?></textarea>
        </label>
        <p class="feld-hilfe" id="lieferant-aliases-hilfe">
            Ein Name pro Zeile – so, wie er auf Rechnungen oder Kontoauszügen steht (z. B. „Getränke Müller“
            und „Mueller Getraenkehandel“).
        </p>

        <fieldset>
            <legend>Bankverbindung</legend>
            <label for="lieferant-ibans">IBANs
                <textarea id="lieferant-ibans" name="ibans" rows="2" autocomplete="off" spellcheck="false"
                          aria-describedby="lieferant-ibans-hilfe"><?= e($felder['ibans']) ?></textarea>
            </label>
            <p class="feld-hilfe" id="lieferant-ibans-hilfe">Eine IBAN pro Zeile, Leerzeichen sind egal.</p>
            <?= $text('bic', 'BIC', 20, ' autocomplete="off" spellcheck="false"') ?>
            <?= $text('creditor_id', 'Gläubiger-ID (Lastschrift)', 35, ' autocomplete="off" spellcheck="false"') ?>
            <label for="lieferant-mandate-refs">Mandatsreferenzen
                <textarea id="lieferant-mandate-refs" name="mandate_refs" rows="2" spellcheck="false"
                          aria-describedby="lieferant-mandate-refs-hilfe"><?= e($felder['mandate_refs']) ?></textarea>
            </label>
            <p class="feld-hilfe" id="lieferant-mandate-refs-hilfe">Eine pro Zeile, wie auf dem Kontoauszug.</p>
        </fieldset>

        <fieldset>
            <legend>Steuer</legend>
            <?= $text('vat_id', 'USt-ID', 20, ' autocomplete="off" spellcheck="false"') ?>
            <?= $text('tax_number', 'Steuernummer', SupplierService::TEXT_MAX, ' autocomplete="off" spellcheck="false"') ?>
        </fieldset>

        <fieldset>
            <legend>Kontakt</legend>
            <label for="lieferant-address">Anschrift
                <textarea id="lieferant-address" name="address" rows="3"
                          maxlength="<?= e((string) SupplierService::ADDRESS_MAX) ?>"><?= e($felder['address']) ?></textarea>
            </label>
            <label for="lieferant-email">E-Mail
                <input type="email" id="lieferant-email" name="email" value="<?= e($felder['email']) ?>"
                       maxlength="<?= e((string) SupplierService::TEXT_MAX) ?>">
            </label>
            <?= $text('website', 'Website') ?>
            <?= $text('customer_number', 'Unsere Kundennummer') ?>
        </fieldset>

        <label for="lieferant-notes">Notiz
            <textarea id="lieferant-notes" name="notes" rows="3"
                      maxlength="<?= e((string) SupplierService::NOTES_MAX) ?>"><?= e($felder['notes']) ?></textarea>
        </label>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Speichern</button>
            <a class="knopf" href="/app/lieferanten">Abbrechen</a>
        </p>
    </form>

    <?php if (!$neu): ?>
        <p class="gedaempft">
            <?= e($lieferant->createdVia->label()) ?> am <?= e($lieferant->createdAt->format('d.m.Y')) ?>,
            zuletzt geändert am <?= e($lieferant->updatedAt->format('d.m.Y H:i')) ?>.
        </p>

        <?php if ($andere !== []): ?>
            <h3>Mit anderem Lieferanten zusammenführen</h3>
            <p class="gedaempft">
                Doppelt angelegt? Dann geht dieser Lieferant im gewählten auf: Belege, Name, Aliasse, IBANs
                und die übrigen Angaben wandern dorthin, dieser verschwindet aus der Liste.
                Vorher zeigt eine Vorschau das Ergebnis.
            </p>
            <form method="get" action="/app/lieferanten/<?= e((string) $lieferant->id) ?>/zusammenfuehren" class="formular">
                <label for="lieferant-ziel">Zusammenführen mit
                    <select id="lieferant-ziel" name="ziel" required>
                        <option value="">Bitte wählen</option>
                        <?php foreach ($andere as $anderer): ?>
                            <option value="<?= e((string) $anderer->id) ?>"><?= e($anderer->data->name . ($anderer->data->ibans === [] ? '' : ' – ' . Iban::formatieren($anderer->data->ibans[0]))) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="knopfreihe">
                    <button type="submit" class="knopf">Vorschau</button>
                </p>
            </form>
        <?php endif; ?>

        <h3>Lieferant löschen</h3>
        <?php if ($verwendungen > 0): ?>
            <p class="gedaempft">
                Der Lieferant wird verwendet und lässt sich deshalb nicht löschen.
            </p>
        <?php else: ?>
            <form method="post" action="/app/lieferanten/<?= e((string) $lieferant->id) ?>/loeschen" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-gefahr">Lieferant löschen</button>
                </p>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
