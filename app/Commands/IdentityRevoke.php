<?php

namespace App\Commands;

use App\Models\ApiTokenModel;
use App\Models\IdentityModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Cut off an identity's access, or restore it.
 *
 * WHY THIS EXISTS, and it is the answer to a question the operator asked
 * rather than a feature: **this relay cannot see what it carries.** Content is
 * end-to-end encrypted and the store holds sender id, recipient id, a header
 * and ciphertext. There is no inspection, no heuristic and no report that
 * would reveal misuse, and that is the design rather than a gap. So moderation
 * is permanently off the table, and the only real levers are ADMISSION -- who
 * may register -- and REVOCATION, which is this.
 *
 * Before this command the operator had neither. On a report of abuse the only
 * options were editing the database by hand or taking the service down, which
 * is the position of a bystander rather than an operator. Being able to say
 * "we can cut off an identity on report" is what makes running a relay you
 * cannot read a defensible posture instead of a reckless one.
 *
 * Three deliberate properties:
 *
 *  - **It does not delete the identity.** Identities are never deleted here --
 *    each is one public key, they are not what grows, and a peer holding a
 *    pinned fingerprint deserves an honest answer rather than a 404 that looks
 *    like key substitution. Revocation kills the TOKEN; the key stays
 *    resolvable.
 *  - **It is reversible.** `--restore` puts the same token back in service,
 *    because the token hash is untouched. Without an undo, a mistyped id
 *    permanently destroys someone's identity -- the worst failure this project
 *    has -- and a typo should not be able to do that.
 *  - **It acts immediately and reports exactly what changed.** The dry-run
 *    convention belongs to the bulk sweeps (`db:retain`, `db:prune`); this
 *    names a single id you had to type, so a confirmation step would be
 *    friction without a decision behind it. An id that matches nothing is a
 *    LOUD failure, never a silent no-op.
 *
 * Pending mail is left alone on purpose. Revoking stops the identity
 * authenticating; it does not reach into other people's inboxes, and messages
 * already accepted are still theirs. `db:retain` reclaims what a dead token
 * makes unreachable, on its own schedule.
 */
class IdentityRevoke extends BaseCommand
{
    protected $group       = 'Stringcup';
    protected $name        = 'identity:revoke';
    protected $description = 'Cut off an identity\'s API access, or restore it with --restore.';
    protected $usage       = 'identity:revoke <sc-id> [--restore]';
    protected $arguments   = ['sc-id' => 'The assigned external id, e.g. sc-abc123...'];
    protected $options     = ['--restore' => 'Put the identity back in service instead.'];

    public function run(array $params)
    {
        $externalId = $params[0] ?? null;
        $restore    = CLI::getOption('restore') !== null;

        if (!$externalId) {
            CLI::error('Usage: php spark identity:revoke <sc-id> [--restore]');
            return 1;
        }

        $identities = new IdentityModel();
        $identity   = $identities->where('external_id', $externalId)->first();

        // A mistyped id must fail loudly. A silent no-op would report success
        // for an identity that is still live, which is the worst outcome for a
        // command whose whole purpose is responding to a report.
        if (!$identity) {
            CLI::error(sprintf('No identity %s on this relay. Nothing was changed.', $externalId));
            return 1;
        }

        $tokens = new ApiTokenModel();
        $rows   = $tokens->where('identity_id', $identity['id'])->findAll();

        if (!$rows) {
            CLI::write(sprintf('%s exists but holds no token; nothing to change.', $externalId), 'yellow');
            return 0;
        }

        $target  = $restore ? 1 : 0;
        $changed = 0;

        foreach ($rows as $row) {
            if ((int) $row['is_active'] === $target) {
                continue;
            }
            $tokens->update($row['id'], ['is_active' => $target]);
            $changed++;
        }

        $verb = $restore ? 'restored' : 'revoked';

        if ($changed === 0) {
            CLI::write(sprintf('%s was already %s. No change.', $externalId, $verb), 'yellow');
            return 0;
        }

        CLI::write(sprintf('%s %s (%d token%s).', $externalId, $verb, $changed, $changed === 1 ? '' : 's'),
                   $restore ? 'green' : 'red');

        if (!$restore) {
            CLI::write('  The identity row and its public key are untouched, so a peer');
            CLI::write('  holding a pinned fingerprint still gets an honest answer.');
            CLI::write('  Pending mail is untouched. Undo with --restore.');
        }

        return 0;
    }
}
