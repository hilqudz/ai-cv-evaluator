<?php

namespace App\Console\Commands;

use App\Services\GeminiService;
use App\Services\PdfTextExtractorService;
use Illuminate\Console\Command;

class TestGeminiIntegration extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:gemini';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test Gemini API integration';

    /**
     * Execute the console command.
     */
    public function handle(GeminiService $geminiService, PdfTextExtractorService $pdfExtractor): int
    {
        $this->info('🚀 Testing Gemini Integration...');

        try {
            // Test 1: Basic API connectivity
            $this->info("\n1️⃣ Testing Basic API Connectivity...");
            
            $testPrompt = "Hello! Please respond with exactly this text: 'Gemini API is working correctly!'";
            $response = $geminiService->generateContent($testPrompt);
            
            $this->info("✅ Basic API Response: " . substr($response['text'], 0, 100) . "...");

            // Test 2: PDF Text Extraction
            $this->info("\n2️⃣ Testing PDF Text Extractor...");
            
            if ($pdfExtractor->isPdftotextAvailable()) {
                $this->info("✅ pdftotext is available - will use high-quality extraction");
            } else {
                $this->info("⚠️  pdftotext not available - will use PHP fallback (less reliable)");
            }

            // Test 3: CV Analysis
            $this->info("\n3️⃣ Testing CV Analysis...");
            
            $sampleCvText = "
JOHN DOE
Software Developer

EXPERIENCE:
- Senior Software Engineer at Tech Corp (2020-2023)
- Full Stack Developer at StartupXYZ (2018-2020)

SKILLS:
- Programming: Python, JavaScript, PHP, Java
- Frameworks: Laravel, React, Django
- Databases: MySQL, PostgreSQL, MongoDB
- Tools: Git, Docker, AWS

EDUCATION:
- Bachelor of Computer Science (2018)
- Relevant coursework in algorithms and data structures

PROJECTS:
- E-commerce platform with 10k+ users
- Real-time chat application
- API integrations for payment systems
            ";

            $cvAnalysis = $geminiService->analyzeCv(
                $sampleCvText, 
                'Software Developer',
                'Looking for a mid-level software developer with 3+ years experience in web development.'
            );

            $this->info("✅ CV Analysis completed:");
            $this->line("   - Match Rate: " . $cvAnalysis['match_rate']);
            $this->line("   - Technical Skills: " . $cvAnalysis['technical_skills_score'] . "/5");
            $this->line("   - Experience: " . $cvAnalysis['experience_score'] . "/5");

            // Test 4: Project Analysis  
            $this->info("\n4️⃣ Testing Project Analysis...");

            $sampleProjectText = "
PROJECT REPORT: AI-Powered CV Evaluation System

IMPLEMENTATION:
This project implements a RESTful API using Laravel framework for evaluating CVs and project submissions.

KEY FEATURES:
1. File Upload System
   - PDF validation and storage
   - Secure file handling
   - Error handling for invalid files

2. Asynchronous Processing
   - Queue-based job processing
   - Background AI evaluation
   - Status tracking system

3. AI Integration
   - Gemini API integration
   - Structured prompting
   - Score calculation algorithms

4. Database Design
   - Proper relationships between models
   - Migration files for deployment
   - Efficient query optimization

TECHNICAL STACK:
- Backend: Laravel 10
- Database: PostgreSQL
- Queue: Database driver
- AI: Google Gemini API
- Storage: Local filesystem with validation

CODE QUALITY:
- PSR-12 coding standards
- Proper error handling
- Service layer architecture
- Comprehensive logging

CHALLENGES & SOLUTIONS:
1. PDF text extraction - Used poppler-utils for reliability
2. Queue processing - Implemented retry mechanisms
3. AI consistency - Structured prompting with scoring criteria
            ";

            $projectAnalysis = $geminiService->analyzeProject(
                $sampleProjectText,
                'Build a comprehensive CV evaluation system with AI integration'
            );

            $this->info("✅ Project Analysis completed:");
            $this->line("   - Overall Score: " . $projectAnalysis['overall_score'] . "/5");
            $this->line("   - Code Quality: " . $projectAnalysis['scores']['code_quality'] . "/5");
            $this->line("   - Technical Implementation: " . $projectAnalysis['scores']['correctness'] . "/5");

            // Test 5: Final Summary
            $this->info("\n5️⃣ Testing Final Summary Generation...");

            $finalSummary = $geminiService->generateFinalSummary(
                $cvAnalysis,
                $projectAnalysis,
                'Software Developer'
            );

            $this->info("✅ Final Summary generated:");
            $this->line("   " . substr($finalSummary['overall_summary'], 0, 100) . "...");

            $this->info("\n🎉 All Gemini integration tests passed!");
            $this->info("\n📝 Setup Instructions:");
            $this->line("1. Get your Gemini API key from: https://makersuite.google.com/app/apikey");
            $this->line("2. Add it to your .env file: GEMINI_API_KEY=your_actual_api_key");
            $this->line("3. Start the queue worker: php artisan queue:work");
            $this->line("4. Test with real PDFs via the API endpoints");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("\n❌ Gemini Integration Test Failed:");
            $this->error($e->getMessage());
            
            if (str_contains($e->getMessage(), 'API key')) {
                $this->info("\n💡 Solution: Please add your Gemini API key to .env file");
                $this->line("Get your key from: https://makersuite.google.com/app/apikey");
            }

            return self::FAILURE;
        }
    }
}
