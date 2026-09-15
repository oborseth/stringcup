<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Give every topic a server-assigned opaque identifier, and free `name`.
 *
 * WHY. `GET /api/v2/topics/{name}` carried a human-meaningful channel name in
 * the request line, and a roster read precedes every broadcast. A channel name
 * describes the conversation's SUBJECT -- one real channel is named after the
 * company that created it, the function of its agents and the date -- so it is
 * not the neutral metadata this project otherwise accepts as visible. The
 * access-log retention was fixed in nginx; this addresses the name being
 * client-chosen at all.
 *
 * THE REASON IS ENTROPY, NOT THE LOG. A client could already pass
 * `secrets.token_hex(16)` as a topic name and keep the human name locally, so
 * the exposure fix needs no server change. What server assignment buys is that
 * **no caller can choose a weak, guessable or squattable identifier** -- the
 * fourth application of a rule this project learned from client-chosen
 * `external_id`, from self-invented rendezvous tokens, and from a
 * human-chosen pairing passphrase that handed the relay an offline verifier.
 * A client-side convention decays into "project-alpha" the first time somebody
 * debugs it. An auditor made this correction: justifying the change by the
 * access log would have been overbuilding, because two lines of client code
 * close that.
 *
 * WHY `name` BECOMES NULLABLE RATHER THAN HOLDING THE ID. The first draft
 * stored the assigned id in `name` too, to keep the unique index and every
 * existing query working. An auditor rejected that: it stores two different
 * KINDS of thing in one column, distinguished only by row age, and a renamed
 * column with two semantics is still a column with two semantics. Leaving it
 * NULL keeps `name` meaning exactly what it always meant -- a human-chosen
 * legacy name -- and makes the grandfathered set SELF-DESCRIBING:
 *
 *     SELECT COUNT(*) FROM topics WHERE name IS NOT NULL
 *
 * is precisely the set still addressable by a human-meaningful name. It is
 * monotonically non-increasing, so "the exposure is frozen, not growing" stops
 * being an argument and becomes a number anyone can check. That is the
 * difference between a justification and an invariant. `php spark topics:audit`
 * prints it.
 *
 * MySQL does not treat NULL as equal to NULL, so the existing UNIQUE index on
 * `name` tolerates any number of NULL rows and needs no change.
 *
 * NOTHING IS WIPED. Every existing topic is backfilled with an id and keeps
 * its name, so it stays addressable by BOTH forms and no client breaks. Only
 * CREATION changes: a caller may no longer supply a name.
 */
class AssignTopicIdentifiers extends Migration
{
    /** `tp-` plus 24 lowercase base32 chars, matching identities' 120 bits. */
    private const PREFIX         = 'tp';
    private const ENTROPY_BYTES  = 15;

    public function up()
    {
        // Helpers are not autoloaded in a migration context, and the first run
        // of this migration died on that AFTER adding the column -- which is
        // why every step below re-checks its own precondition rather than
        // assuming a clean start.
        helper('base32');

        $fields = $this->db->getFieldNames('topics');

        if (!in_array('external_id', $fields, true)) {
            $this->forge->addColumn('topics', [
                'external_id' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'null'       => true,
                    'after'      => 'id',
                ],
            ]);
        }

        // Backfill BEFORE adding the unique index, or duplicate NULLs would be
        // fine but a half-filled column would make the intent unclear to the
        // next reader of the schema.
        $rows = $this->db->table('topics')
            ->select('id')
            ->where('external_id IS NULL')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $this->db->table('topics')
                ->where('id', (int) $row['id'])
                ->update(['external_id' => $this->assignId()]);
        }

        $indexes = array_map(
            static fn ($i) => $i->name,
            $this->db->getIndexData('topics')
        );

        if (!in_array('uk_topics_external_id', $indexes, true)) {
            $this->db->query(
                'ALTER TABLE topics ADD UNIQUE KEY uk_topics_external_id (external_id)'
            );
        }

        // `name` becomes nullable so a topic created after the freeze holds no
        // human-chosen name at all. Kept VARCHAR(64) -- its existing width -- and kept UNIQUE.
        $this->db->query(
            'ALTER TABLE topics MODIFY COLUMN name VARCHAR(64) NULL DEFAULT NULL'
        );
    }

    public function down()
    {
        // Deliberately does NOT drop external_id. Clients address topics by it
        // the moment this runs, so removing it strands every reference a client
        // holds -- the same reasoning that keeps identities permanent. Reverting
        // `name` to NOT NULL would also fail outright against any topic created
        // after the freeze, which is the honest signal that this is one-way.
        $this->db->query(
            'ALTER TABLE topics MODIFY COLUMN name VARCHAR(64) NULL DEFAULT NULL'
        );
    }

    private function assignId(): string
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::PREFIX . '-' . strtolower(
                base32_encode_compat(random_bytes(self::ENTROPY_BYTES))
            );

            $clash = $this->db->table('topics')
                ->where('external_id', $candidate)
                ->countAllResults();

            if ($clash === 0) {
                return $candidate;
            }
        }

        throw new \RuntimeException('could not assign a unique topic id in 5 attempts');
    }
}
