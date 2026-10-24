<?php

/**
 * AI call log (M7-8, issue #50, docs/spec/03-erfassung-und-ki.md section 6
 * "Client"): per provider the totals of the last days and its newest calls.
 * Only operating data - the log holds no prompt, no answer and no value read
 * from a receipt.
 *
 * Only components from /admin/designsystem, no JavaScript; the tables scroll
 * sideways on narrow screens like every other table.
 *
 * @var string $csrf
 * @var list<array{providerId: ?int, name: string, aufrufe: int, gueltig: int, fehler: int, tokens: int, dauerMs: ?int, aufrufeListe: list<array{zeitpunkt: \DateTimeImmutable, modell: string, promptVersion: string, reparatur: bool, fallback: bool, status: string, promptTokens: ?int, antwortTokens: ?int, tokens: ?int, dauerMs: ?int, httpStatus: ?int}>}> $gruppen
 * @var int $tage
 * @var int $zeilen
 */

$zahl = static fn(?int $wert): string => $wert === null ? '–' : number_format($wert, 0, ',', '.');
$dauer = static fn(?int $ms): string => $ms === null ? '–' : number_format($ms / 1000, 1, ',', '.') . ' s';
$ergebnis = static fn(string $status): string => \App\Service\Ki\KiAufrufStatus::tryFrom($status)?->meldung() ?? $status;
?>
<section>
    <h2>KI-Protokoll</h2>

    <p class="gedaempft">
        Jeder Aufruf eines KI-Anbieters beim Auslesen von Belegen, gruppiert nach Anbieter: Zeitpunkt,
        Dauer, Tokens, Modell, Prompt-Version und Ergebnis. Angezeigt werden die letzten
        <?= e((string) $tage) ?> Tage und je Anbieter die neuesten <?= e((string) $zeilen) ?> Aufrufe.
        Das Protokoll enthält keine Belegdaten – weder Anfrage noch Antwort. „Verbindung testen“
        erscheint hier nicht.
    </p>

    <?php if ($gruppen === []): ?>
        <p class="hinweis" role="status">In den letzten <?= e((string) $tage) ?> Tagen gab es keine KI-Aufrufe.</p>
    <?php endif; ?>

    <?php foreach ($gruppen as $gruppe): ?>
        <div class="karte">
            <h3><?= e($gruppe['name']) ?><?= $gruppe['providerId'] === null ? ' (Profil gelöscht)' : '' ?></h3>
            <p>
                <?= e($zahl($gruppe['aufrufe'])) ?> Aufrufe,
                davon <?= e($zahl($gruppe['gueltig'])) ?> gültig und <?= e($zahl($gruppe['fehler'])) ?> mit Fehler;
                <?= e($zahl($gruppe['tokens'])) ?> Tokens,
                durchschnittlich <?= e($dauer($gruppe['dauerMs'])) ?>.
            </p>
            <div class="tabelle-rahmen">
                <table class="tabelle">
                    <caption>Neueste Aufrufe von „<?= e($gruppe['name']) ?>“</caption>
                    <thead>
                        <tr>
                            <th scope="col">Zeitpunkt</th>
                            <th scope="col">Modell</th>
                            <th scope="col">Prompt</th>
                            <th scope="col">Dauer</th>
                            <th scope="col">Tokens (Anfrage / Antwort)</th>
                            <th scope="col">Ergebnis</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gruppe['aufrufeListe'] as $aufruf): ?>
                            <tr>
                                <td><?= e($aufruf['zeitpunkt']->format('d.m.Y H:i:s')) ?></td>
                                <td><?= e($aufruf['modell']) ?></td>
                                <td>
                                    <?= e($aufruf['promptVersion']) ?>
                                    <?= $aufruf['reparatur'] ? '<span class="marke">Reparatur</span>' : '' ?>
                                    <?= $aufruf['fallback'] ? '<span class="marke">Fallback</span>' : '' ?>
                                </td>
                                <td><?= e($dauer($aufruf['dauerMs'])) ?></td>
                                <td><?= e($zahl($aufruf['promptTokens'])) ?> / <?= e($zahl($aufruf['antwortTokens'])) ?></td>
                                <td>
                                    <?= e($ergebnis($aufruf['status'])) ?>
                                    <?= $aufruf['httpStatus'] !== null ? '(HTTP ' . e((string) $aufruf['httpStatus']) . ')' : '' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>
</section>
