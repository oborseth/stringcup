<?php

namespace App\Models;

use CodeIgniter\Model;

class TopicMemberModel extends Model
{
    protected $table      = 'topic_members';
    protected $primaryKey = 'id';

    protected $allowedFields = [
        'topic_id',
        'identity_id',
        'added_at',
    ];

    protected $returnType    = 'array';
    public    $useTimestamps = false;

    /**
     * Members of a topic, joined to their identities.
     *
     * Returns the public key so a sender can encrypt for every member without
     * a lookup per member.
     *
     * @return list<array<string,mixed>>
     */
    public function membersWithKeys(int $topicId): array
    {
        return $this->select('identities.id, identities.external_id, identities.identity_pubkey, identities.algo, identities.key_updated_at, topic_members.added_at')
            ->join('identities', 'identities.id = topic_members.identity_id')
            ->where('topic_members.topic_id', $topicId)
            ->orderBy('identities.external_id', 'ASC')
            ->findAll();
    }

    public function isMember(int $topicId, int $identityId): bool
    {
        return $this->where('topic_id', $topicId)
            ->where('identity_id', $identityId)
            ->countAllResults() > 0;
    }

    /**
     * Topic rows the identity belongs to.
     *
     * @return list<array<string,mixed>>
     */
    public function topicsFor(int $identityId): array
    {
        return $this->select('topics.id, topics.name, topics.owner_identity_id, topics.created_at')
            ->join('topics', 'topics.id = topic_members.topic_id')
            ->where('topic_members.identity_id', $identityId)
            ->orderBy('topics.name', 'ASC')
            ->findAll();
    }
}
