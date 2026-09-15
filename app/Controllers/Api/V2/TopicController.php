<?php

namespace App\Controllers\Api\V2;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;
use App\Models\IdentityModel;
use App\Models\TopicModel;
use App\Models\TopicMemberModel;
use App\Libraries\KeyFingerprint;

/**
 * V2 TopicController — named membership directories for multi-agent fan-out.
 *
 * A topic answers "who is in this group, and what are their public keys?" in
 * one call. It does not carry messages. Sending to a topic means encrypting
 * separately for each member (v2 derives every message key from a fresh
 * ephemeral ECDH against one recipient's static key, so one ciphertext cannot
 * serve several readers) and posting the results to
 * POST /api/v2/messages/batch.
 *
 * Keeping fan-out client-side is what preserves the end-to-end property: the
 * server learns the social graph, never the plaintext.
 *
 * Membership is visible only to members, so a topic is not a way to enumerate
 * identities.
 */
class TopicController extends BaseController
{
    use ResponseTrait;

    /** Members permitted in one topic. */
    public const MAX_MEMBERS = 200;

    /** Identities addable in a single request. */
    public const MAX_ADD_BATCH = 100;

    private const NAME_PATTERN = '/^[a-zA-Z0-9_-]{1,64}$/';

    protected function currentIdentity(): ?array
    {
        if (isset($this->request->identity) && is_array($this->request->identity)) {
            return $this->request->identity;
        }

        return null;
    }

    /**
     * Shape a member row for the wire, including the key needed to encrypt.
     */
    private function presentMember(array $row): array
    {
        return array_merge([
            'id'                  => $row['external_id'],
            'identity_public_key' => base64_encode($row['identity_pubkey']),
            'algo'                => $row['algo'],
            'key_updated_at'      => $row['key_updated_at'] ?? null,
            'added_at'            => $row['added_at'] ?? null,
        ], KeyFingerprint::describe($row['identity_pubkey']));
    }

    /**
     * Resolve a topic the caller may see, or return an error response.
     *
     * Non-members get 404 rather than 403: confirming a topic exists would
     * leak the namespace to anyone probing it.
     *
     * @return array{0: array|null, 1: mixed} topic, errorResponse
     */
    private function requireMembership(string $name, array $identity): array
    {
        $topic = (new TopicModel())->findByName($name);

        if (!$topic) {
            return [null, $this->failNotFound('Topic not found')];
        }

        if (!(new TopicMemberModel())->isMember((int) $topic['id'], (int) $identity['id'])) {
            return [null, $this->failNotFound('Topic not found')];
        }

        return [$topic, null];
    }

    /**
     * POST /api/v2/topics
     *
     * Creates a topic owned by the caller, who is always its first member.
     * Optionally seeds `members` with external_ids in the same call.
     */
    public function create()
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $req = $this->request->getJSON(true);
            if (!is_array($req) || empty($req['name'])) {
                return $this->failValidationErrors('name is required');
            }

            $name = $req['name'];
            if (!preg_match(self::NAME_PATTERN, $name)) {
                return $this->failValidationErrors('name must be 1-64 characters: letters, digits, hyphens, underscores');
            }

            $topicModel = new TopicModel();
            if ($topicModel->findByName($name)) {
                // Names are a global namespace, like external_id.
                return $this->fail('A topic with this name already exists', 409);
            }

            $seed = [];
            if (array_key_exists('members', $req)) {
                if (!is_array($req['members'])) {
                    return $this->failValidationErrors('members must be an array of identity ids');
                }
                $seed = $req['members'];

                // The same two ceilings addMembersEndpoint() enforces. This
                // path had neither, so the seed array went straight to
                // addMembers(), which does one SELECT plus one INSERT per
                // entry: a single request with 50,000 ids bought a
                // 50,000-member topic and 100,000 queries for one of the
                // 60/hour topics_post calls. Checked BEFORE the topic row is
                // inserted, so a refused create leaves nothing behind.
                // Found by an external code audit.
                if (count($seed) > self::MAX_ADD_BATCH) {
                    return $this->failValidationErrors(
                        'members may contain at most ' . self::MAX_ADD_BATCH . ' identities'
                    );
                }

                // +1 for the owner, who is added unconditionally below.
                if (count($seed) + 1 > self::MAX_MEMBERS) {
                    return $this->failValidationErrors(
                        'A topic may hold at most ' . self::MAX_MEMBERS . ' members'
                    );
                }
            }

            $now     = date('Y-m-d H:i:s');
            $topicId = $topicModel->insert([
                'name'              => $name,
                'owner_identity_id' => (int) $identity['id'],
                'created_at'        => $now,
            ], true);

            if (!$topicId) {
                return $this->failServerError('Could not create the topic');
            }

            $memberModel = new TopicMemberModel();
            $memberModel->insert([
                'topic_id'    => (int) $topicId,
                'identity_id' => (int) $identity['id'],
                'added_at'    => $now,
            ]);

            $added   = [$identity['external_id']];
            $unknown = [];

            if ($seed !== []) {
                [$added2, $unknown] = $this->addMembers((int) $topicId, $seed, $identity, $now);
                $added = array_values(array_unique(array_merge($added, $added2)));
            }

            $this->logWithContext('info', 'Topic created', [
                'topic'   => $name,
                'owner'   => $identity['external_id'],
                'members' => count($added),
            ]);

            return $this->respondCreated([
                'name'         => $name,
                'owner'        => $identity['external_id'],
                'members'      => $added,
                'member_count' => count($added),
                'unknown'      => $unknown,
                'created_at'   => $now,
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Topic creation failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while creating the topic.');
        }
    }

    /**
     * GET /api/v2/topics
     *
     * Topics the caller belongs to. Membership lists are not included; fetch a
     * topic by name for those.
     */
    public function index()
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            $identityModel = new IdentityModel();
            $memberModel   = new TopicMemberModel();

            $out = [];
            foreach ($memberModel->topicsFor((int) $identity['id']) as $topic) {
                $owner = $identityModel->find($topic['owner_identity_id']);

                $out[] = [
                    'name'         => $topic['name'],
                    'owner'        => $owner['external_id'] ?? null,
                    'is_owner'     => (int) $topic['owner_identity_id'] === (int) $identity['id'],
                    'member_count' => $memberModel->where('topic_id', $topic['id'])->countAllResults(),
                    'created_at'   => $topic['created_at'],
                ];
            }

            return $this->respond(['topics' => $out, 'count' => count($out)]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Topic list failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while listing topics.');
        }
    }

    /**
     * GET /api/v2/topics/{name}
     *
     * The membership roster with each member's public key and fingerprint —
     * everything needed to encrypt one message per member in a single call.
     * Members only.
     */
    public function show(string $name)
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            if (!preg_match(self::NAME_PATTERN, $name)) {
                return $this->failValidationErrors('Invalid topic name');
            }

            [$topic, $error] = $this->requireMembership($name, $identity);
            if ($error) {
                return $error;
            }

            $memberModel = new TopicMemberModel();
            $members     = array_map(
                [$this, 'presentMember'],
                $memberModel->membersWithKeys((int) $topic['id'])
            );

            $owner = (new IdentityModel())->find($topic['owner_identity_id']);

            return $this->respond([
                'name'         => $topic['name'],
                'owner'        => $owner['external_id'] ?? null,
                'is_owner'     => (int) $topic['owner_identity_id'] === (int) $identity['id'],
                'members'      => $members,
                'member_count' => count($members),
                'created_at'   => $topic['created_at'],
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Topic lookup failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while reading the topic.');
        }
    }

    /**
     * POST /api/v2/topics/{name}/members   { "ids": ["agent-a", "agent-b"] }
     *
     * Owner only. Already-present members and unknown ids are reported rather
     * than treated as errors, so the call is safe to repeat.
     */
    public function addMembersEndpoint(string $name)
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            if (!preg_match(self::NAME_PATTERN, $name)) {
                return $this->failValidationErrors('Invalid topic name');
            }

            // Membership first, ownership second. Branching straight to 403
            // on ownership answered "that topic exists, you are not its
            // owner" to a complete stranger, which is the same existence
            // oracle requireMembership() exists to avoid. A non-member now
            // gets the same 404 it gets everywhere else; a member who simply
            // is not the owner still gets 403, because it already knows the
            // topic exists. Found by an external code audit.
            [$topic, $error] = $this->requireMembership($name, $identity);
            if ($error) {
                return $error;
            }

            if ((int) $topic['owner_identity_id'] !== (int) $identity['id']) {
                return $this->failForbidden('Only the topic owner may change membership');
            }

            $req = $this->request->getJSON(true);
            if (!is_array($req) || !array_key_exists('ids', $req) || !is_array($req['ids']) || $req['ids'] === []) {
                return $this->failValidationErrors('ids must be a non-empty array of identity ids');
            }

            if (count($req['ids']) > self::MAX_ADD_BATCH) {
                return $this->failValidationErrors('ids may contain at most ' . self::MAX_ADD_BATCH . ' identities');
            }

            $memberModel = new TopicMemberModel();
            $existing    = $memberModel->where('topic_id', $topic['id'])->countAllResults();

            if ($existing + count($req['ids']) > self::MAX_MEMBERS) {
                return $this->failValidationErrors(
                    'A topic may hold at most ' . self::MAX_MEMBERS . ' members'
                );
            }

            [$added, $unknown] = $this->addMembers(
                (int) $topic['id'],
                $req['ids'],
                $identity,
                date('Y-m-d H:i:s')
            );

            $this->logWithContext('info', 'Topic members added', [
                'topic' => $name,
                'added' => count($added),
            ]);

            return $this->respond([
                'name'         => $topic['name'],
                'added'        => $added,
                'unknown'      => $unknown,
                'member_count' => $memberModel->where('topic_id', $topic['id'])->countAllResults(),
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Topic member add failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while adding members.');
        }
    }

    /**
     * DELETE /api/v2/topics/{name}/members/{identityId}
     *
     * The owner may remove anyone; a member may remove themselves. The owner
     * cannot leave their own topic — delete it instead, so a topic is never
     * left ownerless.
     */
    public function removeMember(string $name, string $externalId)
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            if (!preg_match(self::NAME_PATTERN, $name) || !preg_match(self::NAME_PATTERN, $externalId)) {
                return $this->failValidationErrors('Invalid topic or identity name');
            }

            // Same ordering fix as addMembersEndpoint: a non-member must not
            // be able to tell "exists, forbidden" from "does not exist".
            [$topic, $error] = $this->requireMembership($name, $identity);
            if ($error) {
                return $error;
            }

            $isOwner = (int) $topic['owner_identity_id'] === (int) $identity['id'];
            $isSelf  = $externalId === $identity['external_id'];

            if (!$isOwner && !$isSelf) {
                return $this->failForbidden('Only the owner may remove other members');
            }

            $target = (new IdentityModel())->where('external_id', $externalId)->first();
            if (!$target) {
                return $this->failNotFound('Identity not found');
            }

            if ((int) $target['id'] === (int) $topic['owner_identity_id']) {
                return $this->fail('The owner cannot be removed; delete the topic instead', 409);
            }

            $memberModel = new TopicMemberModel();
            if (!$memberModel->isMember((int) $topic['id'], (int) $target['id'])) {
                return $this->failNotFound('That identity is not a member of this topic');
            }

            $memberModel->where('topic_id', $topic['id'])
                ->where('identity_id', $target['id'])
                ->delete();

            $this->logWithContext('info', 'Topic member removed', [
                'topic'   => $name,
                'removed' => $externalId,
            ]);

            return $this->respond([
                'name'         => $topic['name'],
                'removed'      => $externalId,
                'member_count' => $memberModel->where('topic_id', $topic['id'])->countAllResults(),
            ]);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Topic member removal failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while removing the member.');
        }
    }

    /**
     * DELETE /api/v2/topics/{name}
     *
     * Owner only. Removes the topic and its membership rows. Messages already
     * sent are unaffected — they were addressed to individuals, not the topic.
     */
    public function delete(string $name)
    {
        try {
            $identity = $this->currentIdentity();
            if (!$identity) {
                return $this->failUnauthorized('Missing or invalid token');
            }

            if (!preg_match(self::NAME_PATTERN, $name)) {
                return $this->failValidationErrors('Invalid topic name');
            }

            $topicModel = new TopicModel();
            $topic      = $topicModel->findByName($name);

            if (!$topic) {
                return $this->failNotFound('Topic not found');
            }

            if ((int) $topic['owner_identity_id'] !== (int) $identity['id']) {
                return $this->failForbidden('Only the topic owner may delete it');
            }

            (new TopicMemberModel())->where('topic_id', $topic['id'])->delete();
            $topicModel->delete($topic['id']);

            $this->logWithContext('info', 'Topic deleted', ['topic' => $name]);

            return $this->respond(['name' => $name, 'status' => 'deleted']);
        } catch (\Exception $e) {
            $this->logWithContext('error', 'Topic deletion failed: {message}', [
                'message'   => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            return $this->failServerError('An error occurred while deleting the topic.');
        }
    }

    /**
     * Insert membership rows, skipping unknown identities and existing members.
     *
     * @param list<mixed> $externalIds
     * @return array{0: list<string>, 1: list<string>} added, unknown
     */
    private function addMembers(int $topicId, array $externalIds, array $actor, string $now): array
    {
        $identityModel = new IdentityModel();
        $memberModel   = new TopicMemberModel();

        $added   = [];
        $unknown = [];

        foreach (array_unique($externalIds) as $externalId) {
            if (!is_string($externalId) || !preg_match(self::NAME_PATTERN, $externalId)) {
                $unknown[] = (string) $externalId;
                continue;
            }

            $target = $identityModel->where('external_id', $externalId)->first();
            if (!$target) {
                $unknown[] = $externalId;
                continue;
            }

            if ($memberModel->isMember($topicId, (int) $target['id'])) {
                continue; // already present; repeating the call is harmless
            }

            $memberModel->insert([
                'topic_id'    => $topicId,
                'identity_id' => (int) $target['id'],
                'added_at'    => $now,
            ]);

            $added[] = $externalId;
        }

        return [$added, $unknown];
    }
}
