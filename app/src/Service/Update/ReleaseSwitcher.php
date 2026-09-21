<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\MaintenanceMode;

/**
 * Switches the active release via rename() (atomic on the same filesystem,
 * CLAUDE.md section 2): maintenance.flag -> current => releases/_prev ->
 * releases/vX.Y.Z => current -> remove flag. If PHP dies in between, the
 * state is unambiguous from the filesystem and switchTo() repairs it on the
 * next call. Operates on a base dir so tests run against temp dirs.
 */
final class ReleaseSwitcher
{
    /**
     * The docroot shim. It lives here because this class already owns the
     * on-disk layout, and because the content must be byte-identical in
     * three places (ShimContentTest enforces that): this constant, the copy
     * bin/setup.template.php writes on a fresh install, and docker/web/.
     *
     * The maintenance check lives in the SHIM, not only in
     * current/public/index.php, for one reason: between
     * rename(current, _prev) and rename(new, current) the release directory
     * is briefly gone. A require of the missing file is a fatal error, so
     * the very file that renders the maintenance page cannot be the one that
     * disappears. The shim is the only file no release ZIP and no rename()
     * ever touches.
     */
    public const string SHIM = <<<'PHP'
        <?php
        // Docroot shim - written by setup.php on a fresh install and kept up
        // to date by the updater. Do not edit.
        $basis = dirname(__DIR__);
        $release = $basis . '/current/public/index.php';
        $pfad = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

        // /admin stays reachable while the flag is set - the update step chain runs
        // there - and so do its stylesheet and scripts, otherwise the page that is
        // supposed to end the maintenance would arrive unstyled and without the
        // JavaScript that drives the steps.
        $durchlassen = str_starts_with($pfad, '/admin')
            || str_starts_with($pfad, '/css/')
            || str_starts_with($pfad, '/js/');

        // Missing release = mid-switch or a crashed one; the flag = update in
        // progress. Nothing gets through while the release itself is gone - there is
        // nothing to serve it with.
        if (!is_file($release)
            || (is_file($basis . '/shared/maintenance.flag') && !$durchlassen)) {
            http_response_code(503);
            header('Content-Type: text/html; charset=utf-8');
            header('Retry-After: 30');
            echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Wartung</title></head>'
                . '<body><h1>Kurze Wartungspause</h1><p>Die Belegverwaltung wird gerade aktualisiert. '
                . 'Bitte in einer Minute erneut laden.</p></body></html>';
            exit;
        }

        require $release;

        PHP;

    private readonly MaintenanceMode $maintenance;

    /**
     * @param MaintenanceMode|null $maintenance shared instance from the
     *        bootstrap; defaults to the flag inside this base dir so tests
     *        (and setup.php) can construct the switcher from a path alone
     */
    public function __construct(
        private readonly string $baseDir,
        ?MaintenanceMode $maintenance = null,
    ) {
        $this->maintenance = $maintenance ?? new MaintenanceMode($baseDir . '/shared/maintenance.flag');
    }

    public function switchTo(string $version): void
    {
        $current = $this->baseDir . '/current';
        $prev = $this->baseDir . '/releases/_prev';
        $new = $this->baseDir . '/releases/v' . $version;

        // already switched (repair/idempotency)
        if ($this->currentVersion() === $version) {
            $this->removeMaintenanceFlag();

            return;
        }

        if (!is_dir($new)) {
            throw new \RuntimeException('Entpacktes Release fehlt: ' . $new);
        }

        $this->setMaintenanceFlag();

        // a leftover _prev from an older update would block the rename
        if (is_dir($prev) && is_dir($current)) {
            self::removeTree($prev);
        }

        if (is_dir($current) && !rename($current, $prev)) {
            throw new \RuntimeException('current kann nicht nach releases/_prev verschoben werden.');
        }
        if (!rename($new, $current)) {
            // roll the first rename back so the instance keeps running
            if (is_dir($prev) && !is_dir($current)) {
                rename($prev, $current);
            }
            throw new \RuntimeException('Neues Release kann nicht nach current verschoben werden.');
        }

        $this->removeMaintenanceFlag();
    }

    /**
     * Rollback = rename _prev back. The failed new release moves back to
     * releases/vX.Y.Z for inspection.
     */
    public function rollback(): void
    {
        $current = $this->baseDir . '/current';
        $prev = $this->baseDir . '/releases/_prev';

        if (!is_dir($prev)) {
            throw new \RuntimeException('Kein vorheriges Release vorhanden (releases/_prev fehlt).');
        }

        $this->setMaintenanceFlag('Rollback');

        if (is_dir($current)) {
            $failedVersion = $this->currentVersion() ?? ('unbekannt_' . time());
            $failedTarget = $this->baseDir . '/releases/v' . $failedVersion;
            if (is_dir($failedTarget)) {
                self::removeTree($failedTarget);
            }
            if (!rename($current, $failedTarget)) {
                throw new \RuntimeException('current kann nicht beiseite geräumt werden.');
            }
        }
        if (!rename($prev, $current)) {
            throw new \RuntimeException('releases/_prev kann nicht nach current verschoben werden.');
        }

        $this->removeMaintenanceFlag();
    }

    /**
     * Keep the last 2 (current + _prev); everything else in releases/ goes.
     */
    public function cleanupOldReleases(): void
    {
        $releasesDir = $this->baseDir . '/releases';
        if (!is_dir($releasesDir)) {
            return;
        }

        foreach (glob($releasesDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (basename($dir) !== '_prev') {
                self::removeTree($dir);
            }
        }
    }

    public function currentVersion(): ?string
    {
        $versionFile = $this->baseDir . '/current/VERSION';
        if (!is_file($versionFile)) {
            return null;
        }
        $content = file_get_contents($versionFile);

        return $content === false ? null : trim($content);
    }

    public function hasPreviousRelease(): bool
    {
        return is_dir($this->baseDir . '/releases/_prev');
    }

    public function setMaintenanceFlag(string $grund = 'Update'): void
    {
        $this->maintenance->enable($grund);
    }

    public function removeMaintenanceFlag(): void
    {
        $this->maintenance->disable();
    }

    /**
     * The docroot files a release ships as templates. The shim is NOT in
     * here - it has its own constant, because it must be writable even when
     * the release directory is unreadable.
     */
    public const array DOCROOT_FILES = ['.htaccess', '.user.ini'];

    public function shimFile(): string
    {
        return $this->baseDir . '/web/index.php';
    }

    /**
     * Brings the docroot shim up to date (self-healing). Called from the
     * finish step, i.e. the first step that already runs on the NEW release
     * - the earlier steps still execute the old version's code and could not
     * know about a newer shim.
     *
     * Consequence, and the reason this is not the whole story: the update
     * that INTRODUCES a shim change cannot protect its own switch, only
     * every switch after it. That is a property of self-healing, not a bug.
     *
     * @return string|null the previous content when the shim was replaced,
     *         null when it was already current (nothing to roll back)
     * @throws \RuntimeException when the docroot is not writable
     */
    public function refreshShim(): ?string
    {
        $file = $this->shimFile();
        if (!is_file($file)) {
            // Not our docroot layout (e.g. a custom setup): writing a shim
            // where none exists would be guessing, so stay out of it.
            return null;
        }

        $current = file_get_contents($file);
        if ($current === false) {
            throw new \RuntimeException('Shim nicht lesbar: ' . $file);
        }
        if ($current === self::SHIM) {
            return null;
        }

        if (@file_put_contents($file, self::SHIM, LOCK_EX) === false) {
            throw new \RuntimeException('Shim nicht beschreibbar: ' . $file);
        }

        return $current;
    }

    /**
     * Puts a previous shim back after a failed self-test. A broken shim
     * takes the ENTIRE site down (including /admin, so not even a rollback
     * would be reachable), which is why refreshShim() is always followed by
     * the self-test and this undo.
     */
    public function restoreShim(string $content): void
    {
        @file_put_contents($this->shimFile(), $content, LOCK_EX);
    }

    /**
     * Keeps .htaccess and .user.ini in the docroot up to date (the spec has
     * the updater maintain all three docroot files, not just the shim -
     * docs/spec/06-betrieb.md section 1). Without this, no existing
     * installation would ever receive a tightened CSP: the docroot is the
     * one place a release ZIP never touches.
     *
     * A file is only replaced when it is missing, or when it is still
     * byte-identical to the template of the PREVIOUS release - that is the
     * proof that nobody edited it by hand. Anything else is left alone and
     * reported, because a hand-tuned .htaccess (an IP block, a basic-auth
     * stanza) must not be silently reverted by an update.
     *
     * @return list<string> German notes for the update log, empty when
     *         everything is current
     */
    public function refreshDocrootFiles(): array
    {
        $neu = $this->baseDir . '/current/docker/web';
        $alt = $this->baseDir . '/releases/_prev/docker/web';
        $webDir = $this->baseDir . '/web';
        $meldungen = [];

        if (!is_dir($webDir)) {
            // Not our docroot layout - same reasoning as refreshShim().
            return [];
        }

        foreach (self::DOCROOT_FILES as $datei) {
            $vorlage = $neu . '/' . $datei;
            if (!is_file($vorlage)) {
                continue;
            }

            $ziel = $webDir . '/' . $datei;
            $inhalt = (string) file_get_contents($vorlage);
            $vorhanden = is_file($ziel) ? (string) file_get_contents($ziel) : null;

            if ($vorhanden === $inhalt) {
                continue;
            }

            $altVorlage = is_file($alt . '/' . $datei) ? (string) file_get_contents($alt . '/' . $datei) : null;
            if ($vorhanden !== null && $vorhanden !== $altVorlage) {
                $meldungen[] = sprintf(
                    'Hinweis: %s im Web-Verzeichnis wurde von Hand geändert und bleibt unverändert.',
                    $datei,
                );
                continue;
            }

            if (@file_put_contents($ziel, $inhalt, LOCK_EX) === false) {
                $meldungen[] = sprintf('Hinweis: %s konnte nicht aktualisiert werden.', $datei);
                continue;
            }

            $meldungen[] = sprintf('%s aktualisiert.', $datei);
        }

        return $meldungen;
    }

    private static function removeTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            assert($item instanceof \SplFileInfo);
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
