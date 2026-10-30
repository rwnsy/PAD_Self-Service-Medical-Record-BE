<?php

namespace App\Models;

use Database\Factories\PersonalMedicalRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'visit_date',
    'facility_name',
    'complaint',
    'medications',
    'source',
])]
class PersonalMedicalRecord extends Model
{
    /** @use HasFactory<PersonalMedicalRecordFactory> */
    use HasFactory;

    protected $primaryKey = 'personal_medical_record_id';

    /** @var list<string> */
    protected $appends = ['source_label'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visit_date' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /**
     * Label sumber data yang ramah pengguna untuk ditampilkan pada kartu riwayat di aplikasi Android.
     */
    protected function sourceLabel(): Attribute
    {
        return Attribute::get(fn (): string => 'Catatan Mandiri');
    }
}

