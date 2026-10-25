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
     * @throws \InvalidArgumentException when the text is longer than
     *         Rechtstext::MAX_BYTES or not valid UTF-8
     */
    public function speichern(Rechtstext $art, string $text): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('Der Text enthält ungültige Zeichen.');
        }
        if (strlen($text) > Rechtstext::MAX_BYTES) {
            throw new \InvalidArgumentException(sprintf(
                '„%s“ ist zu lang (höchstens %s Zeichen).',
                $art->titel(),
                number_format(Rechtstext::MAX_BYTES, 0, ',', '.'),
            ));
        }

        // The unchanged template is not stored: saving the whole form after
        // editing one text must not freeze the others as the club's own.
        $istVorlage = trim($text) === ''
            || trim($text) === trim(str_replace(["\r\n", "\r"], "\n", $this->vorlage($art)));

        $this->settings->set($art->settingName(), $istVorlage ? '' : $text);
    }
}
