<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use App\Models\EvaluationJob;
use App\Models\Upload;
use App\Jobs\ProcessEvaluationJob;
use Illuminate\Http\UploadedFile;

class EvaluationTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    /**
     * Test successful evaluation job submission with upload IDs
     */
    public function test_successful_evaluation_with_upload_ids(): void
    {
        // Create test uploads
        $cvUpload = Upload::factory()->create(['file_type' => 'cv']);
        $projectUpload = Upload::factory()->create(['file_type' => 'project_report']);

        $response = $this->post('/api/evaluate', [
            'job_title' => 'Backend Developer',
            'job_description' => 'We need an experienced Laravel developer',
            'cv_upload_id' => $cvUpload->id,
            'project_report_upload_id' => $projectUpload->id,
            'case_study_brief' => 'Build a REST API for user management'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Evaluation job submitted successfully'
                ])
                ->assertJsonStructure([
                    'success',
                    'message', 
                    'data' => [
                        'job_id',
                        'status',
                        'estimated_completion',
                        'created_at'
                    ]
                ]);

        // Assert job was queued
        Queue::assertPushed(ProcessEvaluationJob::class);

        // Assert database record was created
        $jobData = $response->json('data');
        $this->assertDatabaseHas('evaluation_jobs', [
            'id' => $jobData['job_id'],
            'job_title' => 'Backend Developer',
            'status' => 'queued'
        ]);
    }

    /**
     * Test evaluation with direct file upload
     */
    public function test_evaluation_with_direct_files(): void
    {
        $cvFile = UploadedFile::fake()->create('cv.pdf', 1000, 'application/pdf');
        
        $response = $this->post('/api/evaluate', [
            'job_title' => 'Frontend Developer',
            'cv_file' => $cvFile
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessEvaluationJob::class);
    }

    /**
     * Test evaluation with CV only
     */
    public function test_evaluation_cv_only(): void
    {
        $cvUpload = Upload::factory()->create(['file_type' => 'cv']);

        $response = $this->post('/api/evaluate', [
            'job_title' => 'Data Scientist',
            'cv_upload_id' => $cvUpload->id
        ]);

        $response->assertStatus(200);
        
        $jobId = $response->json('data.job_id');
        $this->assertDatabaseHas('evaluation_jobs', [
            'id' => $jobId,
            'cv_upload_id' => $cvUpload->id,
            'project_report_upload_id' => null
        ]);
    }

    /**
     * Test evaluation with project report only
     */
    public function test_evaluation_project_only(): void
    {
        $projectUpload = Upload::factory()->create(['file_type' => 'project_report']);

        $response = $this->post('/api/evaluate', [
            'job_title' => 'Full Stack Developer',
            'project_report_upload_id' => $projectUpload->id,
            'case_study_brief' => 'Build a social media application'
        ]);

        $response->assertStatus(200);
        
        $jobId = $response->json('data.job_id');
        $this->assertDatabaseHas('evaluation_jobs', [
            'id' => $jobId,
            'cv_upload_id' => null,
            'project_report_upload_id' => $projectUpload->id
        ]);
    }

    /**
     * Test missing job title validation
     */
    public function test_missing_job_title(): void
    {
        $cvUpload = Upload::factory()->create(['file_type' => 'cv']);

        $response = $this->post('/api/evaluate', [
            'cv_upload_id' => $cvUpload->id
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['job_title']);
    }

    /**
     * Test no files provided
     */
    public function test_no_files_provided(): void
    {
        $response = $this->post('/api/evaluate', [
            'job_title' => 'Developer'
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'At least CV or project report must be provided'
                ]);
    }

    /**
     * Test invalid upload ID
     */
    public function test_invalid_upload_id(): void
    {
        $response = $this->post('/api/evaluate', [
            'job_title' => 'Developer',
            'cv_upload_id' => 999999
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['cv_upload_id']);
    }

    /**
     * Test get evaluation result - queued status
     */
    public function test_get_result_queued_status(): void
    {
        $evaluationJob = EvaluationJob::factory()->create([
            'status' => 'queued',
            'result' => null
        ]);

        $response = $this->get("/api/result/{$evaluationJob->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'job_id' => $evaluationJob->id,
                        'status' => 'queued'
                    ]
                ]);
    }

    /**
     * Test get evaluation result - processing status
     */
    public function test_get_result_processing_status(): void
    {
        $evaluationJob = EvaluationJob::factory()->create([
            'status' => 'processing',
            'result' => null
        ]);

        $response = $this->get("/api/result/{$evaluationJob->id}");

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'job_id',
                        'status',
                        'created_at'
                    ]
                ]);
    }

    /**
     * Test get evaluation result - completed status
     */
    public function test_get_result_completed_status(): void
    {
        $result = [
            'cv_analysis' => [
                'match_percentage' => 85,
                'strengths' => ['Strong Laravel experience'],
                'weaknesses' => ['Limited cloud experience']
            ],
            'overall_summary' => [
                'recommendation' => 'Strong Candidate',
                'confidence' => 88
            ]
        ];

        $evaluationJob = EvaluationJob::factory()->create([
            'status' => 'completed',
            'result' => $result
        ]);

        $response = $this->get("/api/result/{$evaluationJob->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'job_id' => $evaluationJob->id,
                        'status' => 'completed',
                        'result' => $result
                    ]
                ])
                ->assertJsonStructure([
                    'data' => [
                        'result' => [
                            'cv_analysis' => [
                                'match_percentage',
                                'strengths',
                                'weaknesses'
                            ],
                            'overall_summary' => [
                                'recommendation',
                                'confidence'
                            ]
                        ]
                    ]
                ]);
    }

    /**
     * Test get evaluation result - failed status
     */
    public function test_get_result_failed_status(): void
    {
        $evaluationJob = EvaluationJob::factory()->create([
            'status' => 'failed',
            'result' => [
                'error' => 'AI service temporarily unavailable'
            ]
        ]);

        $response = $this->get("/api/result/{$evaluationJob->id}");

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'data' => [
                        'status' => 'failed',
                        'result' => [
                            'error' => 'AI service temporarily unavailable'
                        ]
                    ]
                ]);
    }

    /**
     * Test get evaluation result - job not found
     */
    public function test_get_result_job_not_found(): void
    {
        $nonExistentId = '550e8400-e29b-41d4-a716-446655440000';
        
        $response = $this->get("/api/result/{$nonExistentId}");

        $response->assertStatus(404)
                ->assertJson([
                    'success' => false,
                    'message' => 'Job not found'
                ]);
    }

    /**
     * Test get evaluation result - invalid UUID format
     */
    public function test_get_result_invalid_uuid(): void
    {
        $response = $this->get('/api/result/invalid-uuid-format');

        $response->assertStatus(404); // Laravel route constraint will not match
    }

    /**
     * Test evaluation job with very long job description
     */
    public function test_evaluation_with_long_description(): void
    {
        $cvUpload = Upload::factory()->create(['file_type' => 'cv']);
        $longDescription = str_repeat('This is a very long job description. ', 1000);

        $response = $this->post('/api/evaluate', [
            'job_title' => 'Senior Developer',
            'job_description' => $longDescription,
            'cv_upload_id' => $cvUpload->id
        ]);

        $response->assertStatus(200);
        Queue::assertPushed(ProcessEvaluationJob::class);
    }

    /**
     * Test evaluation with special characters in job title
     */
    public function test_evaluation_with_special_characters(): void
    {
        $cvUpload = Upload::factory()->create(['file_type' => 'cv']);

        $response = $this->post('/api/evaluate', [
            'job_title' => 'Senior Developer (Remote) - AI/ML Specialist',
            'cv_upload_id' => $cvUpload->id
        ]);

        $response->assertStatus(200);
        
        $jobId = $response->json('data.job_id');
        $this->assertDatabaseHas('evaluation_jobs', [
            'id' => $jobId,
            'job_title' => 'Senior Developer (Remote) - AI/ML Specialist'
        ]);
    }
}