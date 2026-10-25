<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DocumentStatus;
use App\Domain\Permission;
use App\Repository\DocumentRepository;
use App\Repository\SettingRepository;
use App\Repository\UserAccessRepository;
use App\Repository\UserRepository;
use App\Service\Crypto\ServerCrypto;

/**
 * The cron's reminder mail "5 Belege warten auf Prüfung – ohne Details"
 * (docs/spec/06-betrieb.md section 4, issue #249, E-71).
 *
 * Every active account with `document.edit` - the people the new-submission
 * notice goes to - gets one mail per interval with two numbers, counted in
 * SQL within its own scope: the inbox view "Offen" and the review queue,
 * the same two counters as the start page (App\Service\Report\Dashboard).
 * Nothing else: no reference, no name, no amount. Counting needs only the
 * plaintext structure columns (`status`, `resubmit_on`, `cost_center_id`,
 * `created_at`), and the only ciphertext opened is the address, which is
 * server-key operating data - so the cron can do it (CLAUDE.md section 4).
 *
 * The interval is the setting `erinnerung_intervall_tage` (0 = off, 1 =
 * daily, 7 = weekly, the default), changed on /admin/mail. A run is due on
 * the first cron call from ERST_AB_STUNDE o'clock on the day the interval
 * has passed since the last one; that day is written before anything is
 * queued, so a run cut off half way does not mail the first accounts twice.
 * An account with nothing waiting gets no mail, and nobody does while the
 * mail settings are incomplete.
 */
final readonly class OffeneBelegeErinnerung
{
    public const string SETTING_INTERVALL = 'erinnerung_intervall_tage';

    /** Y-m-d of the last run. */
    public const string SETTING_LETZTER_LAUF = 'erinnerung_letzter_lauf';

    public const int STANDARD_TAGE = 7;

    /** @var list<int> the choices on /admin/mail */
    public const array INTERVALLE = [0, 1, 7];

    /** No reminder mail before this hour (Europe/Berlin). */
    public const int ERST_AB_STUNDE = 7;

    public function __construct(
        private Mailer $mailer,
        private MailSettingsRepository $mailSettings,
        private SettingRepository $settings,
        private ServerCrypto $crypto,
        private UserRepository $users,
        private UserAccessRepository $zugriff,
        private DocumentRepository $dokumente,
    ) {
    }

    public static function intervall(SettingRepository $settings): int
    {
        $wert = $settings->get(self::SETTING_INTERVALL, (string) self::STANDARD_TAGE);

        return ctype_digit($wert) && in_array((int) $wert, self::INTERVALLE, true) ? (int) $wert : self::STANDARD_TAGE;
    }

    public function faellig(\DateTimeImmutable $now): bool
    {
        $tage = self::intervall($this->settings);
        if ($tage === 0 || (int) $now->format('G') < self::ERST_AB_STUNDE) {
            return false;
        }
        $letzter = \DateTimeImmutable::createFromFormat('!Y-m-d', $this->settings->get(self::SETTING_LETZTER_LAUF));
        if ($letzter === false) {
            return true;
        }

        return $letzter->modify(sprintf('+%d days', $tage))->format('Y-m-d') <= $now->format('Y-m-d');
    }

    /** @return int number of reminders queued */
    public function ausfuehren(\DateTimeImmutable $now): int
    {
        if (!$this->faellig($now)) {
            return 0;
        }
        $mail = $this->mailSettings->get();
        if (!$mail->istVollstaendig()) {
            // Mail not set up (the installer's step skipped): nothing that
            // could only fail five times in the queue. Due again once it is.
            return 0;
        }
        $this->settings->set(self::SETTING_LETZTER_LAUF, $now->format('Y-m-d'));

        // Links only with the configured address: the cron has no request
        // whose host could be checked (App\Service\Mail\PublicUrl).
        $eingestellt = $mail->oeffentlicheUrl;
        $basis = $eingestellt === '' ? null : PublicUrl::normalize($eingestellt);

        $anzahl = 0;
        foreach ($this->users->all() as $user) {
            if (!$user->mayLogIn($now)) {
                continue;
            }
            $rechte = $this->zugriff->berechtigungen($user->id);
            if (!$rechte->darf(Permission::DocumentEdit)) {
                continue;
            }
            $posteingang = $rechte->darf(Permission::InboxView)
                ? $this->dokumente->anzahlPosteingangOffen($rechte->zugriffsbereich(Permission::InboxView), $now)
                : 0;
            $pruefung = $this->dokumente->anzahlImStatus(
                DocumentStatus::pruefbare(),
                $rechte->zugriffsbereich(Permission::DocumentEdit),
            );
            if ($posteingang + $pruefung === 0) {
                continue;
            }
            // Unreadable after a restore without the old server key (issue #154).
            $email = $this->crypto->decryptOrNull($user->emailEnc);
            if ($email === null) {
                continue;
            }
            $this->mailer->reiheErinnerungEin($email, $posteingang, $pruefung, $basis, $now);
            ++$anzahl;
        }

        return $anzahl;
    }
}
