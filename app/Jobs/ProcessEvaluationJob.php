<?php

namespace App\Jobs;

use App\Models\EvaluationJob;
use App\Services\GeminiService;
use App\Services\PdfTextExtractorService;
use App\Services\ChromaDBService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class ProcessEvaluationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(
        public EvaluationJob $evaluationJob
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        GeminiService $geminiService,
        PdfTextExtractorService $pdfExtractor,
        ChromaDBService $chromaDB
    ): void {
        try {
            Log::info('Starting evaluation processing with RAG enhancement', [
                'job_id' => $this->evaluationJob->id
            ]);

            // Update status to processing
            $this->evaluationJob->update(['status' => 'processing']);

            // Check if ChromaDB is available for RAG enhancement
            $ragAvailable = false;
            try {
                if ($chromaDB->isServerRunning()) {
                    $ragAvailable = true;
                    Log::info('RAG system available for enhanced evaluation', [
                        'job_id' => $this->evaluationJob->id
                    ]);
                } else {
                    Log::warning('ChromaDB not available, using standard evaluation', [
                        'job_id' => $this->evaluationJob->id
                    ]);
                }
            } catch (Exception $e) {
                Log::warning('RAG system check failed, using standard evaluation', [
                    'job_id' => $this->evaluationJob->id,
                    'error' => $e->getMessage()
                ]);
            }

            // Extract text from uploaded files
            $cvText = null;
            $projectText = null;

            // Load relationships if not already loaded
            $this->evaluationJob->load(['cvUpload', 'projectReportUpload']);

            if ($this->evaluationJob->cvUpload && $this->evaluationJob->cvUpload->storage_path) {
                $cvPath = Storage::path($this->evaluationJob->cvUpload->storage_path);
                if (file_exists($cvPath)) {
                    $cvText = $pdfExtractor->extractText($cvPath);
                    Log::info('CV text extracted', [
                        'job_id' => $this->evaluationJob->id,
                        'cv_text_length' => strlen($cvText)
                    ]);
                } else {
                    Log::warning('CV file not found', [
                        'job_id' => $this->evaluationJob->id,
                        'path' => $cvPath
                    ]);
                }
            }

            if ($this->evaluationJob->projectReportUpload && $this->evaluationJob->projectReportUpload->storage_path) {
                $projectPath = Storage::path($this->evaluationJob->projectReportUpload->storage_path);
                if (file_exists($projectPath)) {
                    $projectText = $pdfExtractor->extractText($projectPath);
                    Log::info('Project text extracted', [
                        'job_id' => $this->evaluationJob->id,
                        'project_text_length' => strlen($projectText)
                    ]);
                } else {
                    Log::warning('Project file not found', [
                        'job_id' => $this->evaluationJob->id,
                        'path' => $projectPath
                    ]);
                }
            }

            // Define job requirements
            $jobTitle = $this->evaluationJob->job_title ?? 'Software Developer';
            $jobDescription = $this->getJobDescription($jobTitle);
            $caseStudyBrief = $this->getCaseStudyBrief();

            $results = [];

            // Analyze CV if provided (with RAG enhancement)
            if ($cvText) {
                Log::info('Starting CV analysis with ' . ($ragAvailable ? 'RAG-enhanced' : 'standard') . ' AI', [
                    'job_id' => $this->evaluationJob->id
                ]);
                
                $cvAnalysis = $geminiService->analyzeCv(
                    $cvText, 
                    $jobTitle, 
                    $jobDescription,
                    $ragAvailable ? $chromaDB : null
                );
                $results['cv_analysis'] = $cvAnalysis;
                
                Log::info('CV analysis completed', [
                    'job_id' => $this->evaluationJob->id,
                    'match_rate' => $cvAnalysis['match_rate'],
                    'rag_enhanced' => $ragAvailable
                ]);
            }

            // Analyze project if provided (with RAG enhancement)
            if ($projectText) {
                Log::info('Starting project analysis with ' . ($ragAvailable ? 'RAG-enhanced' : 'standard') . ' AI', [
                    'job_id' => $this->evaluationJob->id
                ]);
                
                $projectAnalysis = $geminiService->analyzeProject(
                    $projectText, 
                    $caseStudyBrief,
                    $ragAvailable ? $chromaDB : null
                );
                $results['project_analysis'] = $projectAnalysis;
                
                Log::info('Project analysis completed', [
                    'job_id' => $this->evaluationJob->id,
                    'overall_score' => $projectAnalysis['overall_score'],
                    'rag_enhanced' => $ragAvailable
                ]);
            }

            // Generate final summary if both CV and project are available
            if (isset($results['cv_analysis']) && isset($results['project_analysis'])) {
                Log::info('Generating final summary with Gemini', [
                    'job_id' => $this->evaluationJob->id
                ]);
                
                $finalSummary = $geminiService->generateFinalSummary(
                    $results['cv_analysis'],
                    $results['project_analysis'],
                    $jobTitle
                );
                $results['final_summary'] = $finalSummary;
            }

            // Calculate overall scores for API consistency
            $results['summary'] = $this->calculateSummaryScores($results);

            // Update job with results
            $this->evaluationJob->update([
                'status' => 'completed',
                'result' => $results,
                'completed_at' => now()
            ]);

            Log::info('Evaluation processing completed successfully', [
                'job_id' => $this->evaluationJob->id,
                'processing_time' => $this->evaluationJob->completed_at->diffInSeconds($this->evaluationJob->created_at)
            ]);

        } catch (Exception $e) {
            Log::error('Evaluation processing failed', [
                'job_id' => $this->evaluationJob->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->evaluationJob->update([
                'status' => 'failed',
                'result' => [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toISOString()
                ]
            ]);

            throw $e;
        }
    }

    /**
     * Calculate summary scores for API consistency
     */
    private function calculateSummaryScores(array $results): array
    {
        $summary = [
            'cv_match_rate' => 0,
            'project_score' => 0,
            'overall_recommendation' => 'insufficient_data',
            'processing_notes' => []
        ];

        if (isset($results['cv_analysis'])) {
            $summary['cv_match_rate'] = $results['cv_analysis']['match_rate'];
            $summary['processing_notes'][] = 'CV analysis completed';
        }

        if (isset($results['project_analysis'])) {
            $summary['project_score'] = $results['project_analysis']['overall_score'];
            $summary['processing_notes'][] = 'Project analysis completed';
        }

        // Generate overall recommendation
        if (isset($results['final_summary'])) {
            $summary['overall_recommendation'] = 'detailed_summary_available';
            $summary['processing_notes'][] = 'Final summary generated';
        } elseif ($summary['cv_match_rate'] > 0 && $summary['project_score'] > 0) {
            // Simple scoring if no final summary
            $overallScore = ($summary['cv_match_rate'] * 0.4) + ($summary['project_score'] * 0.12); // project_score is out of 5, normalize to 0.6
            
            if ($overallScore >= 0.7) {
                $summary['overall_recommendation'] = 'strong_candidate';
            } elseif ($overallScore >= 0.5) {
                $summary['overall_recommendation'] = 'moderate_candidate';
            } else {
                $summary['overall_recommendation'] = 'weak_candidate';
            }
        } elseif ($summary['cv_match_rate'] > 0) {
            $summary['overall_recommendation'] = $summary['cv_match_rate'] >= 0.7 ? 'cv_strong' : 'cv_review_needed';
        } elseif ($summary['project_score'] > 0) {
            $summary['overall_recommendation'] = $summary['project_score'] >= 3.5 ? 'project_strong' : 'project_review_needed';
        }

        return $summary;
    }

    /**
     * Get job description (in production, this would be from database)
     */
    private function getJobDescription(string $jobTitle): string
    {
        $descriptions = [
            'Software Developer' => "
We are seeking a skilled Software Developer to join our team. 

Key Requirements:
- Bachelor's degree in Computer Science or related field
- 2+ years of experience in software development
- Proficiency in programming languages such as Python, Java, JavaScript, or PHP
- Experience with web frameworks (React, Angular, Laravel, Django)
- Knowledge of database systems (MySQL, PostgreSQL)
- Familiarity with version control systems (Git)
- Strong problem-solving skills and attention to detail
- Experience with API development and integration
- Understanding of software development lifecycle
- Good communication and teamwork skills

Preferred Qualifications:
- Experience with cloud platforms (AWS, Azure, GCP)
- Knowledge of containerization (Docker, Kubernetes)
- Experience with automated testing
- Familiarity with CI/CD pipelines
- Experience with microservices architecture
",
            'Data Scientist' => "
We are looking for a Data Scientist to analyze complex data and provide insights.

Key Requirements:
- Master's degree in Data Science, Statistics, or related field
- 3+ years of experience in data analysis and machine learning
- Proficiency in Python, R, or SQL
- Experience with ML libraries (scikit-learn, TensorFlow, PyTorch)
- Statistical analysis and data visualization skills
- Experience with data preprocessing and feature engineering
- Knowledge of big data tools (Spark, Hadoop)
- Strong analytical and problem-solving skills
",
            'Product Manager' => "
We need a Product Manager to drive product strategy and development.

Key Requirements:
- Bachelor's degree in Business, Engineering, or related field
- 4+ years of product management experience
- Strong analytical and strategic thinking skills
- Experience with product roadmap planning
- Knowledge of agile development methodologies
- Excellent communication and leadership skills
- Data-driven decision making experience
- Customer-focused mindset
"
        ];

        return $descriptions[$jobTitle] ?? $descriptions['Software Developer'];
    }

    /**
     * Get case study brief (in production, this would be from database)
     */
    private function getCaseStudyBrief(): string
    {
        return "
CASE STUDY: AI-Powered CV Evaluation System

OBJECTIVE:
Build a comprehensive backend system for evaluating candidate CVs and project submissions using AI.

TECHNICAL REQUIREMENTS:

1. Backend API Development:
   - RESTful API endpoints for file upload, evaluation status, and results
   - Asynchronous processing using queue system
   - Database design for storing evaluations and results
   - Error handling and validation

2. AI Integration:
   - Integration with LLM API (OpenAI, Gemini, etc.)
   - Structured prompting for consistent evaluation
   - Score calculation and feedback generation

3. File Processing:
   - PDF upload and text extraction
   - File validation and security
   - Storage management

4. System Architecture:
   - Scalable and maintainable code structure
   - Proper separation of concerns
   - Configuration management
   - Logging and monitoring

5. Data Management:
   - Database migrations and models
   - Data relationships and integrity
   - Query optimization

EVALUATION CRITERIA:
- Technical Implementation (30%): Code quality, architecture, best practices
- Feature Completeness (25%): All required endpoints and functionality
- System Resilience (20%): Error handling, validation, edge cases
- Documentation (15%): Code comments, API documentation, setup instructions
- Innovation (10%): Creative solutions, additional features, optimization

DELIVERABLES:
- Complete Laravel backend application
- API documentation
- Database schema
- Setup and deployment instructions
- Test cases and examples

The solution should demonstrate professional software development practices and readiness for production deployment.
";
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessEvaluationJob failed permanently', [
            'job_id' => $this->evaluationJob->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->evaluationJob->update([
            'status' => 'failed',
            'result' => [
                'error' => 'Processing failed after maximum retries: ' . $exception->getMessage(),
                'failed_at' => now()->toISOString()
            ]
        ]);
    }
}