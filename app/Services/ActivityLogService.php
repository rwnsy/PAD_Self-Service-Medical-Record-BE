<?php

namespace App\Services;

use App\Models\ActivityLog;
use Throwable;

class ActivityLogService
{
    /**
     * Log a user action silently — never throws, never fails the caller.
     */
    public function log(?int $userId, string $action, ?string $description = null): void
    {
        try {
            ActivityLog::create([
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
            ]);
        } catch (Throwable) {
            // Activity logging must never break the parent request.
        }
    }
}
