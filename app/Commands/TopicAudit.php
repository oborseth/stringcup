<?php

namespace App\Commands;

use App\Models\ApiTokenModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

/**
 * Report the grandfathered (human-named) topic set, and its reclaimability.
 *
 * WHY THIS IS A COMMAND AND NOT A PARAGRAPH. Topic ids are assigned now, and
 * topics that predate that keep a human-chosen `name` and stay addressable by
 * it. The claim "that set is frozen and shrinking" is only worth anything if
 * it is a NUMBER, so this prints it:
 *
 *     SELECT COUNT(*) FROM topics WHERE name IS NOT NULL
 *
 * An auditor's point, and the reason `name` is left NULL rather than holding
 * the assigned id: that makes the exposed set self-describing and
 * monotonically non-increasing, which turns a justification into an invariant.
 *
 * IT PRINTS THE RULE AND THE NAMES, NOT JUST A COUNT. The first version
 * reported "83 recognisable test artefacts, 2 meaningful" from a regex on the
 * name -- which is a NAME-MATCHING CLASSIFIER deciding which rows are
 * sensitive, structurally identical to the `_looks_owned` mistake that
 * silenced a user-owned 0755 directory for being called "tmp". The same
 * failure is available here and errs the same way, towards reassurance: a
 * production channel called `test-integration-eu` would be counted as
 * disposable. So the pattern is printed, every matched name is listed, and the
 * figure is stated as a BOUND -- a lower bound on what is disposable, an upper
 * bound on what is safe to ignore -- so a human can disagree with the
 * classifier instead of inheriting it.
 */
class TopicAudit extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'topics:audit';
    protected $description = 'Report human-named topics, the rule used to classify them, and what is reclaimable.';

    /**
     * Names this project's own test suites generate.
     *
     * A HEURISTIC, and it is printed so it can be argued with. It is not used
     * to decide anything destructive.
     */
    private const FIXTURE_PATTERN = '/^(live-mcp-|p11-topic-|v11-topic-|wire-|tid-|test-|fixture-)/';

    /**
     * The prediction, in code rather than in a document somebody must recall.
     *
     * Tokens die at INACTIVITY_TTL_DAYS (30) + 7 days' grace, and every
     * grandfathered topic had a member last active around 2026-09-15.
     */
    private const PREDICTION_DEADLINE = '2026-10-22';

    /** The two whose members are genuinely active. */
    private const PREDICTION_FLOOR = 2;

    public function run(array $params)
    {
        $db = Database::connect();

        $total  = (int) $db->query('SELECT COUNT(*) AS c FROM topics')->getRow()->c;
        $named  = (int) $db->query('SELECT COUNT(*) AS c FROM topics WHERE name IS NOT NULL')->getRow()->c;

        CLI::write('THE INVARIANT', 'yellow');
        CLI::write("  topics total                         : {$total}");
        CLI::write("  addressable by a human name          : {$named}", $named > 0 ? 'yellow' : 'green');
        CLI::write('  (that second number can only go down: POST /topics refuses a');
        CLI::write('   caller-supplied name, so no new one can ever be created.)');
        CLI::newLine();

        $rows  = $db->query('SELECT name FROM topics WHERE name IS NOT NULL ORDER BY name')
            ->getResultArray();

        $fixture = [];
        $other   = [];
        foreach ($rows as $r) {
            $n = (string) $r['name'];
            if (preg_match(self::FIXTURE_PATTERN, $n)) {
                $fixture[] = $n;
            } else {
                $other[] = $n;
            }
        }

        CLI::write('THE CLASSIFIER, SO YOU CAN DISAGREE WITH IT', 'yellow');
        CLI::write('  rule: ' . self::FIXTURE_PATTERN);
        CLI::write('  This matches on the NAME. A production channel a human called');
        CLI::write('  "test-..." would be counted below as disposable and would not be.');
        CLI::write('  Read the list; do not read the count alone.');
        CLI::newLine();

        CLI::write('  matches the fixture rule (' . count($fixture) . '):');
        foreach ($fixture as $n) {
            CLI::write('    ' . $n);
        }
        CLI::newLine();
        CLI::write('  does NOT match, so certainly meaningful (' . count($other) . '):',
            $other === [] ? 'green' : 'yellow');
        foreach ($other as $n) {
            CLI::write('    ' . $n, 'yellow');
        }
        CLI::newLine();

        // Reclaimability, because "it shrinks" also has to be checkable.
        //
        // RetentionSweeper reclaims a topic no member can authenticate into.
        // Its two older topic rules keyed on the identity ROW being gone, and
        // identities are never deleted, so they could not fire at all -- which
        // is why these topics accumulated. This shows whether the working rule
        // would reach them yet.
        $ttl    = ApiTokenModel::INACTIVITY_TTL_DAYS + 7;
        $cutoff = "DATE_SUB(NOW(), INTERVAL {$ttl} DAY)";

        $reclaimable = (int) $db->query(
            "SELECT COUNT(*) AS c FROM topics t
                  WHERE t.name IS NOT NULL
                    AND NOT EXISTS (
                        SELECT 1 FROM topic_members tm
                          JOIN identities i ON i.id = tm.identity_id
                         WHERE tm.topic_id = t.id
                           AND EXISTS (
                               SELECT 1 FROM api_tokens k
                                WHERE k.identity_id = i.id
                                  AND k.is_active = 1
                                  AND COALESCE(k.last_used_at, k.created_at) >= {$cutoff}))"
        )->getRow()->c;

        CLI::write('RECLAIMABILITY', 'yellow');
        CLI::write("  reclaimable by db:retain right now    : {$reclaimable}");
        CLI::write("  still held by a member with a live token: " . ($named - $reclaimable));
        CLI::write("  (a member is 'live' for {$ttl} days after its last authenticated");
        CLI::write('   request: INACTIVITY_TTL_DAYS plus a 7-day grace.)');

        if ($reclaimable === 0 && $named > 0) {
            CLI::newLine();
            CLI::write('  So nothing is reclaimable yet, and that is expected rather than a', 'green');
            CLI::write('  fault: these topics have members whose tokens are still valid.', 'green');
            CLI::write('  They age out on their own. Deleting them sooner is an operator', 'green');
            CLI::write('  decision, not housekeeping -- db:prune is the tool, and it must', 'green');
            CLI::write('  never be scheduled.', 'green');
        }

        return $this->checkPrediction($named, $reclaimable);
    }

    /**
     * Check the written-down prediction, and FAIL once it is overdue.
     *
     * WHY THIS IS CODE AND NOT A NOTE. An auditor's advice was to record a
     * prediction in advance -- "the count should fall to 2 by roughly
     * 2026-10-22" -- because a number disagreeing with an expectation has been
     * this project's most productive detector. It was duly written into
     * CLAUDE.md, where **it depended on a human remembering to look**, in a
     * project whose entire discipline is "enforced, not remembered". That was
     * the wrong place for it and this is the right one: the check now runs
     * whenever anyone runs the command, and turns into a non-zero exit when
     * the deadline passes without the prediction holding.
     *
     * The prediction exists because `topics no member can reach any more`
     * replaced two rules that were structurally dead for the project's entire
     * life, so **nothing has ever exercised it against real data.** The
     * grandfathered topics aging out is the only natural test it will get.
     *
     * It fails CLOSED but NARROWLY: only when there are grandfathered topics
     * AND the deadline has passed AND none is reclaimable. A self-hoster with
     * no such topics sees nothing, and neither does anyone before the date --
     * so this cannot fire spuriously in somebody else's checkout, which is
     * what would make it a test people learn to ignore.
     */
    private function checkPrediction(int $named, int $reclaimable): int
    {
        if ($named === 0) {
            return EXIT_SUCCESS;
        }

        $deadline = new \DateTimeImmutable(self::PREDICTION_DEADLINE);
        $now      = new \DateTimeImmutable('now');
        $days     = (int) $now->diff($deadline)->format('%r%a');

        CLI::newLine();
        CLI::write('THE WRITTEN-DOWN PREDICTION', 'yellow');
        CLI::write('  due                                  : ' . self::PREDICTION_DEADLINE
            . ' (' . ($days >= 0 ? "in {$days} days" : abs($days) . ' days ago') . ')');
        CLI::write('  expected by then                     : human-named topics == '
            . self::PREDICTION_FLOOR . ', reclaimable > 0 before then');

        if ($now < $deadline) {
            CLI::write('  status                               : not due yet');

            return EXIT_SUCCESS;
        }

        if ($named <= self::PREDICTION_FLOOR) {
            CLI::write('  status                               : HELD -- the reclaim rule '
                . 'fires against production data', 'green');

            return EXIT_SUCCESS;
        }

        CLI::newLine();
        CLI::error('PREDICTION FAILED, and this is the defect it was written to catch.');
        CLI::error(sprintf(
            '  %d topics are still addressable by a human name, expected %d, and %d '
                . 'are reclaimable.',
            $named,
            self::PREDICTION_FLOOR,
            $reclaimable
        ));
        CLI::error('  `topics no member can reach any more` replaced two rules that could');
        CLI::error('  never fire. If the count has not fallen, it does not fire either --');
        CLI::error('  check RetentionSweeper against real data rather than a dry run, and');
        CLI::error('  do NOT hand-clean the rows, which would hide this permanently.');

        return EXIT_ERROR;
    }
}
