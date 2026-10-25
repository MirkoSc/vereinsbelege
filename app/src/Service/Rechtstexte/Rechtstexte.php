<?php

declare(strict_types=1);

namespace App\Service\Rechtstexte;

use App\Repository\SettingRepository;

/**
 * Reads and stores the club-maintained legal texts (issue #80/M13-3, E-46)
 * in the `setting` table. They are public by nature (Impressum,
 * Datenschutzerklärung), so plaintext there is right - nothing from a
 * receipt ever goes in.
 *
 * An empty stored value means "not set": the template is shown again. That
 * is also how the admin resets a text - empty the field and save.
 */
final readonly class Rechtstexte
{
    public function __construct(
        private SettingRepository $settings,
        /** App\Config\Paths::dataDir() - the templates are `rechtstexte/<value>.md` there. */
        private string $dataDir,
    ) {
    }

    public function vorlage(Rechtstext $art): string
    {
        $datei = $this->dataDir . '/rechtstexte/' . $art->value . '.md';
        $inhalt = is_file($datei) ? file_get_contents($datei) : false;

        return $inhalt === false ? '' : $inhalt;
    }

    public function lesen(Rechtstext $art): RechtstextInhalt
    {
        $gespeichert = $this->settings->get($art->settingName());
        if (trim($gespeichert) === '') {
            return new RechtstextInhalt($art, $this->vorlage($art), istVorlage: true);
        }

        return new RechtstextInhalt($art, $gespeichert, istVorlage: false);
    }

    /**
     * Why a text cannot be stored, or null. Checked on the text as it
     * will be stored (line breaks normalised - browsers send CRLF).
     */
    public static function fehler(Rechtstext $art, string $text): ?string
    {
        $text = self::normalisiert($text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            return sprintf('„%s“ enthält ungültige Zeichen.', $art->titel());
        }
        if (strlen($text) > Rechtstext::MAX_BYTES) {
            return sprintf(
                '„%s“ ist zu lang (höchstens %s Byte, Umlaute zählen doppelt).',
                $art->titel(),
                number_format(Rechtstext::MAX_BYTES, 0, ',', '.'),
            );
        }

        return null;
    }

    /**
     * @throws \InvalidArgumentException with the message of fehler()
     */
    public function speichern(Rechtstext $art, string $text): void
    {
        $fehler = self::fehler($art, $text);
        if ($fehler !== null) {
            throw new \InvalidArgumentException($fehler);
        }
        $text = self::normalisiert($text);

        // The unchanged template is not stored: saving the whole form after
        // editing one text must not freeze the others as the club's own.
        $istVorlage = trim($text) === ''
            || trim($text) === trim(self::normalisiert($this->vorlage($art)));

        $this->settings->set($art->settingName(), $istVorlage ? '' : $text);
    }

    private static function normalisiert(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
}
