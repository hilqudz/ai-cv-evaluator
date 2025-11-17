<?php

namespace App\Services;

use App\Models\Upload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    /**
     * Upload a file and store metadata
     *
     * @param UploadedFile $file
     * @param string $type (cv|project_report)
     * @return Upload
     */
    public function uploadFile(UploadedFile $file, string $type): Upload
    {
        // Validate file type
        $this->validateFile($file);
        
        // Generate unique filename
        $filename = $this->generateUniqueFilename($file);
        
        // Store file
        $path = $file->storeAs("uploads/{$type}", $filename);
        
        // Create upload record
        return Upload::create([
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'file_type' => $type,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
        ]);
    }
    
    /**
     * Validate uploaded file
     *
     * @param UploadedFile $file
     * @throws \Exception
     */
    private function validateFile(UploadedFile $file): void
    {
        // Check file extension
        if (!in_array($file->getClientOriginalExtension(), ['pdf'])) {
            throw new \Exception('Only PDF files are allowed');
        }
        
        // Check file size (max 10MB)
        if ($file->getSize() > 10 * 1024 * 1024) {
            throw new \Exception('File size cannot exceed 10MB');
        }
        
        // Check mime type
        if ($file->getMimeType() !== 'application/pdf') {
            throw new \Exception('Invalid file format. Only PDF files are accepted.');
        }
    }
    
    /**
     * Generate unique filename
     *
     * @param UploadedFile $file
     * @return string
     */
    private function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        $random = Str::random(8);
        
        return "{$timestamp}_{$random}.{$extension}";
    }
    
    /**
     * Get file path for processing
     *
     * @param Upload $upload
     * @return string
     */
    public function getFilePath(Upload $upload): string
    {
        return Storage::path($upload->storage_path);
    }
}