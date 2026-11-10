<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\EstimateCalorieRequest;
use App\Services\Contracts\CalorieEstimationServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CalorieEstimationController extends Controller
{
    public function __construct(
        private readonly CalorieEstimationServiceInterface $calorieEstimationService
    ) {}

    public function estimate(EstimateCalorieRequest $request): JsonResponse
    {
        $result = $this->calorieEstimationService->estimate($request->file('image'));

        return response()->json([
            'success' => true,
            'message' => 'Estimasi kalori makanan berhasil dihitung (data simulasi).',
            'data' => $result,
        ]);
    }

    /**
     * Menampilkan file foto makanan dari storage privat secara aman via signed URL.
     */
    public function showImage(Request $request, string $filename): mixed
    {
        // Cegah directory traversal
        $sanitizedFilename = basename($filename);

        if (! Storage::disk('local')->exists('calorie-estimates/'.$sanitizedFilename)) {
            abort(404, 'Foto makanan tidak ditemukan atau telah kedaluwarsa.');
        }

        return Storage::disk('local')->response('calorie-estimates/'.$sanitizedFilename, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}
