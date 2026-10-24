<?php

declare(strict_types=1);

namespace App\Service\Backup;

/**
 * The command line of bin/backup.php, parsed strictly (issue #155): an
 * option the script does not know is an error, not a backup - `--help`
 * used to create one.
 *
 * Pure PHP without I/O so that the parsing is testable without starting a
 * process (the tests cannot use proc_open on the target host).
 */
final readonly class BackupCliOptions
{
    public const string USAGE = <<<'TXT'
        Usage: php bin/backup.php [--mit-config] [--ohne-blobs]

          --mit-config  include shared/config.php (holds the server key - only
                        for a ZIP that stays somewhere safe)
          --ohne-blobs  leave the encrypted blob files of storage backend `fs`
                        out (schema-only snapshot)
          --help, -h    show this help and exit

        Without --mit-config the backup carries NO server key: after restoring
        it on a new server, nobody can log in until an admin re-links their
        account in the installer with the recovery key and their password
        (docs/spec/06-betrieb.md section 2). Keep a copy of shared/config.php
        with the recovery key.
        TXT;

    private const array KNOWN = ['--mit-config', '--ohne-blobs', '--help', '-h'];

    public function __construct(
        public bool $hilfe,
        public bool $mitConfig,
        public bool $mitBlobs,
    ) {
    }

    /**
     * @param list<string> $argv as PHP hands it in, the script name first
     *
     * @throws \InvalidArgumentException naming the first unknown argument
     */
    public static function parse(array $argv): self
    {
        $argumente = array_slice($argv, 1);
        foreach ($argumente as $argument) {
            if (!in_array($argument, self::KNOWN, true)) {
                throw new \InvalidArgumentException(sprintf('Unknown option: %s', $argument));
            }
        }

        return new self(
            hilfe: in_array('--help', $argumente, true) || in_array('-h', $argumente, true),
            mitConfig: in_array('--mit-config', $argumente, true),
            mitBlobs: !in_array('--ohne-blobs', $argumente, true),
        );
    }
}
