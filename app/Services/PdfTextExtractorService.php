<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Log;

class PdfTextExtractorService
{
    /**
     * Extract text content from PDF file
     *
     * @param string $filePath
     * @return string
     * @throws Exception
     */
    public function extractText(string $filePath): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("PDF file not found: {$filePath}");
        }

        try {
            // Try using pdftotext (poppler-utils) if available
            if ($this->isPdftotextAvailable()) {
                return $this->extractWithPdftotext($filePath);
            }

            // Fallback to PHP-based extraction
            return $this->extractWithPhpPdf($filePath);

        } catch (Exception $e) {
            Log::error('PDF text extraction failed', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
            throw new Exception("Failed to extract text from PDF: " . $e->getMessage());
        }
    }

    /**
     * Check if pdftotext command is available
     *
     * @return bool
     */
    public function isPdftotextAvailable(): bool
    {
        $output = [];
        $returnCode = null;
        exec('which pdftotext 2>/dev/null', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Extract text using pdftotext command (most reliable)
     *
     * @param string $filePath
     * @return string
     * @throws Exception
     */
    private function extractWithPdftotext(string $filePath): string
    {
        $outputFile = tempnam(sys_get_temp_dir(), 'pdf_extract_');
        
        try {
            $command = sprintf(
                'pdftotext %s %s 2>&1',
                escapeshellarg($filePath),
                escapeshellarg($outputFile)
            );

            $output = [];
            $returnCode = null;
            exec($command, $output, $returnCode);

            if ($returnCode !== 0) {
                throw new Exception('pdftotext command failed: ' . implode(' ', $output));
            }

            if (!file_exists($outputFile)) {
                throw new Exception('Output file was not created');
            }

            $text = file_get_contents($outputFile);
            
            if ($text === false) {
                throw new Exception('Failed to read extracted text');
            }

            return trim($text);

        } finally {
            if (file_exists($outputFile)) {
                unlink($outputFile);
            }
        }
    }

    /**
     * Extract text using PHP-based PDF parser (fallback)
     *
     * @param string $filePath
     * @return string
     * @throws Exception
     */
    private function extractWithPhpPdf(string $filePath): string
    {
        // This is a basic implementation - for production use a proper PDF library
        // like Smalot/PdfParser or similar
        
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new Exception('Failed to read PDF file');
        }

        // Basic text extraction - this is simplified and may not work for all PDFs
        // For production, install: composer require smalot/pdfparser
        $text = '';
        
        // Look for text objects in PDF
        if (preg_match_all('/BT\s*(.*?)\s*ET/s', $content, $matches)) {
            foreach ($matches[1] as $textBlock) {
                // Extract text from PDF text objects
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $textBlock, $textMatches)) {
                    foreach ($textMatches[1] as $textMatch) {
                        $text .= $textMatch . ' ';
                    }
                }
                
                // Alternative format: [(...)] TJ
                if (preg_match_all('/\[\s*\((.*?)\)\s*\]\s*TJ/s', $textBlock, $textMatches)) {
                    foreach ($textMatches[1] as $textMatch) {
                        $text .= $textMatch . ' ';
                    }
                }
            }
        }

        if (empty(trim($text))) {
            throw new Exception('No text could be extracted from PDF - file may be image-based or encrypted');
        }

        return $this->cleanExtractedText($text);
    }

    /**
     * Clean and normalize extracted text
     *
     * @param string $text
     * @return string
     */
    private function cleanExtractedText(string $text): string
    {
        // Remove extra whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        
        // Remove special characters that might interfere with analysis
        $text = preg_replace('/[^\x20-\x7E\n\r\t]/', ' ', $text);
        
        // Normalize line breaks
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        
        // Remove excessive newlines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        
        return trim($text);
    }

    /**
     * Get PDF metadata and basic info
     *
     * @param string $filePath
     * @return array
     */
    public function getPdfInfo(string $filePath): array
    {
        $info = [
            'file_size' => filesize($filePath),
            'mime_type' => mime_content_type($filePath),
            'pages' => null,
            'text_length' => 0,
            'extraction_method' => null
        ];

        try {
            $text = $this->extractText($filePath);
            $info['text_length'] = strlen($text);
            $info['extraction_method'] = $this->isPdftotextAvailable() ? 'pdftotext' : 'php_fallback';
            
            // Try to estimate page count (rough estimation)
            $info['pages'] = max(1, substr_count($text, "\f") + 1);
            
        } catch (Exception $e) {
            Log::warning('Could not extract PDF info', [
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);
        }

        return $info;
    }
}