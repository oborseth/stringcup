<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Export application tables to a JSON snapshot before destructive work.
 *
 * **A snapshot is the most sensitive artifact this system produces.** It is
 * not "a file containing API token hashes", which is what SECURITY.md used to
 * say -- `token_hash` is SHA-256 over 256 random bits and is the *least*
 * sensitive field in it. Unless `--no-messages` is passed the snapshot holds:
 *
 *   - every pending CIPHERTEXT, with both party ids, headers and timestamps
 *   - the full membership graph (topics, topic_members, rendezvous)
 *
 * And it re-creates the retention violation this project already fixed for
 * the nginx access log. The store honours "only an acknowledgement deletes";
 * a snapshot does not. Take one today, the recipient ACKs tomorrow, the relay
 * honestly reports the mail as gone -- and the ciphertext is still in
 * `writable/backups/`, where a later compromise of that recipient's static key
 * decrypts it. Identical property, identical consequence, except this time it
 * is a feature we ship rather than a vhost default we inherited. An auditor
 * spotted the repeat.
 *
 * Three things follow, all implemented here:
 *
 *   1. `--no-messages` excludes message bodies, and that is the flag to use
 *      for the command's stated purpose. A schema migration needs the schema
 *      and the small tables; it rarely needs other people's sealed mail.
 *   2. The file is created 0600 **before** any bytes are written. It used to
 *      be `file_put_contents()` then `chmod()`, leaving it world-readable for
 *      the duration of the write -- and the write is the slow part, since it
 *      is the whole database.
 *   3. `--prune-days` removes old snapshots, because nothing else does.
 *      `RetentionSweeper` never touched this directory, so snapshots
 *      accumulated forever and quietly falsified the reachability-based
 *      deletion guarantee for any identity whose mail one captured.
 */
class DbBackup extends BaseCommand
{
    protected $group       = 'Database';
    protected $name        = 'db:backup';
    protected $description = 'Export application tables to a JSON snapshot before destructive work.';
    protected $usage       = 'db:backup [--no-messages] [--prune-days N]';

    /** Everything except message bodies. */
    private const SMALL_TABLES = [
        'identities', 'api_tokens', 'idempotency_keys',
        'topics', 'topic_members', 'rendezvous',
        'prekey_bundles', 'prekeys',
    ];

    public function run(array $params)
    {
        $noMessages = in_array('--no-messages', $params, true)
            || array_key_exists('no-messages', $params);

        $pruneDays = null;
        foreach ($params as $key => $value) {
            if ($key === 'prune-days') {
                $pruneDays = (int) $value;
            }
        }
        $flat = array_values($params);
        foreach ($flat as $i => $value) {
            if ($value === '--prune-days' && isset($flat[$i + 1])) {
                $pruneDays = (int) $flat[$i + 1];
            }
        }

        $db     = \Config\Database::connect();
        $tables = self::SMALL_TABLES;
        if (! $noMessages) {
            $tables[] = 'messages';
        }

        $out = [
            'taken_at'         => date('c'),
            'includes_messages' => ! $noMessages,
            'tables'           => [],
        ];

        foreach ($tables as $t) {
            if (! $db->tableExists($t)) {
                continue;
            }

            $rows = $db->query("SELECT * FROM `$t`")->getResultArray();

            // Binary columns are not JSON-safe; base64 them so it round-trips.
            foreach ($rows as &$r) {
                foreach ($r as $k => &$v) {
                    if (is_string($v) && ! mb_check_encoding($v, 'UTF-8')) {
                        $v = 'base64:' . base64_encode($v);
                    }
                }
            }
            unset($r, $v);

            $out['tables'][$t] = $rows;
            CLI::write(sprintf('  %-20s %d rows', $t, count($rows)));
        }

        $dir = WRITEPATH . 'backups';
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        $path = $dir . '/snapshot-' . date('Ymd-His') . '.json';

        // 0600 BEFORE the write, not after. fopen+chmod would still race; the
        // mode has to be set at creation.
        $fd = @fopen($path, 'xb');
        if ($fd === false) {
            CLI::error('Could not create ' . $path);
            return;
        }
        chmod($path, 0600);
        fwrite($fd, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fclose($fd);

        CLI::write('');
        CLI::write(
            'Snapshot: ' . $path . ' (' . number_format(filesize($path)) . ' bytes)',
            'green'
        );

        if ($noMessages) {
            CLI::write('  message bodies EXCLUDED', 'green');
        } else {
            CLI::write(
                '  WARNING: contains every pending ciphertext. This file outlives '
                . 'an ACK, so mail the relay reports as deleted is still in here. '
                . 'Use --no-messages for schema work.',
                'yellow'
            );
        }

        if ($pruneDays !== null && $pruneDays > 0) {
            $this->prune($dir, $pruneDays);
        } else {
            CLI::write(
                '  nothing prunes this directory automatically; pass '
                . '--prune-days N, or schedule it.',
                'yellow'
            );
        }
    }

    private function prune(string $dir, int $days): void
    {
        $cutoff  = time() - $days * 86400;
        $removed = 0;

        foreach (glob($dir . '/snapshot-*.json') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < $cutoff) {
                if (@unlink($file)) {
                    $removed++;
                }
            }
        }

        CLI::write(sprintf('  pruned %d snapshot(s) older than %d day(s)', $removed, $days));
    }
}
