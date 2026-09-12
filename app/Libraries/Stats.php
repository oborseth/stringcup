<?php

namespace App\Libraries;

/**
 * Aggregate counters behind the public dashboard.
 *
 * Two rules govern everything here.
 *
 * **Nothing per-event is ever recorded.** Counters are bucketed by the hour
 * and keyed only by a metric name, so nothing can be resolved back to a
 * conversation. A per-event log would publish the "when" and "how often" that
 * SECURITY.md already admits the relay can see — handing it to every observer
 * would be strictly worse than the leak this project spent effort closing.
 *
 * **Counting must never break a request.** Every write is wrapped and
 * swallowed. A dashboard is worth less than a delivered message.
 */
class Stats
{
    // --- metric names. Add to the dashboard's METRICS list when adding one. --
    public const MESSAGES_RELAYED     = 'messages_relayed';
    public const MESSAGES_ACKED       = 'messages_acked';
    public const IDENTITIES_CREATED   = 'identities_registered';
    public const RENDEZVOUS_PAIRED    = 'rendezvous_paired';
    public const SENDS_REFUSED_FULL   = 'sends_refused_inbox_full';

    /**
     * Delivery-latency histogram edges, in seconds.
     *
     * A histogram rather than a running mean because percentiles are what
     * matters and a mean hides them — and because storing raw durations would
     * be a per-event record, which is exactly what this class refuses to keep.
     */
    public const LATENCY_BUCKETS = [1, 5, 30, 300];

    /**
     * Add to a counter for the current hour.
     *
     * One upsert against the unique key on (metric, bucket) — no read, no
     * transaction, and safe under concurrency.
     */
    public static function bump(string $metric, int $by = 1): void
    {
        if ($by < 1) {
            return;
        }

        try {
            $db = \Config\Database::connect();
            $db->query(
                'INSERT INTO stats_counters (metric, bucket, count) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE count = count + ?',
                [$metric, date('Y-m-d H:00:00'), $by, $by]
            );
        } catch (\Throwable $e) {
            log_message('error', 'Stats::bump({m}) failed: {msg}', [
                'm'   => $metric,
                'msg' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record one delivery latency into the histogram.
     *
     * Called at ACK time: the gap between a message being stored and being
     * acknowledged is the only delivery timing the relay can observe, and it
     * is the number a caller actually wants ("how long until my peer had it").
     * It includes however long the recipient took to get round to polling, so
     * it is an upper bound on transport latency, not a measure of it — the
     * dashboard says so.
     */
    public static function recordDeliveryLatency(int $seconds): void
    {
        foreach (self::LATENCY_BUCKETS as $edge) {
            if ($seconds <= $edge) {
                self::bump('delivery_le_' . $edge);
                return;
            }
        }
        self::bump('delivery_over_' . self::largestBucket());
    }

    /**
     * The largest histogram edge.
     *
     * Via a local copy because `end()` takes its argument by reference and a
     * class constant cannot be passed by reference in PHP 8 — it is a fatal
     * error, not a notice, and `php -l` does not catch it.
     */
    public static function largestBucket(): int
    {
        $edges = self::LATENCY_BUCKETS;
        return (int) end($edges);
    }

    /** Every latency bucket name, in order, plus the overflow bucket. */
    public static function latencyMetrics(): array
    {
        $names = [];
        foreach (self::LATENCY_BUCKETS as $edge) {
            $names[] = 'delivery_le_' . $edge;
        }
        $names[] = 'delivery_over_' . self::largestBucket();
        return $names;
    }

    /**
     * All-time totals per metric: metric => count.
     *
     * @param list<string> $metrics
     * @return array<string,int>
     */
    public static function totals(array $metrics): array
    {
        $out = array_fill_keys($metrics, 0);
        if ($metrics === []) {
            return $out;
        }

        try {
            $db   = \Config\Database::connect();
            $rows = $db->table('stats_counters')
                ->select('metric, SUM(count) AS total')
                ->whereIn('metric', $metrics)
                ->groupBy('metric')
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $out[$row['metric']] = (int) $row['total'];
            }
        } catch (\Throwable $e) {
            log_message('error', 'Stats::totals failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Hourly series for the last $hours hours: metric => [count per hour].
     *
     * Index 0 is the oldest hour. Missing buckets read as 0 rather than being
     * dropped, so the series is evenly spaced and a gap means "nothing
     * happened" instead of "no data".
     *
     * @param list<string> $metrics
     * @return array<string,list<int>>
     */
    public static function hourly(array $metrics, int $hours = 24): array
    {
        $series = [];
        foreach ($metrics as $metric) {
            $series[$metric] = array_fill(0, $hours, 0);
        }
        if ($metrics === []) {
            return $series;
        }

        // Hour labels, oldest first, so a row can be placed by offset.
        $index = [];
        for ($i = 0; $i < $hours; $i++) {
            $index[date('Y-m-d H:00:00', strtotime('-' . ($hours - 1 - $i) . ' hours'))] = $i;
        }

        try {
            $db   = \Config\Database::connect();
            $rows = $db->table('stats_counters')
                ->select('metric, bucket, count')
                ->whereIn('metric', $metrics)
                ->where('bucket >=', date('Y-m-d H:00:00', strtotime('-' . ($hours - 1) . ' hours')))
                ->get()
                ->getResultArray();

            foreach ($rows as $row) {
                $slot = $index[$row['bucket']] ?? null;
                if ($slot !== null) {
                    $series[$row['metric']][$slot] = (int) $row['count'];
                }
            }
        } catch (\Throwable $e) {
            log_message('error', 'Stats::hourly failed: {msg}', ['msg' => $e->getMessage()]);
        }

        return $series;
    }
}
