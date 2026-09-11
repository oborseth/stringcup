<?php

namespace App\Libraries;

/**
 * Bounds how many requests may be parked in a long poll at once.
 *
 * Each waiting client occupies a PHP-FPM worker for the whole hold. This pool
 * (pm.max_children) is shared with every other vhost on the host, so an
 * unbounded number of waiters is a denial-of-service against unrelated sites,
 * not just this one. The guard caps concurrency and lets the controller
 * degrade to an immediate response instead of competing for the last worker.
 *
 * Slots are stored as expiry timestamps in a single flock'd JSON file. Storing
 * expiries rather than a bare counter makes the guard self-healing: a worker
 * killed mid-hold leaves a slot that later acquirers prune, so the pool cannot
 * leak itself into permanent unavailability.
 */
class LongPollGuard
{
    /**
     * Concurrent holds allowed. Deliberately well under pm.max_children (50
     * here, shared across vhosts) so ordinary short requests always have
     * workers available. Override with STRINGCUP_LONGPOLL_SLOTS.
     */
    public const DEFAULT_SLOTS = 8;

    private string $file;
    private int $slots;
    private ?string $held = null;

    public function __construct(?string $file = null, ?int $slots = null)
    {
        $this->file = $file ?? WRITEPATH . 'cache/longpoll_slots.json';

        $configured = $slots ?? (int) (getenv('STRINGCUP_LONGPOLL_SLOTS') ?: 0);
        $this->slots = $configured > 0 ? $configured : self::DEFAULT_SLOTS;
    }

    public function capacity(): int
    {
        return $this->slots;
    }

    /**
     * Try to take a slot for `$ttl` seconds. False means the pool is full and
     * the caller must not park.
     */
    public function acquire(int $ttl): bool
    {
        if ($this->held !== null) {
            return true;
        }

        $handle = $this->open();
        if ($handle === null) {
            // Cannot account for slots, so refuse to park rather than risk
            // unbounded holds.
            return false;
        }

        try {
            $now   = time();
            $state = $this->read($handle);

            // Drop slots whose holder died or overran.
            $state = array_filter($state, static fn ($expires) => $expires > $now);

            if (count($state) >= $this->slots) {
                return false;
            }

            $id           = bin2hex(random_bytes(8));
            $state[$id]   = $now + $ttl + 5; // small grace beyond the wait
            $this->held   = $id;

            $this->write($handle, $state);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Give the slot back. Safe to call when nothing is held.
     */
    public function release(): void
    {
        if ($this->held === null) {
            return;
        }

        $id         = $this->held;
        $this->held = null;

        $handle = $this->open();
        if ($handle === null) {
            return; // the expiry will reclaim it
        }

        try {
            $now   = time();
            $state = $this->read($handle);
            unset($state[$id]);
            $state = array_filter($state, static fn ($expires) => $expires > $now);
            $this->write($handle, $state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Slots currently taken, after pruning expired ones. Diagnostics only.
     */
    public function inUse(): int
    {
        $handle = $this->open();
        if ($handle === null) {
            return $this->slots;
        }

        try {
            $now = time();
            return count(array_filter(
                $this->read($handle),
                static fn ($expires) => $expires > $now
            ));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return resource|null An exclusively locked handle, or null on failure.
     */
    private function open()
    {
        $dir = dirname($this->file);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            return null;
        }

        $handle = @fopen($this->file, 'c+');
        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     * @return array<string,int>
     */
    private function read($handle): array
    {
        rewind($handle);
        $raw = stream_get_contents($handle);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * @param resource $handle
     * @param array<string,int> $state
     */
    private function write($handle, array $state): void
    {
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state));
        fflush($handle);
    }
}
