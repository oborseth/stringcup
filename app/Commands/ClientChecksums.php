<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Regenerate public/clients-SHA256SUMS for the published client files.
 *
 * Exists because of a failure mode reported from a sandboxed agent harness:
 * the host refused to execute any freshly-downloaded module, categorically,
 * which makes `require_version()` unreachable — the integrity check the docs
 * emphasise is the first thing that cannot run, because calling it means
 * importing the file you are trying to vet.
 *
 * Hashing is a read, and hosts that forbid execution generally permit it. So
 * an agent can at least answer "is my copy current?" without running anything.
 *
 * **Be clear what this does not do.** The sums are served from the same origin
 * as the files, so a hostile or compromised relay could serve a bad file and a
 * matching hash. This detects a stale copy and a corrupted download. It is not
 * authentication, and the docs must not imply otherwise.
 */
class ClientChecksums extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'clients:checksums';
    protected $description = 'Regenerate the SHA-256 manifest for published client files.';
    protected $usage       = 'clients:checksums [--check]';

    /** Exactly the files the nginx allowlist publishes under /clients/. */
    public const PUBLISHED = [
        'stringcup.py',
        'stringcup_mcp.py',
        'example_agent.py',
        'test_contract.py',
        'README.md',
    ];

    public const MANIFEST = FCPATH . 'clients-SHA256SUMS';

    public static function expected(): array
    {
        $dir  = ROOTPATH . 'clients/python/';
        $sums = [];
        foreach (self::PUBLISHED as $name) {
            $path = $dir . $name;
            if (is_file($path)) {
                $sums[$name] = hash_file('sha256', $path);
            }
        }
        return $sums;
    }

    public static function render(array $sums): string
    {
        // No blank lines: GNU sha256sum -c warns "improperly formatted" on
        // them, and a manifest meant to be piped into a checker should verify
        // without noise. Comment lines are skipped cleanly; blanks are not.
        $out = "# SHA-256 of the files published under https://stringcup.com/clients/\n"
             . "# Regenerate with: php spark clients:checksums\n"
             . "#\n"
             . "# These detect a STALE or CORRUPTED copy without executing anything -\n"
             . "# useful where a host forbids running downloaded code, which is exactly\n"
             . "# where require_version() cannot help you: calling it means importing\n"
             . "# the file you are trying to vet.\n"
             . "#\n"
             . "# They are NOT authentication. This file is served from the same origin\n"
             . "# as the files it describes, so a compromised relay could serve both a\n"
             . "# bad file and a matching hash. Treat it as an integrity check against\n"
             . "# staleness and transfer corruption, nothing more.\n"
             . "#\n"
             . "# Verify everything you downloaded:\n"
             . "#   sha256sum -c clients-SHA256SUMS\n"
             . "# Verify one file (portable; --ignore-missing needs coreutils 8.25+):\n"
             . "#   grep ' stringcup.py$' clients-SHA256SUMS | sha256sum -c -\n";
        foreach ($sums as $name => $hash) {
            $out .= sprintf("%s  %s\n", $hash, $name);
        }
        return $out;
    }

    public function run(array $params)
    {
        $sums    = self::expected();
        $content = self::render($sums);

        if (CLI::getOption('check')) {
            $current = is_file(self::MANIFEST) ? file_get_contents(self::MANIFEST) : '';
            if ($current === $content) {
                CLI::write('OK — manifest matches the published files.', 'green');
                return 0;
            }
            CLI::write('STALE — manifest does not match. Run: php spark clients:checksums', 'red');
            return 1;
        }

        file_put_contents(self::MANIFEST, $content);
        CLI::write('Wrote ' . self::MANIFEST, 'green');
        foreach ($sums as $name => $hash) {
            CLI::write(sprintf('  %s  %s', substr($hash, 0, 16) . '…', $name));
        }
        return 0;
    }
}
