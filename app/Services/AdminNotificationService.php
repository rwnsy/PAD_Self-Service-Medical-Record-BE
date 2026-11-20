<?php

namespace App\Services;

use App\Models\SystemMetric;
use App\Models\User;
use App\Notifications\AdminNotification;
use Throwable;

class AdminNotificationService
{
    /**
     * Notify all active admins when a component status changes.
     * Deduplication: only fires when the status differs from the previous metric.
     */
    public function notifyOnStatusChange(string $component, string $newStatus): void
    {
        try {
            // Get the second-most-recent metric (the one before the latest) to compare
            $previous = SystemMetric::query()
                ->where('component', $component)
                ->orderByDesc('checked_at')
                ->skip(1) // skip the just-inserted metric
                ->first();

            if ($previous === null || $previous->status === $newStatus) {
                return; // No change — do not notify
            }

            $title = "System Alert: {$component} status changed";
            $body = "Component '{$component}' changed from {$previous->status} to {$newStatus}.";

            $admins = User::query()
                ->whereHas('role', fn ($q) => $q->where('role_name', 'ADMIN'))
                ->where('is_active', true)
                ->get();

            foreach ($admins as $admin) {
                try {
                    $admin->notify(new AdminNotification(
                        title: $title,
                        body: $body,
                        component: $component,
                        previousStatus: $previous->status,
                        currentStatus: $newStatus,
                    ));
                } catch (Throwable) {
                    // Never fail health check due to notification errors
                }
            }
        } catch (Throwable) {
            // Notification must never fail the parent health check
        }
    }
}
