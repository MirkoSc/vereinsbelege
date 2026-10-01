<?php

/**
 * Preview and confirmation of merging one supplier into another (M6-5,
 * issue #39, docs/spec/03-erfassung-und-ki.md section 7).
 *
 * Only existing components, no JavaScript: the target as it would be, as
 * the term/value card of the inbox detail (`posteingang-angaben` - term
 * above value at 360 px, side by side from 30rem), marked where it
 * changes, and one POST form.
 *
 * @var string $csrf
 * @var \App\Domain\Supplier $quelle
 * @var \App\Service\MasterData\SupplierMergeVorschau|null $vorschau null when the merge is not possible
 * @var string|null $fehler
 * @var int|null $konfliktId another supplier the refusal points at
 * @var array<int, string> $kategorien id => name
 */

use App\Domain\Iban;

$zeilen = static fn(array $werte): string => $werte === [] ? '<span class="gedaempft">–</span>' : implode('<br>', array_map(e(...), $werte));
$text = static fn(string $wert): string => $zeilen($wert === '' ? [] : (preg_split('/\R/u', $wert) ?: []));
?>
<section class="schmal">
    <h2>Lieferanten zusammenführen</h2>

    <p><a href="/app/lieferanten/<?= e((string) $quelle->id) ?>">← Zurück zu „<?= e($quelle->data->name) ?>“</a></p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert">
            <?= e($fehler) ?>
            <?php if ($konfliktId !== null): ?>
                <a href="/app/lieferanten/<?= e((string) $konfliktId) ?>">Zum anderen Lieferanten</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>

    <?php if ($vorschau !== null):
        $ziel = $vorschau->ziel;
        $plan = $vorschau->plan;
        $d = $plan->data;
        $offen = $vorschau->belege['offen'];
        $festgeschrieben = $vorschau->belege['festgeschrieben'];
        $kategorie = $plan->defaultCategoryId === null ? '' : ($kategorien[$plan->defaultCategoryId] ?? '?');
        $felder = [
            ['name', 'Name', e($d->name)],
            ['role', 'Rolle', e($plan->role->label())],
            ['default_category_id', 'Standard-Kategorie', $text($kategorie)],
            ['aliases', 'Aliasse', $zeilen($d->aliases)],
            ['iban', 'IBANs', $zeilen(array_map(Iban::formatieren(...), $d->ibans))],
            ['bic', 'BIC', $text($d->bic)],
            ['creditor_id', 'Gläubiger-ID', $text($d->creditorId)],
            ['mandate_refs', 'Mandatsreferenzen', $zeilen($d->mandateRefs)],
            ['vat_id', 'USt-ID', $text($d->vatId)],
            ['tax_number', 'Steuernummer', $text($d->taxNumber)],
            ['address', 'Anschrift', $text($d->address)],
            ['email', 'E-Mail', $text($d->email)],
            ['website', 'Website', $text($d->website)],
            ['customer_number', 'Unsere Kundennummer', $text($d->customerNumber)],
            ['notes', 'Notiz', $text($d->notes)],
        ];
        ?>
        <p>
            „<?= e($quelle->data->name) ?>“ geht in „<?= e($ziel->data->name) ?>“ auf. Danach gibt es nur noch
            „<?= e($ziel->data->name) ?>“ – mit den Angaben unten.
            <a href="/app/lieferanten/<?= e((string) $ziel->id) ?>/zusammenfuehren?ziel=<?= e((string) $quelle->id) ?>">Andersherum zusammenführen</a>
        </p>

        <p>
            <?php if ($offen === 0): ?>
                „<?= e($quelle->data->name) ?>“ hat keine offenen Belege, die umgehängt werden.
            <?php else: ?>
                <?= e((string) $offen) ?> <?= $offen === 1 ? 'Beleg wird' : 'Belege werden' ?> auf „<?= e($ziel->data->name) ?>“ umgehängt.
            <?php endif; ?>
        </p>
        <?php if ($festgeschrieben > 0): ?>
            <p class="hinweis hinweis-info">
                <?= e((string) $festgeschrieben) ?> <?= $festgeschrieben === 1 ? 'Beleg ist' : 'Belege sind' ?> festgeschrieben und
                <?= $festgeschrieben === 1 ? 'bleibt' : 'bleiben' ?> unverändert. Sie zeigen künftig trotzdem
                „<?= e($ziel->data->name) ?>“ – der zusammengeführte Lieferant verweist dorthin.
            </p>
        <?php endif; ?>

        <h3>„<?= e($ziel->data->name) ?>“ nach dem Zusammenführen</h3>
        <div class="karte posteingang-angaben">
            <dl>
                <?php foreach ($felder as [$feld, $label, $wert]): ?>
                    <dt>
                        <?= e($label) ?>
                        <?php if (in_array($feld, $plan->geaenderteFelder, true)): ?>
                            <span class="marke marke-ok">geändert</span>
                        <?php endif; ?>
                    </dt>
                    <dd><?= $wert ?></dd>
                <?php endforeach; ?>
            </dl>
        </div>

        <form method="post" action="/app/lieferanten/<?= e((string) $quelle->id) ?>/zusammenfuehren" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="ziel" value="<?= e((string) $ziel->id) ?>">
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Zusammenführen</button>
                <a class="knopf" href="/app/lieferanten/<?= e((string) $quelle->id) ?>">Abbrechen</a>
            </p>
        </form>
    <?php endif; ?>
</section>
