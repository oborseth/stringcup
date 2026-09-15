<?php

namespace App\Commands;

use App\Models\TopicModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Attach a legacy human name to a topic that has none. TEST SUPPORT ONLY.
 *
 * The API can no longer mint a named topic -- that is the entire point of the
 * id freeze -- but `v2_topic_id_test.php` has to exercise the LEGACY
 * addressing form to prove both forms reach identical checks. Without a way to
 * produce one, the invariant an auditor called the real cost of grandfathering
 * would be untestable, and the untested half is the half that rots.
 *
 * Deliberately narrow:
 *
 *  - It REFUSES if the topic already has a name, so it cannot rewrite the
 *    meaning of an existing row.
 *  - It refuses a name that does not look like a test fixture, so it cannot be
 *    used to reopen the namespace with a real-looking channel name. That is a
 *    name-matching check and therefore weak -- see the `_looks_owned` lesson --
 *    but here it fails CLOSED: a name it does not recognise is rejected, not
 *    quietly allowed.
 *  - It is not reachable over HTTP at all, only from a shell on the relay.
 *
 * It does NOT increase the exposed set in any lasting way: the suite deletes
 * the topic it names. `php spark topics:audit` is how you check that held.
 */
class TopicSetName extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'topics:setname';
    protected $description = 'TEST SUPPORT: attach a legacy name to an unnamed topic.';
    protected $usage       = 'topics:setname <tp-id> <name>';

    /** Only fixture-looking names, and the check fails closed. */
    private const FIXTURE_PATTERN = '/^tid-|^test-|^fixture-/';

    public function run(array $params)
    {
        $id   = $params[0] ?? null;
        $name = $params[1] ?? null;

        if (!$id || !$name) {
            CLI::error('usage: topics:setname <tp-id> <name>');

            return EXIT_ERROR;
        }

        if (!preg_match(self::FIXTURE_PATTERN, $name)) {
            CLI::error(
                'Refused: the name must look like a test fixture (tid-, test-, '
                    . 'fixture-). This command exists to exercise legacy addressing, '
                    . 'not to reopen the namespace.'
            );

            return EXIT_ERROR;
        }

        $model = new TopicModel();
        $topic = $model->findByExternalId($id);

        if (!$topic) {
            CLI::error("No topic with id {$id}");

            return EXIT_ERROR;
        }

        if (($topic['name'] ?? null) !== null) {
            CLI::error(
                "Refused: topic {$id} already has the name '{$topic['name']}'. This "
                    . 'command never rewrites an existing name.'
            );

            return EXIT_ERROR;
        }

        $model->update($topic['id'], ['name' => $name]);
        CLI::write("attached '{$name}' to {$id}", 'green');

        return EXIT_SUCCESS;
    }
}
