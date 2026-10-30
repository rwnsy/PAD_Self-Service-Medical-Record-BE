<?php

namespace App\Services;

class SelfAssessmentService
{
    /**
     * @param  array{height: int|float|string|null, weight: int|float|string|null, systolic: int|float|string|null, diastolic: int|float|string|null}  $measurements
     * @return array{bmi: float|null, bmi_category_raw: string|null, warning_status: string|null, warning_message: string|null}
     */
    public function calculate(array $measurements): array
    {
        $bmiFullPrecision = null;
        $bmiRounded = null;

        if ($measurements['height'] !== null && $measurements['weight'] !== null) {
            $heightInMeters = (float) $measurements['height'] / 100;
            $bmiFullPrecision = (float) $measurements['weight'] / ($heightInMeters * $heightInMeters);
            $bmiRounded = round($bmiFullPrecision, 2);
        }

        $hasBp = $measurements['systolic'] !== null && $measurements['diastolic'] !== null;

        [$warningStatus, $warningMessage] = $this->assessBloodPressure(
            $hasBp ? (int) $measurements['systolic'] : null,
            $hasBp ? (int) $measurements['diastolic'] : null,
        );

        return [
            'bmi' => $bmiRounded,
            'warning_status' => $warningStatus,
            'warning_message' => $warningMessage,
            // Nilai kategori berbasis full-precision disimpan sementara di sini
            // agar controller dapat meneruskannya ke model apabila diperlukan.
            // Saat ini kategori dihitung ulang oleh accessor bmiCategory pada model
            // dari nilai yang tersimpan — lihat catatan konsistensi di bawah.
            '_bmi_full_precision' => $bmiFullPrecision,
        ];
    }

    /**
     * Hitung kategori BMI dari nilai full-precision sebelum pembulatan.
     *
     * Aturan dewasa:
     * - RENDAH        : BMI < 18.5
     * - IDEAL         : 18.5 <= BMI < 25
     * - BERAT_BERLEBIH: BMI >= 25
     *
     * @internal Digunakan oleh controller untuk memutuskan kategori yang benar
     *           saat menyimpan, sehingga nilai tersimpan di DB selalu konsisten.
     */
    public function bmiCategory(?float $bmiFullPrecision): ?string
    {
        if ($bmiFullPrecision === null) {
            return null;
        }

        return match (true) {
            $bmiFullPrecision < 18.5 => 'RENDAH',
            $bmiFullPrecision < 25.0 => 'IDEAL',
            default => 'BERAT_BERLEBIH',
        };
    }

    /**
     * Skrining tekanan darah saja.
     * Mengembalikan [null, null] bila tidak ada data tekanan darah,
     * karena tidak ada tekanan darah yang diukur bukan berarti "NORMAL".
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function assessBloodPressure(?int $systolic, ?int $diastolic): array
    {
        if ($systolic === null || $diastolic === null) {
            // Data tekanan darah tidak dimasukkan; tidak diberikan status.
            return [null, null];
        }

        if ($systolic > 180 || $diastolic > 120) {
            return [
                'URGENT',
                'This blood-pressure reading is above 180 systolic or 120 diastolic. Repeat it after one minute and seek urgent medical advice, especially if concerning symptoms are present. This self-assessment is not a medical diagnosis.',
            ];
        }

        if ($systolic >= 140 || $diastolic >= 90) {
            return [
                'ATTENTION',
                'This blood-pressure reading is in a screening range that merits clinical follow-up. This self-assessment is not a medical diagnosis.',
            ];
        }

        if ($systolic >= 130 || $diastolic >= 80) {
            return [
                'ATTENTION',
                'This blood-pressure reading is in a screening range that merits monitoring and clinical discussion. This self-assessment is not a medical diagnosis.',
            ];
        }

        return [
            'NORMAL',
            'No blood-pressure screening indicator was triggered by this single measurement. This self-assessment is not a medical diagnosis.',
        ];
    }
}
