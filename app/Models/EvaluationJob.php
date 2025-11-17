<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids; // 1. Import ini

class EvaluationJob extends Model
{
    use HasFactory, HasUuids; // 2. Tambahkan HasUuids di sini

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array
     */
    protected $guarded = []; // 3. Izinkan mass assignment

    // Definisikan relasi (opsional tapi bagus)
    public function cvUpload() {
        return $this->belongsTo(Upload::class, 'cv_upload_id');
    }

    public function projectReportUpload() {
        return $this->belongsTo(Upload::class, 'project_report_upload_id');
    }
}