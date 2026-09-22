<?php

/**
 * Mail admin page (M3-1, issue #14): SMTP settings, a test mail, the retry
 * queue.
 *
 * Only components from /admin/designsystem, no page-specific CSS, no
 * JavaScript - two plain forms are enough here.
 *
 * @var string $csrf
 * @var \App\Service\Mail\MailSettings $einstellungen
 * @var list<\App\Service\Mail\SmtpSecurity> $sicherheiten
 * @var list<\App\Domain\QueuedMail> $warteschlange
 */

// Masks a recipient for the queue table (spec 06 section 3: "Queue einsehen
// (Empfänger maskiert)") - only the first letter of the local part and of
// the domain survive.
$maskiere = static function (string $adresse): string {
    $klammeraffe = strpos($adresse, '@');
    if ($klammeraffe === false || $klammeraffe === 0) {
        return '***';
    }
    $lokal = substr($adresse, 0, $klammeraffe);
    $domain = substr($adresse, $klammeraffe + 1);
    $punkt = strrpos($domain, '.');
    $domainMaske = $punkt === false ? ($domain[0] ?? '') . '***' : ($domain[0] ?? '') . '***' . substr($domain, $punkt);

    return $lokal[0] . '***@' . $domainMaske;
};
?>
<section class="schmal">
    <h2>Mail</h2>

    <p class="gedaempft">
        Der Versand läuft über die Warteschlange: ein Versuch sofort beim
        Auslösen, weitere Versuche mit steigendem Abstand im Cron – bis zu
        fünf Mal insgesamt. Zugangsdaten liegen mit dem Server-Schlüssel
        verschlüsselt in den Einstellungen.
    </p>

    <h3>SMTP-Einstellungen</h3>
    <form method="post" action="/admin/mail/einstellungen" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <fieldset>
            <legend>Versandweg</legend>
            <label class="feld-ankreuz">
                <input type="radio" name="transport" value="smtp"
                       <?= $einstellungen->transport === 'smtp' ? 'checked' : '' ?>> SMTP
            </label>
            <label class="feld-ankreuz">
                <input type="radio" name="transport" value="php_mail"
                       <?= $einstellungen->transport === 'php_mail' ? 'checked' : '' ?>> PHP <code>mail()</code>
            </label>
        </fieldset>
        <p class="feld-hilfe">
            <code>mail()</code> nutzt den lokalen Mail-Transport des Hosters – nur
            sinnvoll, wenn dort kein eigenes SMTP-Konto zur Verfügung steht.
        </p>

        <label for="mail-host">SMTP-Server
            <input type="text" id="mail-host" name="host" value="<?= e($einstellungen->host) ?>"
                   autocomplete="off" placeholder="z. B. mail.example.org">
        </label>

        <label for="mail-port" class="feld-kurz">Port
            <input type="text" id="mail-port" name="port" inputmode="numeric"
                   value="<?= e((string) $einstellungen->port) ?>">
        </label>

        <fieldset>
            <legend>Verschlüsselung</legend>
            <?php foreach ($sicherheiten as $sicherheit): ?>
                <label class="feld-ankreuz">
                    <input type="radio" name="sicherheit" value="<?= e($sicherheit->value) ?>"
                           <?= $sicherheit === $einstellungen->sicherheit ? 'checked' : '' ?>>
                    <?= e($sicherheit->bezeichnung()) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <label for="mail-benutzer">Benutzername
            <input type="text" id="mail-benutzer" name="benutzer" value="<?= e($einstellungen->benutzer) ?>"
                   autocomplete="off">
        </label>

        <label for="mail-passwort">Passwort
            <input type="password" id="mail-passwort" name="passwort" autocomplete="off">
        </label>
        <p class="feld-hilfe">
            Leer lassen, um das gespeicherte Passwort zu behalten. Aktuell
            <?= $einstellungen->passwortGesetzt ? 'gesetzt' : 'nicht gesetzt' ?>.
        </p>
        <label class="feld-ankreuz">
            <input type="checkbox" name="passwort_loeschen" value="1"> Gespeichertes Passwort löschen
        </label>

        <label for="mail-absender">Absenderadresse <span class="pflicht" aria-hidden="true">*</span>
            <input type="email" id="mail-absender" name="absender" required
                   value="<?= e($einstellungen->absender) ?>">
        </label>

        <label for="mail-antwort-an">Antwort-an-Adresse
            <input type="email" id="mail-antwort-an" name="antwort_an" value="<?= e($einstellungen->antwortAn) ?>">
        </label>
        <p class="feld-hilfe">Optional – bleibt sie leer, antworten Empfänger an die Absenderadresse.</p>

        <label for="mail-vereinsname">Vereinsname im Absender
            <input type="text" id="mail-vereinsname" name="vereinsname" value="<?= e($einstellungen->vereinsname) ?>">
        </label>

        <p class="knopfreihe">
            <button type="submit" class="knopf knopf-primaer">Einstellungen speichern</button>
        </p>
    </form>

    <h3>Testmail</h3>
    <form method="post" action="/admin/mail/testmail" class="formular">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
        <label for="mail-testempfaenger">Empfängeradresse
            <input type="email" id="mail-testempfaenger" name="empfaenger" required autocomplete="off">
        </label>
        <p class="feld-hilfe">
            Rein technische Testmail ohne fachlichen Inhalt – prüft nur, ob die
            Einstellungen oben funktionieren.
        </p>
        <p class="knopfreihe">
            <button type="submit" class="knopf">Testmail senden</button>
        </p>
    </form>

    <h3>Warteschlange</h3>
    <?php if ($warteschlange === []): ?>
        <div class="leer">Keine Einträge vorhanden.</div>
    <?php else: ?>
        <div class="tabelle-rahmen">
            <table class="tabelle">
                <caption>Letzte Einträge – Empfänger maskiert, Betreff wird nicht angezeigt.</caption>
                <thead>
                    <tr>
                        <th scope="col">Empfänger</th>
                        <th scope="col">Status</th>
                        <th scope="col" class="zahl">Versuche</th>
                        <th scope="col">Nächster Versuch</th>
                        <th scope="col">Fehler</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($warteschlange as $mail): ?>
                        <tr>
                            <td><?= e($maskiere($mail->to)) ?></td>
                            <td>
                                <span class="marke <?= e($mail->status->markeKlasse()) ?>">
                                    <?= e($mail->status->bezeichnung()) ?>
                                </span>
                            </td>
                            <td class="zahl"><?= e((string) $mail->attempts) ?></td>
                            <td><?= e($mail->nextTryAt->format('d.m.Y H:i')) ?></td>
                            <td class="klein"><?= e($mail->lastError ?? '–') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
