<?php

namespace App\Services\Contracts;

use Illuminate\Http\UploadedFile;

interface CalorieEstimationServiceInterface
{
    /**
     * Estimasi kalori dari gambar makanan yang diunggah pengguna.
     *
     * @param  UploadedFile|string  $image  File gambar atau URL/path gambar
     * @return array<string, mixed>
     */
    public function estimate(UploadedFile|string $image): array;
}
