<?php

/**
 * Statement import, start page (M9-4, issue #62, docs/spec/
 * 04-bank-und-abgleich.md section 4): upload a file, and the latest imports
 * with their counts and balance check.
 *
 * Only components from /admin/designsystem, no JavaScript; the history
 * turns into cards on narrow screens (.tabelle-karten). The file goes
 * through a plain multipart form; nothing of it is shown before the
 * preview, which only renders with the unlocked vault.
 *
 * @var string $csrf
 * @var bool $entsperrt
 * @var list<\App\Domain\BankAccount> $konten active bank accounts
 * @var array<int, string> $kontoNamen every account id => name, for the history
 * @var list<\App\Service\Bank\Csv\CsvProfil> $profile
 * @var list<\App\Domain\BankImportRecord> $importe newest first
 * @var array{konto: string, format: string} $felder
 * @var string|null $fehler
 * @var string|null $fehlerFeld
 * @var bool $csvFormatFehlt the error is "no CSV format fits" - link to the formats
 * @var int $maxBytes
 */

use App\Domain\BalanceCheck;
use App\Domain\BankImportStatus;

$fehlerAn = static fn(string $feld): string => $fehlerFeld === $feld ? ' aria-invalid="true" aria-describedby="import-fehler"' : '';
$profilNamen = [];
foreach ($profile as $profil) {
    $profilNamen['csv:' . $profil->id] = $profil->name;
}
$formatLabel = static fn(string $format): string => $format === 'mt940' ? 'MT940' : 'CSV: ' . ($profilNamen[$format] ?? 'gelöschtes Format');
$saldoMarke = static fn(?BalanceCheck $b): string => match ($b) {
    BalanceCheck::Ok => 'marke-ok',
    BalanceCheck::Abweichung => 'marke-warnung',
    default => '',
};
$statusMarke = static fn(BankImportStatus $s): string => match ($s) {
    BankImportStatus::Fertig => 'marke-ok',
    BankImportStatus::Laeuft => 'marke-warnung',
    BankImportStatus::Vorschau => '',
};
?>
<section>
    <h2>Kontoauszug importieren</h2>

    <p><a href="/app/konten">← Zu den Konten</a></p>

    <p class="gedaempft">
        Laden Sie den Kontoauszug-Export Ihrer Bank hoch. Bevor etwas gespeichert wird, zeigt eine Vorschau den
        Zeitraum, wie viele Buchungen neu oder schon vorhanden sind und ob die Salden stimmen. Empfohlen ist
        <strong>MT940</strong> (enthält Anfangs- und Schlusssaldo); CSV-Exporte gehen ebenso, wenn ein
        <a href="/app/konten/csv-formate">CSV-Format</a> dazu passt.
    </p>

    <?php if (!$entsperrt): ?>
        <p class="hinweis hinweis-info">
            Kontoauszüge sind verschlüsselt und nur mit entsperrtem Tresor lesbar.
            Melden Sie sich neu an, um Kontoauszüge zu importieren.
        </p>
    <?php else: ?>
        <?php if ($fehler !== null): ?>
            <p class="hinweis hinweis-fehler" role="alert" id="import-fehler">
                <?= e($fehler) ?>
                <?php if ($csvFormatFehlt): ?>
                    <a href="/app/konten/csv-formate/neu">Neues CSV-Format anlegen</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if ($konten === []): ?>
            <p class="hinweis hinweis-info">
                Es gibt noch kein aktives Bankkonto. Legen Sie unter <a href="/app/konten">Konten</a> zuerst das
                Bankkonto an – am besten mit IBAN, dann ordnet der Import die Datei selbst zu.
            </p>
        <?php else: ?>
            <form method="post" action="/app/konten/import" enctype="multipart/form-data" class="formular">
                <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

                <label for="import-datei">Datei <span class="pflicht" aria-hidden="true">*</span>
                    <input type="file" id="import-datei" name="datei" required
                           accept=".sta,.mt940,.940,.txt,.csv,text/plain,text/csv"<?= $fehlerAn('datei') ?>>
                </label>
                <p class="feld-hilfe">MT940 (.sta, .txt) oder CSV, höchstens <?= e((string) intdiv($maxBytes, 1024 * 1024)) ?> MB.</p>

                <label for="import-konto">Konto
                    <select id="import-konto" name="konto" aria-describedby="import-konto-hilfe"<?= $fehlerAn('konto') ?>>
                        <option value="">Automatisch aus der Datei</option>
                        <?php foreach ($konten as $konto): ?>
                            <option value="<?= e((string) $konto->id) ?>"<?= $felder['konto'] === (string) $konto->id ? ' selected' : '' ?>><?= e($konto->data->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <p class="feld-hilfe" id="import-konto-hilfe">
                    Eine MT940-Datei nennt ihr Konto selbst. Eine CSV-Datei nicht – dann fragt die Vorschau nach dem Konto.
                </p>

                <label for="import-format">Format
                    <select id="import-format" name="format"<?= $fehlerAn('format') ?>>
                        <option value="">Automatisch erkennen</option>
                        <option value="mt940"<?= $felder['format'] === 'mt940' ? ' selected' : '' ?>>MT940</option>
                        <?php foreach ($profile as $profil): ?>
                            <?php $wert = 'csv:' . $profil->id; ?>
                            <option value="<?= e($wert) ?>"<?= $felder['format'] === $wert ? ' selected' : '' ?>>CSV: <?= e($profil->name) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <p class="knopfreihe">
                    <button type="submit" class="knopf knopf-primaer">Hochladen und prüfen</button>
                </p>
            </form>
        <?php endif; ?>

        <h3>Letzte Importe</h3>
        <?php if ($importe === []): ?>
            <div class="leer">Noch kein Kontoauszug importiert.</div>
        <?php else: ?>
            <div class="tabelle-rahmen">
                <table class="tabelle tabelle-karten">
                    <caption>Die letzten <?= e((string) count($importe)) ?> Importe</caption>
                    <thead>
                        <tr>
                            <th scope="col">Hochgeladen</th>
                            <th scope="col">Konto</th>
                            <th scope="col">Format</th>
                            <th scope="col">Zeitraum</th>
                            <th scope="col">Status</th>
                            <th scope="col" class="zahl">Neu</th>
                            <th scope="col" class="zahl">Vorhanden</th>
                            <th scope="col">Salden</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($importe as $import): ?>
                            <tr>
                                <td class="tabelle-kurz" data-label="Hochgeladen"><a href="/app/konten/import/<?= e((string) $import->id) ?>"><?= e($import->createdAt->format('d.m.Y H:i')) ?></a></td>
                                <td data-label="Konto"><?= $import->accountId === null ? '<span class="gedaempft">noch offen</span>' : e($kontoNamen[$import->accountId] ?? '?') ?></td>
                                <td data-label="Format"><?= e($formatLabel($import->format)) ?></td>
                                <td class="tabelle-kurz" data-label="Zeitraum">
                                    <?php if ($import->periodFrom !== null && $import->periodTo !== null): ?>
                                        <?= e($import->periodFrom->format('d.m.Y')) ?> – <?= e($import->periodTo->format('d.m.Y')) ?>
                                    <?php else: ?>
                                        <span class="gedaempft">–</span>
                                    <?php endif; ?>
                                </td>
                                <td class="tabelle-kurz" data-label="Status"><span class="marke <?= e($statusMarke($import->status)) ?>"><?= e($import->status->label()) ?></span></td>
                                <?php if ($import->status === BankImportStatus::Fertig && $import->stats !== null): ?>
                                    <td class="zahl tabelle-kurz" data-label="Neu"><?= e((string) $import->stats->neu) ?></td>
                                    <td class="zahl tabelle-kurz" data-label="Vorhanden"><?= e((string) $import->stats->duplikat) ?></td>
                                <?php else: ?>
                                    <td class="zahl tabelle-kurz" data-label="Neu"><span class="gedaempft">–</span></td>
                                    <td class="zahl tabelle-kurz" data-label="Vorhanden"><span class="gedaempft">–</span></td>
                                <?php endif; ?>
                                <td class="tabelle-kurz" data-label="Salden">
                                    <?php if ($import->balanceCheck === null): ?>
                                        <span class="gedaempft">–</span>
                                    <?php else: ?>
                                        <span class="marke <?= e($saldoMarke($import->balanceCheck)) ?>"><?= e($import->balanceCheck->label()) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</section>
