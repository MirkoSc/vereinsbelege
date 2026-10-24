<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * The ".htaccess that denies everything" for the directories next to the
 * docroot (issue #101, docs/spec/06-betrieb.md section 1).
 *
 * shared/, releases/ and current/ are unreachable as long as the domain
 * points at web/ - but that is a setting in the hoster's customer menu.
 * Should a domain ever point one level higher, config.php (server key,
 * database password), every blob and the code would be public. The hosting
 * check proved that the target host evaluates .htaccess, so this costs
 * nothing.
 *
 * current/ is a renamed release directory, so the same content also sits at
 * the root of every release (repository root `.htaccess`, part of the
 * release ZIP; DenyHtaccessTest keeps both identical): that way current/ is
 * locked the moment it is switched in. Writing it here as well covers a
 * rollback to a release built before issue #101.
 *
 * Dependency-free on purpose: bin/build_setup.php inlines this class into
 * setup.php, which runs before there is an autoloader.
 */
final class DenyHtaccess
{
    /**
     * Apache 2.4 (mod_authz_core) understands "Require", 2.2 only
     * "Order/Deny". The IfModule pair picks the one the server knows. Should
     * the host's AllowOverride forbid the directive, Apache answers with a
     * 500 - which serves nothing either.
     */
    public const string CONTENT = <<<'HTACCESS'
        # Written by Vereinsbelege - do not remove. Nothing in this directory
        # may ever be served by the web server, even if a domain is pointed
        # one level too high (docs/spec/06-betrieb.md section 1).
        <IfModule mod_authz_core.c>
            Require all denied
        </IfModule>
        <IfModule !mod_authz_core.c>
            Order allow,deny
            Deny from all
        </IfModule>

        HTACCESS;

    /** Directories next to the docroot that must never be served. */
    public const array DIRECTORIES = ['shared', 'releases', 'current'];

    public const string PROBLEM_MISSING_DIR = 'Verzeichnis fehlt';
    public const string PROBLEM_NOT_WRITABLE = 'Datei nicht schreibbar – bitte Schreibrechte prüfen';
    public const string PROBLEM_FOREIGN_FILE = 'vorhandene .htaccess sperrt nicht vollständig – bitte von Hand prüfen';

    /**
     * Makes sure $dir carries a denying .htaccess. A file that is already
     * there is never overwritten - it may have been tuned by hand - and
     * counts as fine only when denies() says it locks the whole directory.
     *
     * @return string|null null when the directory is protected, otherwise
     *         one of the PROBLEM_* texts (German, shown to the admin)
     */
    public static function ensure(string $dir): ?string
    {
        if (!is_dir($dir)) {
            return self::PROBLEM_MISSING_DIR;
        }

        $file = rtrim($dir, '/') . '/.htaccess';
        if (file_exists($file)) {
            if (!is_file($file)) {
                return self::PROBLEM_NOT_WRITABLE;
            }

            return self::denies((string) @file_get_contents($file)) ? null : self::PROBLEM_FOREIGN_FILE;
        }

        return @file_put_contents($file, self::CONTENT, LOCK_EX) === strlen(self::CONTENT)
            ? null
            : self::PROBLEM_NOT_WRITABLE;
    }

    /**
     * ensure() for every directory in DIRECTORIES below $baseDir (the parent
     * of web/). A directory that does not exist yet is skipped: there is
     * nothing in it to protect, and whoever creates it calls this again.
     *
     * @return array<string, string> unprotected directory (relative name,
     *         never a server path) => problem, empty when all are locked
     */
    public static function ensureAll(string $baseDir): array
    {
        $problems = [];
        foreach (self::DIRECTORIES as $name) {
            $dir = rtrim($baseDir, '/') . '/' . $name;
            if (!is_dir($dir)) {
                continue;
            }
            $problem = self::ensure($dir);
            if ($problem !== null) {
                $problems[$name . '/'] = $problem;
            }
        }

        return $problems;
    }

    /**
     * "shared/ (reason), releases/ (reason)" - for setup.php, the installer
     * and the update log.
     *
     * @param array<string, string> $problems what ensureAll() returned
     */
    public static function describe(array $problems): string
    {
        $parts = [];
        foreach ($problems as $dir => $problem) {
            $parts[] = $dir . ' (' . $problem . ')';
        }

        return implode(', ', $parts);
    }

    /**
     * Whether an existing .htaccess locks the whole directory on its own -
     * deliberately strict, a false "protected" is the expensive mistake:
     *
     *  - a "Require all denied" or "Deny from all" line outside any section
     *    other than <IfModule> (inside <Files>, <FilesMatch>, <If> ... it
     *    only covers some requests);
     *  - and nothing that grants access again (Require all granted, Allow
     *    from, Satisfy any).
     */
    public static function denies(string $content): bool
    {
        $depth = 0;
        $denies = false;
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('#^</\s*([A-Za-z]+)#', $line, $m) === 1) {
                if (strcasecmp($m[1], 'IfModule') !== 0) {
                    --$depth;
                }
                continue;
            }
            if (preg_match('#^<\s*([A-Za-z]+)#', $line, $m) === 1) {
                if (strcasecmp($m[1], 'IfModule') !== 0) {
                    ++$depth;
                }
                continue;
            }
            if (preg_match('/^(Require\s+all\s+granted|Allow\s+from|Satisfy\s+any)\b/i', $line) === 1) {
                return false;
            }
            if ($depth === 0 && preg_match('/^(Require\s+all\s+denied|Deny\s+from\s+all)$/i', $line) === 1) {
                $denies = true;
            }
        }

        return $denies;
    }
}
