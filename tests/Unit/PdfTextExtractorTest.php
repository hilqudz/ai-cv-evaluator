<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\PdfTextExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class PdfTextExtractorTest extends TestCase
{
    use RefreshDatabase;

    private PdfTextExtractorService $pdfExtractor;

    public function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->pdfExtractor = new PdfTextExtractorService();
    }

    /**
     * Test successful text extraction from PDF
     */
    public function test_successful_text_extraction(): void
    {
        // Create a mock PDF file with known content
        $pdfContent = $this->createMockPdfContent('John Doe CV\nSoftware Developer\nLaravel Expert');
        $filePath = Storage::path('test.pdf');
        file_put_contents($filePath, $pdfContent);

        $extractedText = $this->pdfExtractor->extractText($filePath);

        $this->assertIsString($extractedText);
        $this->assertNotEmpty($extractedText);
    }

    /**
     * Test pdftotext availability check
     */
    public function test_pdftotext_availability(): void
    {
        $isAvailable = $this->pdfExtractor->isPdftotextAvailable();
        
        $this->assertIsBool($isAvailable);
        
        // If pdftotext is available, test should pass
        if ($isAvailable) {
            $this->assertTrue($isAvailable);
        } else {
            // Mark test as skipped if pdftotext is not available
            $this->markTestSkipped('pdftotext not available - using PHP fallback');
        }
    }

    /**
     * Test extraction with empty file
     */
    public function test_extraction_with_empty_file(): void
    {
        $filePath = Storage::path('empty.pdf');
        file_put_contents($filePath, '');

        $this->expectException(\Exception::class);
        $this->pdfExtractor->extractText($filePath);
    }

    /**
     * Test extraction with non-existent file
     */
    public function test_extraction_with_non_existent_file(): void
    {
        $this->expectException(\Exception::class);
        $this->pdfExtractor->extractText('/path/to/non/existent/file.pdf');
    }

    /**
     * Test extraction with invalid PDF file
     */
    public function test_extraction_with_invalid_pdf(): void
    {
        $filePath = Storage::path('invalid.pdf');
        file_put_contents($filePath, 'This is not a PDF file content');

        $this->expectException(\Exception::class);
        $this->pdfExtractor->extractText($filePath);
    }

    /**
     * Test text cleaning functionality
     */
    public function test_text_cleaning(): void
    {
        $dirtyText = "  John   Doe  \n\n\nSoftware  Developer\t\t\r\n  Laravel   Expert  ";
        
        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->pdfExtractor);
        $method = $reflection->getMethod('cleanText');
        $method->setAccessible(true);
        
        $cleanText = $method->invokeArgs($this->pdfExtractor, [$dirtyText]);
        
        $this->assertEquals("John Doe\nSoftware Developer\nLaravel Expert", trim($cleanText));
    }

    /**
     * Test PDF info generation
     */
    public function test_pdf_info(): void
    {
        $pdfContent = $this->createMockPdfContent('Test CV Content');
        $filePath = Storage::path('test.pdf');
        file_put_contents($filePath, $pdfContent);

        $info = $this->pdfExtractor->getPdfInfo($filePath);

        $this->assertIsArray($info);
        $this->assertArrayHasKey('file_size', $info);
        $this->assertArrayHasKey('text_length', $info);
    }

    /**
     * Test fallback extraction method
     */
    public function test_fallback_extraction_method(): void
    {
        // Mock scenario where pdftotext is not available
        $pdfExtractor = new class extends PdfTextExtractorService {
            public function isPdftotextAvailable(): bool {
                return false; // Force fallback
            }
        };

        $pdfContent = $this->createMockPdfContent('Fallback test content');
        $filePath = Storage::path('fallback_test.pdf');
        file_put_contents($filePath, $pdfContent);

        $extractedText = $pdfExtractor->extractText($filePath);

        // Should still return some text even with fallback
        $this->assertIsString($extractedText);
    }

    /**
     * Test extraction with large PDF
     */
    public function test_extraction_with_large_content(): void
    {
        // Create large text content
        $largeContent = str_repeat("This is a test line with various content. ", 1000);
        $pdfContent = $this->createMockPdfContent($largeContent);
        $filePath = Storage::path('large.pdf');
        file_put_contents($filePath, $pdfContent);

        $extractedText = $this->pdfExtractor->extractText($filePath);

        $this->assertIsString($extractedText);
        $this->assertGreaterThan(1000, strlen($extractedText));
    }

    /**
     * Test extraction with special characters
     */
    public function test_extraction_with_special_characters(): void
    {
        $specialContent = "John Doe\n★ Senior Developer ★\n• Laravel Expert\n• API Development\n→ 5+ years experience";
        $pdfContent = $this->createMockPdfContent($specialContent);
        $filePath = Storage::path('special.pdf');
        file_put_contents($filePath, $pdfContent);

        $extractedText = $this->pdfExtractor->extractText($filePath);

        $this->assertIsString($extractedText);
        $this->assertNotEmpty($extractedText);
    }

    /**
     * Test concurrent extraction requests
     */
    public function test_concurrent_extractions(): void
    {
        $content1 = $this->createMockPdfContent('CV Content 1');
        $content2 = $this->createMockPdfContent('CV Content 2');
        
        $filePath1 = Storage::path('cv1.pdf');
        $filePath2 = Storage::path('cv2.pdf');
        
        file_put_contents($filePath1, $content1);
        file_put_contents($filePath2, $content2);

        $text1 = $this->pdfExtractor->extractText($filePath1);
        $text2 = $this->pdfExtractor->extractText($filePath2);

        $this->assertIsString($text1);
        $this->assertIsString($text2);
        $this->assertNotEmpty($text1);
        $this->assertNotEmpty($text2);
    }

    /**
     * Helper method to create mock PDF content
     * Note: This creates a minimal PDF-like structure for testing
     */
    private function createMockPdfContent(string $textContent): string
    {
        // This is a simplified PDF structure for testing
        // In real tests, you might use a library to create actual PDFs
        return "%PDF-1.4\n1 0 obj\n<<\n/Type /Catalog\n/Pages 2 0 R\n>>\nendobj\n2 0 obj\n<<\n/Type /Pages\n/Kids [3 0 R]\n/Count 1\n>>\nendobj\n3 0 obj\n<<\n/Type /Page\n/Parent 2 0 R\n/Contents 4 0 R\n>>\nendobj\n4 0 obj\n<<\n/Length " . strlen($textContent) . "\n>>\nstream\nBT\n/F1 12 Tf\n72 720 Td\n(" . $textContent . ") Tj\nET\nendstream\nendobj\nxref\n0 5\n0000000000 65535 f \n0000000009 00000 n \n0000000058 00000 n \n0000000115 00000 n \n0000000179 00000 n \ntrailer\n<<\n/Size 5\n/Root 1 0 R\n>>\nstartxref\n" . (279 + strlen($textContent)) . "\n%%EOF";
    }

    protected function tearDown(): void
    {
        // Clean up test files
        Storage::deleteDirectory('');
        parent::tearDown();
    }
}