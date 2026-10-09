<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogService
{
    /** @param array<string, mixed> $metadata */
    public function record(
        ?User $actor,
        ?int $ownerId,
        string $subjectType,
        int $subjectId,
        string $action,
        array $metadata = [],
    ): void {
        AuditLog::query()->create([
            'owner_id' => $ownerId,
            'actor_id' => $actor?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'action' => $action,
            'metadata' => $metadata ?: null,
        ]);
    }
}
