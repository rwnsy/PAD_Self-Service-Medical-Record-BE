<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminIntegrationLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 20), 100);
        $status = $request->query('status');
        $operation = $request->query('operation');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $logs = IntegrationLog::query()
            ->with('user:user_id,name,email')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($operation, fn ($q) => $q->where('operation', $operation))
            ->when($dateFrom, fn ($q) => $q->where('attempted_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->where('attempted_at', '<=', $dateTo.' 23:59:59'))
            ->orderByDesc('attempted_at')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Integration logs retrieved successfully.',
            'data' => $logs,
        ]);
    }
}
