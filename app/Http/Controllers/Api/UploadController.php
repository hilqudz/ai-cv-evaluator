<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FileUploadService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class UploadController extends Controller
{
    protected FileUploadService $uploadService;
    
    public function __construct(FileUploadService $uploadService)
    {
        $this->uploadService = $uploadService;
    }
    
    /**
     * Handle file upload
     * POST /api/upload
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function upload(Request $request): JsonResponse
    {
        try {
            // Validate request - either CV or Project Report or both
            $request->validate([
                'cv' => 'sometimes|required|file|mimes:pdf|max:10240', // 10MB max
                'project_report' => 'sometimes|required|file|mimes:pdf|max:10240', // 10MB max
            ]);
            
            // Must have at least one file
            if (!$request->hasFile('cv') && !$request->hasFile('project_report')) {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one file (CV or Project Report) must be provided'
                ], 422);
            }
            
            $results = [];
            
            // Upload CV
            if ($request->hasFile('cv')) {
                $cvUpload = $this->uploadService->uploadFile(
                    $request->file('cv'),
                    'cv'
                );
                $results['cv'] = [
                    'id' => $cvUpload->id,
                    'filename' => $cvUpload->original_filename,
                    'size' => $cvUpload->file_size
                ];
            }
            
            // Upload Project Report
            if ($request->hasFile('project_report')) {
                $projectUpload = $this->uploadService->uploadFile(
                    $request->file('project_report'),
                    'project_report'
                );
                $results['project_report'] = [
                    'id' => $projectUpload->id,
                    'filename' => $projectUpload->original_filename,
                    'size' => $projectUpload->file_size
                ];
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Files uploaded successfully',
                'data' => $results
            ], 201);
            
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Upload failed: ' . $e->getMessage()
            ], 500);
        }
    }
}
