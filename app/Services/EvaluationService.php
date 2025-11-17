<?php

namespace App\Services;

use App\Models\EvaluationJob;
use App\Models\Upload;
use Illuminate\Support\Str;

class EvaluationService
{
    /**
     * Create evaluation job
     *
     * @param string $jobTitle
     * @param int|null $cvUploadId
     * @param int|null $projectReportUploadId
     * @return EvaluationJob
     */
    public function createEvaluationJob(string $jobTitle, ?int $cvUploadId = null, ?int $projectReportUploadId = null): EvaluationJob
    {
        // Validate uploads exist and are correct type (if provided)
        $cvUpload = null;
        $projectUpload = null;
        
        if ($cvUploadId) {
            $cvUpload = Upload::where('id', $cvUploadId)
                ->where('file_type', 'cv')
                ->firstOrFail();
        }
        
        if ($projectReportUploadId) {
            $projectUpload = Upload::where('id', $projectReportUploadId)
                ->where('file_type', 'project_report')
                ->firstOrFail();
        }
        
        return EvaluationJob::create([
            'id' => Str::uuid(),
            'job_title' => $jobTitle,
            'cv_upload_id' => $cvUploadId,
            'project_report_upload_id' => $projectReportUploadId,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    
    /**
     * Get evaluation job by ID
     *
     * @param string $id
     * @return EvaluationJob|null
     */
    public function getEvaluationJob(string $id): ?EvaluationJob
    {
        return EvaluationJob::with(['cvUpload', 'projectReportUpload'])->find($id);
    }
    
    /**
     * Update evaluation job status
     *
     * @param string $id
     * @param string $status
     * @param array $result
     * @return bool
     */
    public function updateEvaluationJob(string $id, string $status, array $result = []): bool
    {
        $job = EvaluationJob::find($id);
        
        if (!$job) {
            return false;
        }
        
        $updateData = [
            'status' => $status,
            'updated_at' => now(),
        ];
        
        if (!empty($result)) {
            $updateData['result'] = json_encode($result);
        }
        
        return $job->update($updateData);
    }
}