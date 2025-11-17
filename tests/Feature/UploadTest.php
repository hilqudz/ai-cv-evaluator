<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Models\Upload;

class UploadTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Test successful CV upload
     */
    public function test_successful_cv_upload(): void
    {
        $file = UploadedFile::fake()->create('cv.pdf', 1000, 'application/pdf');

        $response = $this->post('/api/upload', [
            'cv' => $file,
            'type' => 'cv'
        ]);

        $response->assertStatus(200)
                ->assertJson([
                    'success' => true,
                    'message' => 'Files uploaded successfully'
                ])
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'cv' => [
                            'id',
                            'filename',
                            'original_filename',
                            'size',
                            'mime_type',
                            'storage_path'
                        ]
                    ]
                ]);

        // Assert file was stored
        $uploadData = $response->json('data.cv');
        $this->assertDatabaseHas('uploads', [
            'filename' => $uploadData['filename'],
            'file_type' => 'cv',
            'mime_type' => 'application/pdf'
        ]);
    }

    /**
     * Test successful project report upload
     */
    public function test_successful_project_report_upload(): void
    {
        $file = UploadedFile::fake()->create('project.pdf', 2000, 'application/pdf');

        $response = $this->post('/api/upload', [
            'project_report' => $file,
            'type' => 'project_report'
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'project_report' => [
                            'id',
                            'filename',
                            'original_filename',
                            'size'
                        ]
                    ]
                ]);
    }

    /**
     * Test upload both CV and project report
     */
    public function test_upload_both_files(): void
    {
        $cv = UploadedFile::fake()->create('cv.pdf', 1000, 'application/pdf');
        $project = UploadedFile::fake()->create('project.pdf', 1500, 'application/pdf');

        $response = $this->post('/api/upload', [
            'cv' => $cv,
            'project_report' => $project
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        'cv' => ['id', 'filename'],
                        'project_report' => ['id', 'filename']
                    ]
                ]);

        $this->assertDatabaseCount('uploads', 2);
    }

    /**
     * Test file size validation - too large
     */
    public function test_file_too_large(): void
    {
        // Create 15MB file (exceeds 10MB limit)
        $file = UploadedFile::fake()->create('large_cv.pdf', 15000, 'application/pdf');

        $response = $this->post('/api/upload', [
            'cv' => $file
        ]);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'Validation failed'
                ])
                ->assertJsonValidationErrors(['cv']);
    }

    /**
     * Test invalid file type
     */
    public function test_invalid_file_type(): void
    {
        $file = UploadedFile::fake()->create('document.docx', 1000, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

        $response = $this->post('/api/upload', [
            'cv' => $file
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['cv']);
    }

    /**
     * Test no file provided
     */
    public function test_no_file_provided(): void
    {
        $response = $this->post('/api/upload', []);

        $response->assertStatus(422)
                ->assertJson([
                    'success' => false,
                    'message' => 'At least one file (CV or project report) must be provided'
                ]);
    }

    /**
     * Test empty file
     */
    public function test_empty_file(): void
    {
        $file = UploadedFile::fake()->create('empty.pdf', 0, 'application/pdf');

        $response = $this->post('/api/upload', [
            'cv' => $file
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['cv']);
    }

    /**
     * Test file with special characters in name
     */
    public function test_file_with_special_characters(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'CV - John Doe (2024) [Updated].pdf', 
            file_get_contents(base_path('tests/fixtures/sample.pdf'))
        );

        $response = $this->post('/api/upload', [
            'cv' => $file
        ]);

        $response->assertStatus(200);
        
        $uploadData = $response->json('data.cv');
        // Assert filename is sanitized
        $this->assertStringNotContainsString('[', $uploadData['filename']);
        $this->assertStringNotContainsString(']', $uploadData['filename']);
    }

    /**
     * Test concurrent uploads
     */
    public function test_concurrent_uploads(): void
    {
        $file1 = UploadedFile::fake()->create('cv1.pdf', 1000, 'application/pdf');
        $file2 = UploadedFile::fake()->create('cv2.pdf', 1000, 'application/pdf');

        // Simulate concurrent requests
        $response1 = $this->post('/api/upload', ['cv' => $file1]);
        $response2 = $this->post('/api/upload', ['cv' => $file2]);

        $response1->assertStatus(200);
        $response2->assertStatus(200);

        // Assert both files were stored with different filenames
        $filename1 = $response1->json('data.cv.filename');
        $filename2 = $response2->json('data.cv.filename');
        
        $this->assertNotEquals($filename1, $filename2);
        $this->assertDatabaseCount('uploads', 2);
    }
}