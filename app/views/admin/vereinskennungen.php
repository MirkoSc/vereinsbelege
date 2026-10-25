<?php

/**
 * Admin page for the club's own VAT id and tax number (issue #245, E-67,
 * docs/spec/03-erfassung-und-ki.md section 7 "Stand #245").
 *
 * Only components from /admin/designsystem, no page-specific CSS/JS. The
 * values are vault data: without the unlocked vault the page only says
 * whether they were saved.
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var bool $gepflegt
 * @var string $ustId
 * @var string $steuernummer
 * @var ?string $fehler
 */

use App\Service\MasterData\SupplierService;

?>
<section class="schmal">
    <h2>Vereinskennungen</h2>

    <p class="gedaempft">
        Die eigene USt-ID und Steuernummer des Vereins. Viele Rechnungen nennen sie („Ihre USt-IdNr.“) –
        damit die Lieferantenerkennung sie nicht für die des Lieferanten hält, wird sie nie bei einem
        Lieferanten gelernt und bei der Zuordnung übergangen. Solange diese Seite nicht gespeichert ist,
        lernen Lieferanten aus Belegen keine USt-ID und keine Steuernummer.
    </p>

    <p>
        Status:
        <?php if ($gepflegt): ?>
            <span class="marke marke-ok">gespeichert</span>
        <?php else: ?>
            <span class="marke">noch nicht gespeichert</span>
        <?php endif; ?>
    </p>

    <?php if ($fehler !== null): ?>
        <p class="hinweis hinweis-fehler" role="alert"><?= e($fehler) ?></p>
    <?php endif; ?>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Die Kennungen liegen verschlüsselt im Tresor. Melden Sie sich neu an, um sie einzusehen oder zu ändern.
        </p>
    <?php else: ?>
        <form method="post" action="/admin/vereinskennungen" class="formular">
            <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

            <label for="verein-ust-id">USt-ID des Vereins
                <input type="text" id="verein-ust-id" name="ust_id" value="<?= e($ustId) ?>" maxlength="20"
                       spellcheck="false" autocomplete="off"<?= $fehler !== null ? ' aria-invalid="true"' : '' ?>>
            </label>

            <label for="verein-steuernummer">Steuernummer des Vereins
                <input type="text" id="verein-steuernummer" name="steuernummer" value="<?= e($steuernummer) ?>"
                       maxlength="<?= e((string) SupplierService::TEXT_MAX) ?>" spellcheck="false" autocomplete="off"
                       aria-describedby="verein-kennungen-hilfe">
            </label>
            <p class="feld-hilfe" id="verein-kennungen-hilfe">
                Leer lassen, was der Verein nicht hat. Auch mit zwei leeren Feldern speichern – das bestätigt,
                dass der Verein keine hat, und Lieferanten lernen ab dann USt-ID und Steuernummer aus Belegen.
            </p>

            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Speichern</button>
            </p>
        </form>
    <?php endif; ?>
</section>
