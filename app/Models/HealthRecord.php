<?php

namespace App\Models;

use Database\Factories\HealthRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'height',
    'weight',
    'systolic',
    'diastolic',
    'bmi',
    'bmi_category',
    'warning_status',
    'warning_message',
    'recorded_at',
])]
class HealthRecord extends Model
{
    /** @use HasFactory<HealthRecordFactory> */
    use HasFactory;

    protected $primaryKey = 'health_record_id';

    /** @var list<string> */
    protected $appends = ['bmi_message', 'recorded_date'];

    protected function casts(): array
    {
        return [
            'height' => 'decimal:2',
            'weight' => 'decimal:2',
            'bmi' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Pesan informatif BMI dalam Bahasa Indonesia, sesuai kategori.
     * Bukan diagnosis medis.
     */
    protected function bmiMessage(): Attribute
    {
        return Attribute::get(function (): ?string {
            return match ($this->bmi_category) {
                'RENDAH' => 'Berat badan berada di bawah rentang ideal. Perhatikan kecukupan gizi dan pola makan yang seimbang.',
                'IDEAL' => 'BMI berada dalam rentang berat badan ideal. Pertahankan pola hidup sehat.',
                'BERAT_BERLEBIH' => 'BMI berada di atas rentang ideal. Perhatikan pola makan dan aktivitas fisik.',
                default => null,
            };
        });
    }

    /**
     * Tanggal pengukuran dalam format YYYY-MM-DD menggunakan zona waktu Asia/Jakarta.
     * Bidang ini memastikan tanggal kalender yang dipilih pengguna tidak bergeser
     * karena konversi UTC, baik saat input maupun respons.
     */
    protected function recordedDate(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->recorded_at === null) {
                return null;
            }

            return $this->recorded_at->setTimezone('Asia/Jakarta')->format('Y-m-d');
        });
    }
}
