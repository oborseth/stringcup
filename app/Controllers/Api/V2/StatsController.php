<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Libraries\LongPollGuard;
use App\Libraries\Stats;
use App\Controllers\Api\V2\MessageController;

/**
 * GET /api/v2/stats — the public dashboard's data source.
 *
 * Unauthenticated, so every field here is a deliberate disclosure. The rules
 * it follows are the point of the file:
 *
 * **Nothing identifying.** No identifiers, no topic names (the topic
 * namespace is deliberately non-enumerable — see TopicController), no IP
 * data, no message sizes, no per-event anything.
 *
 * **Small counts are suppressed, not rounded.** At low traffic an "aggregate"
 * is not aggregate: with two active agents, "8 messages in the last hour" *is*
 * a description of one conversation. Anything under SUPPRESS_BELOW is reported
 * as the string "<5" rather than a number.
 *
 * **The timeline only appears once there is a crowd to hide in.** A sparkline
 * of small counts still leaks per-hour timing even when every value is
 * suppressed — the *shape* is the leak. So the 24h series is withheld
 * entirely until the window's total clears TIMELINE_MIN, and the response says
 * why instead of returning a misleading flat line.
 *
 * **All-time totals are exact.** They carry no timing information, and they
 * are the honest answer to "is this thing actually used".
 *
 * Cached, because a dashboard polls: one DB read serves every viewer for
 * CACHE_SECONDS. Deliberately *not* streamed — SSE or websockets would each
 * hold a PHP-FPM worker exactly as long polling does, competing with the hold
 * pool and the other vhosts on this host.
 */
class StatsController extends BaseController
{
    use ResponseTrait;

    /** Counts below this are published as "<5". */
    public const SUPPRESS_BELOW = 5;

    /** The 24h series is withheld until its total reaches this. */
    public const TIMELINE_MIN = 50;

    /** How long one computation serves every viewer. */
    public const CACHE_SECONDS = 30;

    private const USAGE_METRICS = [
        Stats::MESSAGES_RELAYED,
        Stats::MESSAGES_ACKED,
        Stats::IDENTITIES_CREATED,
        Stats::RENDEZVOUS_PAIRED,
        Stats::SENDS_REFUSED_FULL,
    ];

    public function index()
    {
        $cache = service('cache');
        $key   = 'stringcup_stats_v1';

        $payload = $cache->get($key);
        if (!is_array($payload)) {
            $payload = $this->build();
            $cache->save($key, $payload, self::CACHE_SECONDS);
        }

        $payload['cache_seconds'] = self::CACHE_SECONDS;

        return $this->respond($payload);
    }

    private function build(): array
    {
        return [
            'service'     => 'Stringcup',
            'generated_at' => gmdate('c'),
            'privacy'     => 'Aggregates only. No identifiers, topic names, message '
                . 'sizes, IP data or per-message timing is published. Counts below '
                . self::SUPPRESS_BELOW . ' are reported as "<' . self::SUPPRESS_BELOW
                . '", and the hourly timeline is withheld until there is enough '
                . 'volume for it not to describe individual conversations.',

            'health'    => $this->health(),
            'capacity'  => $this->capacity(),
            'delivery'  => $this->delivery(),
            'usage'     => $this->usage(),
            'limits'    => $this->limits(),
        ];
    }

    /**
     * Liveness of the things that can independently fail.
     */
    private function health(): array
    {
        $checks = [];

        try {
            \Config\Database::connect()->query('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Throwable $e) {
            $checks['database'] = 'error';
        }

        $checks['storage'] = is_writable(WRITEPATH) ? 'ok' : 'error';
        $checks['cache']   = is_writable(WRITEPATH . 'cache') ? 'ok' : 'error';

        $status = in_array('error', $checks, true) ? 'degraded' : 'ok';

        return ['status' => $status, 'checks' => $checks];
    }

    /**
     * Long-poll pool occupancy — a property of the server, not of any user.
     */
    private function capacity(): array
    {
        $slots = (int) (getenv('STRINGCUP_LONGPOLL_SLOTS') ?: LongPollGuard::DEFAULT_SLOTS);

        $inUse = null;
        try {
            $inUse = (new LongPollGuard())->inUse();
        } catch (\Throwable $e) {
            // Reporting capacity is not worth failing the whole response.
        }

        return [
            'long_poll_slots'        => $slots,
            'long_poll_slots_in_use' => $inUse,
            'note'                   => 'Each parked long poll occupies one PHP-FPM worker. '
                . 'Over the cap the relay answers immediately with '
                . 'X-Long-Poll: unavailable rather than queueing.',
        ];
    }

    /**
     * Delivery latency as a histogram, with approximate percentiles.
     *
     * This measures store-to-acknowledgement, which includes however long the
     * recipient took to poll and process. It is an upper bound on transport
     * latency, not a measurement of it, and says so — publishing it as
     * "latency" without that caveat would overstate the relay's speed.
     */
    private function delivery(): array
    {
        $names   = Stats::latencyMetrics();
        $totals  = Stats::totals($names);
        $samples = array_sum($totals);

        $histogram = [];
        foreach (Stats::LATENCY_BUCKETS as $edge) {
            $histogram['<=' . $edge . 's'] = $totals['delivery_le_' . $edge] ?? 0;
        }
        $last = Stats::largestBucket();
        $histogram['>' . $last . 's'] = $totals['delivery_over_' . $last] ?? 0;

        $result = [
            'samples'   => $samples,
            'histogram' => $histogram,
            'note'      => 'Time from a message being stored to being acknowledged, so it '
                . 'includes the recipient\'s own polling and processing. An upper bound '
                . 'on transport latency, not a measurement of it.',
        ];

        if ($samples >= self::SUPPRESS_BELOW) {
            $result['p50'] = $this->percentileFrom($histogram, $samples, 0.50);
            $result['p95'] = $this->percentileFrom($histogram, $samples, 0.95);
        } else {
            $result['p50'] = null;
            $result['p95'] = null;
            $result['percentiles_withheld'] = 'Too few samples to publish without '
                . 'describing individual messages.';
        }

        return $result;
    }

    /**
     * The bucket a percentile falls in. Bucket labels, not interpolated
     * numbers — the data is a histogram and pretending otherwise would invent
     * precision it does not have.
     */
    private function percentileFrom(array $histogram, int $total, float $q): ?string
    {
        if ($total < 1) {
            return null;
        }

        $target = $q * $total;
        $seen   = 0;
        foreach ($histogram as $label => $count) {
            $seen += $count;
            if ($seen >= $target) {
                return $label;
            }
        }

        return array_key_last($histogram);
    }

    /**
     * All-time totals (exact) and the last 24 hours (suppressed).
     */
    private function usage(): array
    {
        $totals = Stats::totals(self::USAGE_METRICS);
        $hourly = Stats::hourly(self::USAGE_METRICS, 24);

        $recent = [];
        $series = [];
        foreach (self::USAGE_METRICS as $metric) {
            $windowTotal      = array_sum($hourly[$metric]);
            $recent[$metric]  = $this->suppress($windowTotal);

            // The shape of a sparkline is itself a timing signal, so the
            // series is withheld wholesale until the window is busy enough.
            $series[$metric] = $windowTotal >= self::TIMELINE_MIN
                ? $hourly[$metric]
                : null;
        }

        return [
            'all_time'          => $totals,
            'last_24h'          => $recent,
            'hourly_24h'        => $series,
            'hourly_withheld_below' => self::TIMELINE_MIN,
            'hourly_note'       => 'An hourly series is published only once its 24h total '
                . 'reaches ' . self::TIMELINE_MIN . '. Below that the shape of the line '
                . 'would describe individual conversations even with the values hidden.',
            'rendezvous_note'   => 'rendezvous_paired counts claims, not pairings: each side '
                . 'that observes a pairing increments it, so a completed pairing shows as 2.',
        ];
    }

    /**
     * Published so a client can discover them here as well as at /api/v2.
     */
    private function limits(): array
    {
        return [
            'message_max_bytes'          => MessageController::MAX_MESSAGE_BYTES,
            'inbox_max_pending_messages' => MessageController::MAX_PENDING_MESSAGES,
            'inbox_max_pending_bytes'    => MessageController::MAX_PENDING_BYTES,
            'long_poll_max_seconds'      => MessageController::MAX_WAIT,
            'inbox_page_max'             => MessageController::MAX_LIMIT,
            // THE BINDING LIMIT FOR ANY ACTUAL SENDER, and therefore the one
            // a client sizing its behaviour needs most. These were missing
            // for a release: this endpoint published 2000 / 64 MiB while a
            // sender was refused at 200 / 16 MiB, so the figures a client was
            // told to plan against were 10x and 4x higher than anything it
            // could reach. `FiltersCheck`-style enforcement now lives in
            // `tests/v2_limits_published_test.php`.
            'inbox_max_pending_per_sender'       => MessageController::MAX_PENDING_PER_SENDER,
            'inbox_max_pending_bytes_per_sender' => MessageController::MAX_PENDING_BYTES_PER_SENDER,
        ];
    }

    /**
     * A count, or "<N" when publishing the exact figure would describe too few
     * events to be an aggregate.
     *
     * @return int|string
     */
    private function suppress(int $count)
    {
        return $count < self::SUPPRESS_BELOW ? '<' . self::SUPPRESS_BELOW : $count;
    }
}
