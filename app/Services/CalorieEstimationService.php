<?php

namespace App\Services;

use App\Services\Contracts\CalorieEstimationServiceInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CalorieEstimationService implements CalorieEstimationServiceInterface
{
    /**
     * Estimasi kalori makanan menggunakan mock/dummy data.
     * Arsitektur ini dirancang sebagai implementasi kontrak CalorieEstimationServiceInterface,
     * sehingga dapat digantikan oleh model AI riil di kemudian hari tanpa mengubah kontrak API dan UI.
     *
     * @return array<string, mixed>
     */
    public function estimate(UploadedFile|string $image): array
    {
        $imageUrl = null;

        if ($image instanceof UploadedFile) {
            $extension = $image->getClientOriginalExtension() ?: 'jpg';
            $filename = (string) Str::uuid().'.'.strtolower($extension);
            // Simpan ke storage privat (local disk) untuk melindungi privasi pengguna
            $image->storeAs('calorie-estimates', $filename, 'local');
            // Hasilkan signed URL sementara yang aman dan berkadaluwarsa
            $imageUrl = URL::temporarySignedRoute(
                'calorie-estimation.image',
                now()->addHours(24),
                ['filename' => $filename]
            );
        } elseif (is_string($image)) {
            $imageUrl = $image;
        }

        // Komponen makanan dummy sesuai spesifikasi desain
        $foods = [
            [
                'name' => 'Ikan Salmon Panggang',
                'calories_min' => 220,
                'calories_max' => 250,
                'calories_formatted' => '220–250 kkal',
            ],
            [
                'name' => 'Nasi',
                'calories_min' => 120,
                'calories_max' => 150,
                'calories_formatted' => '120–150 kkal',
            ],
            [
                'name' => 'Alpukat',
                'calories_min' => 60,
                'calories_max' => 80,
                'calories_formatted' => '60–80 kkal',
            ],
            [
                'name' => 'Jagung Manis',
                'calories_min' => 30,
                'calories_max' => 40,
                'calories_formatted' => '30–40 kkal',
            ],
            [
                'name' => 'Saus/Dressing',
                'calories_min' => 30,
                'calories_max' => 60,
                'calories_formatted' => '30–60 kkal',
            ],
            [
                'name' => 'Tomat Ceri',
                'calories_min' => 10,
                'calories_max' => 15,
                'calories_formatted' => '10–15 kkal',
            ],
        ];

        // Total kalori dihitung dinamis dari rentang penjumlahan komponen makanan
        $totalMin = array_sum(array_column($foods, 'calories_min'));
        $totalMax = array_sum(array_column($foods, 'calories_max'));

        return [
            'image' => $imageUrl,
            'image_url' => $imageUrl,
            'title' => 'Hasil Estimasi',
            'total_calories_min' => $totalMin,
            'total_calories_max' => $totalMax,
            'total_calories_formatted' => "~{$totalMin}–{$totalMax} kkal",
            'is_mock' => true,
            'simulation_label' => 'Data Simulasi (Mock)',
            'disclaimer' => 'Hasil estimasi kalori ini merupakan data simulasi dan belum dianalisis oleh model AI medis sungguhan.',
            'foods' => $foods,
        ];
    }
}
