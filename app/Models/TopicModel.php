<?php

namespace App\Models;

use CodeIgniter\Model;

class TopicModel extends Model
{
    protected $table      = 'topics';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'external_id',
        'name',
        'owner_identity_id',
        'created_at',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;

    /** `tp-` plus 24 lowercase base32 chars: 120 bits, as identities use. */
    public const ASSIGNED_PREFIX        = 'tp';
    public const ASSIGNED_ENTROPY_BYTES = 15;

    /** Matches an assigned id, so resolution can tell the two forms apart. */
    public const ID_PATTERN = '/^tp-[a-z2-7]{24}$/';

    /**
     * A topic by its server-assigned opaque id.
     */
    public function findByExternalId(string $externalId): ?array
    {
        return $this->where('external_id', $externalId)->first();
    }

    /**
     * A topic by its human-chosen legacy name.
     *
     * **`name IS NOT NULL` is not defensive, it is the guard.** Topics created
     * after the freeze carry no name, and MySQL does not treat NULL as equal
     * to NULL — but a caller passing an empty or null-ish segment must not be
     * able to match one of those rows by accident, so the condition is
     * explicit rather than implied by the comparison.
     */
    public function findByName(string $name): ?array
    {
        if ($name === '') {
            return null;
        }

        return $this->where('name', $name)->where('name IS NOT NULL')->first();
    }

    /**
     * Resolve either addressing form to the same topic.
     *
     * **Both forms must reach IDENTICAL checks, and that is an invariant, not
     * a convenience.** Two ways to name one object is the same shape as the
     * rate-limiter's IP-only bucket list versus the auth filter: the day
     * anything keys on one form while resolution accepts both — an allowlist,
     * a rate-limit bucket, an audit filter, a permission check — a caller
     * using the other form walks straight past it. An auditor named this as
     * the real cost of grandfathering, ahead of any exposure argument.
     *
     * Two things keep it honest. Every topic endpoint funnels through
     * `TopicController::requireMembership()`, so there is ONE resolution site
     * rather than six that have to agree; and `v2_topic_id_test.php` addresses
     * the same topic both ways at every endpoint and requires identical
     * responses, because the next endpoint added could bypass the chokepoint.
     *
     * Ids are tried first: they are the form every new client uses, and the
     * pattern is unambiguous so a legacy name cannot collide with one.
     */
    public function findAddressable(string $segment): ?array
    {
        if (preg_match(self::ID_PATTERN, $segment)) {
            return $this->findByExternalId($segment);
        }

        return $this->findByName($segment);
    }

    /**
     * Mint an unused opaque id.
     *
     * Server-assigned for the reason client-chosen `external_id` was removed,
     * rendezvous tokens are refused, and a human-chosen pairing passphrase was
     * rejected: **a value a caller chooses is a value an attacker can
     * predict.** This is the fourth application of that rule and the first
     * time it has been applied before shipping the weak version.
     */
    public function assignExternalId(): ?string
    {
        helper('base32');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::ASSIGNED_PREFIX . '-' . strtolower(
                base32_encode_compat(random_bytes(self::ASSIGNED_ENTROPY_BYTES))
            );

            if (!$this->findByExternalId($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
