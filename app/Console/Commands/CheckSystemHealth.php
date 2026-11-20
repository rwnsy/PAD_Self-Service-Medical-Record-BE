<?php

namespace App\Console\Commands;

use App\Services\AdminNotificationService;
use App\Services\SystemHealthService;
use Illuminate\Console\Command;

class CheckSystemHealth extends Command
{
    protected $signature = 'system:health-check';

    protected $description = 'Check system component health and store metrics. Runs every minute via scheduler.';

    public function handle(SystemHealthService $healthService, AdminNotificationService $notificationService): void
    {
        $dbMetric = $healthService->checkDatabase();
        $notificationService->notifyOnStatusChange('database', $dbMetric->status);

        $appMetric = $healthService->checkApplication();
        $notificationService->notifyOnStatusChange('application', $appMetric->status);

        $openMrsMetric = $healthService->checkOpenMrs();
        $notificationService->notifyOnStatusChange('openmrs', $openMrsMetric->status);

        $this->info("Health check complete — DB: {$dbMetric->status}, App: {$appMetric->status}, OpenMRS: {$openMrsMetric->status}");
    }
}
