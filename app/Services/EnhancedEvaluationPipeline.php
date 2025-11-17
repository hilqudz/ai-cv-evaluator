<?php

namespace App\Services;

use App\Models\EvaluationJob;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

/**
 * EnhancedEvaluationPipeline
 *
 * A reusable service that encapsulates the evaluation flow: PDF text extraction,
 * optional RAG-enhancement via ChromaDB, AI analysis via Gemini, and final
 * summary generation. This allows `ProcessEvaluationJob` or other callers to
 * reuse the same flow.
 */
class EnhancedEvaluationPipeline
{
    public function __construct(
        protected GeminiService $gemini,
        protected PdfTextExtractorService $pdfExtractor,
        protected ChromaDBService $chromaDB
    ) {}

    /**
     * Run the full evaluation pipeline for an EvaluationJob.
     * If $persist is true the EvaluationJob will be updated with results/status.
     *
     * @param EvaluationJob $evaluationJob
     * @param bool $persist
     * @return array results
     * @throws Exception
     */
    public function run(EvaluationJob $evaluationJob, bool $persist = true): array
    {
        try {
            Log::info('EnhancedEvaluationPipeline: start', ['job_id' => $evaluationJob->id]);

            if ($persist) {
                $evaluationJob->update(['status' => 'processing']);
            }

            // Check RAG availability
            $ragAvailable = false;
            try {
                if ($this->chromaDB->isServerRunning()) {
                    $ragAvailable = true;
                    Log::info('RAG available for pipeline', ['job_id' => $evaluationJob->id]);
                }
            } catch (Exception $e) {
                Log::warning('RAG availability check failed', ['error' => $e->getMessage()]);
            }

            // Extract texts
            $cvText = null;
            $projectText = null;

            if ($evaluationJob->cvUpload) {
                $cvPath = Storage::path($evaluationJob->cvUpload->file_path);
                $cvText = $this->pdfExtractor->extractText($cvPath);
                Log::info('Pipeline: extracted CV text', ['job_id' => $evaluationJob->id, 'len' => strlen($cvText)]);
            }

            if ($evaluationJob->projectReportUpload ?? false) {
                // Support both naming conventions if present
                $projectRel = $evaluationJob->projectUpload ?? $evaluationJob->projectReportUpload;
                if ($projectRel) {
                    $projectPath = Storage::path($projectRel->file_path);
                    $projectText = $this->pdfExtractor->extractText($projectPath);
                    Log::info('Pipeline: extracted project text', ['job_id' => $evaluationJob->id, 'len' => strlen($projectText)]);
                }
            }

            // Prepare job metadata and briefs
            $jobTitle = $evaluationJob->job_title ?? 'Software Developer';
            $jobDescription = $this->getJobDescription($jobTitle);
            $caseStudyBrief = $this->getCaseStudyBrief();

            $results = [];

            if ($cvText) {
                $results['cv_analysis'] = $this->gemini->analyzeCv(
                    $cvText,
                    $jobTitle,
                    $jobDescription,
                    $ragAvailable ? $this->chromaDB : null
                );
                Log::info('Pipeline: cv analysis done', ['job_id' => $evaluationJob->id]);
            }

            if ($projectText) {
                $results['project_analysis'] = $this->gemini->analyzeProject(
                    $projectText,
                    $caseStudyBrief,
                    $ragAvailable ? $this->chromaDB : null
                );
                Log::info('Pipeline: project analysis done', ['job_id' => $evaluationJob->id]);
            }

            if (isset($results['cv_analysis']) && isset($results['project_analysis'])) {
                $results['final_summary'] = $this->gemini->generateFinalSummary(
                    $results['cv_analysis'],
                    $results['project_analysis'],
                    $jobTitle
                );
                Log::info('Pipeline: final summary generated', ['job_id' => $evaluationJob->id]);
            }

            $results['summary'] = $this->calculateSummaryScores($results);

            if ($persist) {
                $evaluationJob->update([
                    'status' => 'completed',
                    'result' => $results,
                    'completed_at' => now()
                ]);
            }

            Log::info('EnhancedEvaluationPipeline: completed', ['job_id' => $evaluationJob->id]);

            return $results;

        } catch (Exception $e) {
            Log::error('EnhancedEvaluationPipeline: failed', ['job_id' => $evaluationJob->id, 'error' => $e->getMessage()]);
            if ($persist) {
                $evaluationJob->update([
                    'status' => 'failed',
                    'result' => ['error' => $e->getMessage(), 'failed_at' => now()->toISOString()]
                ]);
            }
            throw $e;
        }
    }

    /**
     * Calculate summary scores (kept consistent with job implementation)
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
            $summary['cv_match_rate'] = $results['cv_analysis']['match_rate'] ?? 0;
            $summary['processing_notes'][] = 'CV analysis completed';
        }

        if (isset($results['project_analysis'])) {
            $summary['project_score'] = $results['project_analysis']['overall_score'] ?? 0;
            $summary['processing_notes'][] = 'Project analysis completed';
        }

        if (isset($results['final_summary'])) {
            $summary['overall_recommendation'] = 'detailed_summary_available';
            $summary['processing_notes'][] = 'Final summary generated';
        } elseif ($summary['cv_match_rate'] > 0 && $summary['project_score'] > 0) {
            $overallScore = ($summary['cv_match_rate'] * 0.4) + ($summary['project_score'] * 0.12);
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
     * Replicate simple job description lookup (kept for offline use)
     */
    private function getJobDescription(string $jobTitle): string
    {
        $descriptions = [
            'Software Developer' => "\nWe are seeking a skilled Software Developer to join our team. \n\nKey Requirements:\n- Bachelor's degree in Computer Science or related field\n- 2+ years of experience in software development\n- Proficiency in programming languages such as Python, Java, JavaScript, or PHP\n- Experience with web frameworks (React, Angular, Laravel, Django)\n",
        ];

        return $descriptions[$jobTitle] ?? $descriptions['Software Developer'];
    }

    private function getCaseStudyBrief(): string
    {
        return "CASE STUDY: AI-Powered CV Evaluation System\n\nOBJECTIVE:\nBuild a comprehensive backend system for evaluating candidate CVs and project submissions using AI.";
    }
}
