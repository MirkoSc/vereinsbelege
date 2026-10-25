<?php

/**
 * New SEPA payment run (issue #85, E-42): the open reimbursements per payee
 * (name AND IBAN from the submission, as in /app/offen/erstattungen), each
 * receipt that may go into a run ticked, the others with the reason. The
 * form carries invoice ids, the paying account, the payer's name and the
 * execution date - the server checks everything again. Only components
 * from /admin/designsystem, no JavaScript.
 *
 * @var \App\Service\Bank\Zahllaufvorbereitung $vorbereitung
 * @var string $auftraggeber
 * @var string $ausfuehrung
 * @var string $csrf
 */

use App\Domain\Iban;
use App\Service\Bank\Sepa\Pain001;
use App\Service\Processing\Betrag;

$waehlbar = array_filter($vorbereitung->gruppen, static fn($g): bool => $g->hatWaehlbare());
?>
<section>
    <h2>Neuer SEPA-Zahllauf</h2>
    <p><a href="/app/zahllaeufe">← Alle Zahlläufe</a></p>
    <p class="gedaempft">
        Je Empfänger eine Überweisung über die offene Summe der gewählten Belege, an die IBAN aus der Einreichung.
        Nicht angeboten werden Empfänger, deren Name auch mit einer anderen oder ohne IBAN vorkommt, Konten des Vereins,
        Barerstattungen, ungeprüfte Belege und Belege, die schon in einem Zahllauf angewiesen sind.
    </p>
</section>

<?php if ($vorbereitung->unlesbar > 0): ?>
    <p class="hinweis hinweis-warnung"><?= e(sprintf('%d %s nicht entschlüsseln.', $vorbereitung->unlesbar, $vorbereitung->unlesbar === 1 ? 'Beleg ließ sich' : 'Belege ließen sich')) ?></p>
<?php endif; ?>

<?php if ($vorbereitung->konten === []): ?>
    <p class="hinweis hinweis-warnung">
        Es gibt kein aktives Bankkonto mit gültiger IBAN. Bitte zuerst unter <a href="/app/konten">Konten</a> IBAN (und
        möglichst BIC) des Vereinskontos eintragen.
    </p>
<?php elseif ($vorbereitung->gruppen === []): ?>
    <div class="leer">Keine offenen Erstattungen.</div>
<?php else: ?>
    <form method="post" action="/app/zahllaeufe" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <fieldset>
            <legend>Auftrag</legend>
            <label for="zahllauf-konto">Konto des Vereins <span class="pflicht" aria-hidden="true">*</span>
                <select id="zahllauf-konto" name="konto" required>
                    <?php foreach ($vorbereitung->konten as $konto): ?>
                        <option value="<?= e((string) $konto->id) ?>"><?= e($konto->data->name . ' – ' . Iban::formatieren($konto->data->iban)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label for="zahllauf-auftraggeber">Kontoinhaber (Auftraggeber) <span class="pflicht" aria-hidden="true">*</span>
                <input type="text" id="zahllauf-auftraggeber" name="auftraggeber" value="<?= e($auftraggeber) ?>"
                       maxlength="<?= e((string) Pain001::NAME_LAENGE) ?>" required>
            </label>
            <p class="feld-hilfe">So, wie die Bank den Inhaber des Kontos führt. Umlaute werden umgeschrieben (ä → ae).</p>
            <label for="zahllauf-datum" class="feld-kurz">Ausführung am <span class="pflicht" aria-hidden="true">*</span>
                <input type="date" id="zahllauf-datum" name="ausfuehrung" value="<?= e($ausfuehrung) ?>" min="<?= e($ausfuehrung) ?>" required>
            </label>
        </fieldset>

        <?php foreach ($vorbereitung->gruppen as $g): ?>
            <fieldset>
                <legend><?= e($g->name !== '' ? $g->name : 'Ohne Angabe des Einreichers') ?></legend>
                <?php if ($g->iban !== ''): ?>
                    <p>IBAN laut Einreichung: <strong><?= e(Iban::formatieren($g->iban)) ?></strong>
                        <?php if ($g->sepaName !== '' && $g->sepaName !== $g->name): ?>
                            <br><span class="gedaempft">In der Datei: <?= e($g->sepaName) ?></span>
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php if ($g->sperrgrund !== null): ?>
                    <p class="hinweis hinweis-warnung"><?= e($g->sperrgrund) ?></p>
                <?php endif; ?>
                <?php foreach ($g->kandidaten as $k): ?>
                    <?php $p = $k->posten; ?>
                    <?php $text = trim(($p->lieferant !== '' ? $p->lieferant : 'ohne Lieferant') . ' · ' . ($p->nummer !== '' ? $p->nummer . ' · ' : '') . 'vom ' . $p->record->invoiceDate->format('d.m.Y')) . ' – offen ' . Betrag::format($p->offen) . ' ' . $p->waehrung; ?>
                    <?php if ($k->waehlbar()): ?>
                        <label class="feld-ankreuz">
                            <input type="checkbox" name="belege[]" value="<?= e((string) $p->record->id) ?>" checked>
                            <span><?= e($text) ?></span>
                        </label>
                    <?php elseif ($g->sperrgrund === null): ?>
                        <p class="gedaempft"><?= e($text) ?> – <?= e((string) $k->sperrgrund) ?></p>
                    <?php else: ?>
                        <p class="gedaempft"><?= e($text) ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </fieldset>
        <?php endforeach; ?>

        <?php if ($waehlbar === []): ?>
            <p class="hinweis hinweis-warnung">Keine der offenen Erstattungen kann in einen Zahllauf.</p>
        <?php else: ?>
            <p class="knopfreihe">
                <button type="submit" class="knopf knopf-primaer">Zahllauf anlegen</button>
            </p>
            <p class="feld-hilfe">
                Danach können Sie die SEPA-Datei herunterladen. Die Belege sind ab dann angewiesen und werden nicht noch
                einmal angeboten.
            </p>
        <?php endif; ?>
    </form>
<?php endif; ?>
