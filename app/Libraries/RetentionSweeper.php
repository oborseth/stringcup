<?php

namespace App\Libraries;

use App\Models\ApiTokenModel;

/**
 * Reclaim data that nobody can reach any more.
 *
 * **This never expires a deliverable message.** Only an ACK deletes a message,
 * and that is load-bearing rather than accidental: it is what makes delivery
 * at-least-once and crash-safe. An age-based sweep would silently destroy mail
 * the sender had been told was stored, so the pending inbox is bounded at the
 * other end instead — `MessageController::MAX_PENDING_MESSAGES` /
 * `MAX_PENDING_BYTES` refuse the *send* with 507 while someone can still act
 * on it. Everything here is reachability-based, which is a much stronger
 * property than an age cut-off:
 *
 * > A message is removed only when its recipient can no longer authenticate,
 * > and therefore could never fetch it under any circumstances.
 *
 * The consequence worth stating plainly: an agent that polls once a month
 * loses nothing, no matter how old its mail is. Age alone is never a reason to
 * delete anything.
 *
 * Do not add an age-based rule here without changing PROTOCOL.md B.3.3 and
 * every place that promises messages persist until acknowledged. That is a
 * protocol change, not housekeeping.
 *
 * ## Running
 *
 * `maybeRun()` is called from a request path and is throttled by a marker file
 * to at most once an hour, so a busy service sweeps regularly and an idle one
 * does not sweep at all — which is correct, because an idle service is not
 * accumulating anything. That keeps self-hosters from needing cron at all,
 * matching how `idempotency_keys` and `rendezvous` already prune themselves.
 * `php spark db:retain` does the same work on demand.
 */
class RetentionSweeper
{
    /**
     * Grace period past the token inactivity TTL before an identity's mail is
     * considered unreachable.
     *
     * A token is judged expired at authentication time, not by a sweep, so a
     * token that is one second past the TTL is dead but its row still says
     * `is_active = 1`. The grace period means a clock skew, a daylight-saving
     * edge or a brief outage cannot cause mail to be reclaimed from an agent
     * that was about to come back and could still have used its token.
     */
    public const GRACE_DAYS = 7;

    /** Shortest interval between automatic sweeps. */
    public const THROTTLE_SECONDS = 3600;

    private string $marker;

    public function __construct(?string $marker = null)
    {
        $this->marker = $marker ?? WRITEPATH . 'cache/retention_last_run';
    }

    /**
     * Sweep if nothing has swept recently. Safe to call on every request.
     *
     * Failures are swallowed: reclaiming storage must never turn a working
     * send into an error. The next request tries again.
     */
    public function maybeRun(): void
    {
        try {
            if (!$this->claimTurn()) {
                return;
            }
            $this->run(true);
        } catch (\Throwable $e) {
            log_message('error', 'Retention sweep failed: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    /**
     * Take the sweep slot if it is due, atomically.
     *
     * The marker's mtime is the last run. `LOCK_EX` plus a re-check inside the
     * lock is what stops a burst of concurrent requests from all deciding they
     * are due and sweeping at once.
     */
    private function claimTurn(): bool
    {
        $dir = dirname($this->marker);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return false;
        }

        $handle = @fopen($this->marker, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return false;   // someone else is sweeping right now
            }

            $last = (int) trim((string) fread($handle, 32));
            if (time() - $last < self::THROTTLE_SECONDS) {
                return false;
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) time());
            fflush($handle);

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Apply every rule. Returns label => rows affected (or matched, on a dry
     * run), in the order applied — messages before the tokens and identities
     * they depend on, so a single pass fully reclaims a departed agent.
     *
     * @return array<string,int>
     */
    public function run(bool $force): array
    {
        $db     = \Config\Database::connect();
        $cutoff = sprintf(
            'DATE_SUB(NOW(), INTERVAL %d DAY)',
            ApiTokenModel::INACTIVITY_TTL_DAYS + self::GRACE_DAYS
        );

        // An identity is unreachable when it holds no token that could still
        // authenticate: nothing active, and nothing used recently enough to
        // beat the inactivity TTL.
        $unreachable = "NOT EXISTS (
            SELECT 1 FROM api_tokens t
             WHERE t.identity_id = i.id
               AND t.is_active = 1
               AND COALESCE(t.last_used_at, t.created_at) >= {$cutoff})";

        $plan = [
            // Addressed to an identity that no longer exists at all. Nobody
            // can authenticate as a row that is gone.
            'messages for a deleted identity' =>
                'DELETE m FROM messages m
                  LEFT JOIN identities i ON i.external_id = m.recipient_id
                      WHERE i.id IS NULL',

            // The main reclaim: the recipient exists but can never log in
            // again, so this mail is uncollectable by construction.
            'messages whose recipient can no longer authenticate' =>
                "DELETE m FROM messages m
                   JOIN identities i ON i.external_id = m.recipient_id
                       WHERE {$unreachable}",

            'api tokens revoked or past the inactivity TTL' =>
                "DELETE FROM api_tokens
                      WHERE is_active = 0
                         OR COALESCE(last_used_at, created_at) < {$cutoff}",

            'expired rendezvous claims' =>
                'DELETE FROM rendezvous WHERE expires_at < NOW()',

            'idempotency keys past their 24h retention' =>
                'DELETE FROM idempotency_keys
                      WHERE created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)',

            // Membership of a topic whose owner is gone, and then the topic.
            // Left behind, these keep a departed agent in a roster that
            // broadcasts would still try to encrypt for.
            'topic memberships for a deleted identity' =>
                'DELETE tm FROM topic_members tm
                  LEFT JOIN identities i ON i.id = tm.identity_id
                      WHERE i.id IS NULL',

            'topics whose owner is gone' =>
                'DELETE t FROM topics t
                  LEFT JOIN identities i ON i.id = t.owner_identity_id
                      WHERE i.id IS NULL',
        ];

        $results = [];
        foreach ($plan as $label => $sql) {
            if ($force) {
                $db->query($sql);
                $results[$label] = $db->affectedRows();
            } else {
                $results[$label] = $this->countFor($db, $sql);
            }
        }

        // Identities are deliberately *not* deleted. They are a single public
        // key each, so they are not what grows, and a peer holding a pinned
        // fingerprint should get an honest answer rather than a 404 that looks
        // like a substitution. Assigned ids are 120 bits of randomness, so
        // nothing is ever reused.

        return $results;
    }

    /**
     * How many rows a DELETE would affect, by rewriting it as a COUNT.
     */
    private function countFor($db, string $sql): int
    {
        if (preg_match('/^\s*DELETE\s+(\w+)\s+FROM\s+(.*)$/is', $sql, $m)) {
            $count = "SELECT COUNT(*) AS c FROM {$m[2]}";
        } elseif (preg_match('/^\s*DELETE\s+FROM\s+(.*)$/is', $sql, $m)) {
            $count = "SELECT COUNT(*) AS c FROM {$m[1]}";
        } else {
            return 0;
        }

        return (int) ($db->query($count)->getRowArray()['c'] ?? 0);
    }
}
