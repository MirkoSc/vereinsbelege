<?php

declare(strict_types=1);

namespace App\Service\Update;

/**
 * The ".htaccess that denies everything" for the directories next to the
 * docroot (issue #101, docs/spec/06-betrieb.md section 1).
 *
 * shared/ and releases/ are unreachable as long as the domain points at
 * web/ - but that is a setting in the hoster's customer menu. Should a domain
 * ever point one level higher, config.php (server key, database password)
 * and every blob would be public. The hosting check proved that the target
 * host evaluates .htaccess, so this costs nothing.
 *
 * current/ is covered by the copy of CONTENT at the root of every release
 * (repository root `.htaccess`, part of the release ZIP; DenyHtaccessTest
 * keeps both identical): current/ is a renamed release directory, a file
 * written into it would be gone with the next update.
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

    /** Directories next to the docroot that get the file written by the installer and the updater. */
    public const array DIRECTORIES = ['shared', 'releases'];

    /**
     * Makes sure $dir carries a denying .htaccess. A file that is already
     * there is never overwritten - it may have been tuned by hand - and
     * counts as fine as long as it denies access itself.
     *
     * @return bool false when the directory stays unprotected: missing,
     *         not writable, or a foreign .htaccess without a deny rule
     */
    public static function ensure(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $file = rtrim($dir, '/') . '/.htaccess';
        if (is_file($file)) {
            return self::denies((string) @file_get_contents($file));
        }

        return @file_put_contents($file, self::CONTENT, LOCK_EX) === strlen(self::CONTENT);
    }

    /**
     * ensure() for every directory in DIRECTORIES below $baseDir (the parent
     * of web/ and current/). A directory that does not exist yet is skipped:
     * there is nothing in it to protect, and whoever creates it calls this
     * again.
     *
     * @return list<string> the directories (relative names, never server
     *         paths) that stay unprotected - for a visible warning
     */
    public static function ensureAll(string $baseDir): array
    {
        $failed = [];
        foreach (self::DIRECTORIES as $name) {
            $dir = rtrim($baseDir, '/') . '/' . $name;
            if (is_dir($dir) && !self::ensure($dir)) {
                $failed[] = $name . '/';
            }
        }

        return $failed;
    }

    /**
     * Whether an existing .htaccess denies access on its own: a "Require all
     * denied" or "Deny from all" line that is not commented out.
     */
    public static function denies(string $content): bool
    {
        return preg_match('/^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\s*$/mi', $content) === 1;
    }
}
