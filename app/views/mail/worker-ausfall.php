<?php

/**
 * Alarm to the admins when the optional worker has been silent for more
 * than a day while jobs were due for it (issue #53, docs/spec/07-worker.md
 * section 6). No receipt data - only that the worker is down, how long,
 * and how many jobs went back to the browser/session route or wait.
 *
 * Plain text, rendered by App\Service\Mail\MailTemplates (no e() here on
 * purpose, as in the other mail templates).
 *
 * @var string $vereinsname
 * @var int $stunden
 * @var int $betroffen
 */
?>
Der Verarbeitungs-Worker der Belegverwaltung von <?= $vereinsname !== '' ? $vereinsname : 'Vereinsbelege' ?> meldet sich seit über <?= $stunden ?> Stunden nicht mehr.

<?= $betroffen ?> Aufgabe(n) waren für ihn bestimmt. Sie laufen inzwischen wieder über den Browser bzw. die angemeldete Sitzung; die Texterkennung (OCR) ist für diese Belege ausgefallen und lässt sich nachholen, sobald der Worker wieder läuft.

Bitte prüfen Sie den Worker (Strom, Netzwerk, „docker compose ps“ auf dem Gerät). Den Stand sehen Sie nach der Anmeldung unter Verwaltung → Worker; dort lässt sich auch „OCR nachholen“ auslösen.
