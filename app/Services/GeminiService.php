<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class GeminiService
{
    private string $apiKey;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key');
        $this->baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent';
        
        if (empty($this->apiKey)) {
            throw new Exception('Gemini API key not configured');
        }
    }

    /**
     * Generate content using Gemini Pro
     *
     * @param string $prompt
     * @param array $options
     * @return array
     * @throws Exception
     */
    public function generateContent(string $prompt, array $options = []): array
    {
        try {
            $response = Http::timeout(60)
                ->post($this->baseUrl . '?key=' . $this->apiKey, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt]
                            ]
                        ]
                    ],
                    'generationConfig' => array_merge([
                        'temperature' => 0.1, // Low temperature for consistent results
                        'topK' => 32,
                        'topP' => 1,
                        'maxOutputTokens' => 2048,
                    ], $options)
                ]);

            if (!$response->successful()) {
                throw new Exception('Gemini API request failed: ' . $response->body());
            }

            $data = $response->json();
            
            if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                throw new Exception('Invalid response format from Gemini API');
            }

            return [
                'text' => $data['candidates'][0]['content']['parts'][0]['text'],
                'usage' => $data['usageMetadata'] ?? null
            ];

        } catch (Exception $e) {
            Log::error('Gemini API error', [
                'error' => $e->getMessage(),
                'prompt_length' => strlen($prompt)
            ]);
            throw $e;
        }
    }

    /**
     * Analyze CV content and match with job requirements using RAG
     *
     * @param string $cvContent
     * @param string $jobTitle
     * @param string $jobDescription
     * @param ChromaDBService|null $chromaDB
     * @return array
     */
    public function analyzeCv(string $cvContent, string $jobTitle, string $jobDescription, $chromaDB = null): array
    {
        // Enhanced prompt with RAG if available
        if ($chromaDB) {
            try {
                // Get best matching job from vector DB
                $jobMatch = $chromaDB->findBestMatchingJob($jobTitle);
                if ($jobMatch) {
                    $jobDescription = $jobMatch['document'];
                    Log::info('Using RAG-enhanced job description', [
                        'matched_job' => $jobMatch['metadata']['title'] ?? 'Unknown',
                        'similarity' => 1 - ($jobMatch['distance'] ?? 1)
                    ]);
                }

                // Get CV evaluation criteria from vector DB
                $criteria = $chromaDB->queryCollection('cv_evaluation_rubrics', 'CV evaluation scoring rubric', 1);
                $evaluationCriteria = '';
                if (!empty($criteria['results']['documents'][0])) {
                    $evaluationCriteria = "\n\nDETAILED EVALUATION CRITERIA:\n" . 
                                         $criteria['results']['documents'][0];
                }

                $prompt = $this->buildEnhancedCvAnalysisPrompt($cvContent, $jobTitle, $jobDescription, $evaluationCriteria);
                
            } catch (\Exception $e) {
                Log::warning('RAG enhancement failed, using fallback', ['error' => $e->getMessage()]);
                $prompt = $this->buildCvAnalysisPrompt($cvContent, $jobTitle, $jobDescription);
            }
        } else {
            $prompt = $this->buildCvAnalysisPrompt($cvContent, $jobTitle, $jobDescription);
        }
        
        $response = $this->generateContent($prompt);
        
        return $this->parseCvAnalysisResponse($response['text']);
    }

    /**
     * Analyze project report using RAG-enhanced criteria
     *
     * @param string $projectContent
     * @param string $caseStudyBrief
     * @param ChromaDBService|null $chromaDB
     * @return array
     */
    public function analyzeProject(string $projectContent, string $caseStudyBrief, $chromaDB = null): array
    {
        // Enhanced prompt with RAG if available
        if ($chromaDB) {
            try {
                // Get project evaluation criteria from vector DB
                $criteria = $chromaDB->queryCollection('project_evaluation_rubrics', 'Project evaluation scoring rubric', 1);
                $evaluationCriteria = '';
                if (!empty($criteria['results']['documents'][0])) {
                    $evaluationCriteria = "\n\nDETAILED EVALUATION CRITERIA:\n" . 
                                         $criteria['results']['documents'][0];
                }

                $prompt = $this->buildEnhancedProjectAnalysisPrompt($projectContent, $caseStudyBrief, $evaluationCriteria);
                
            } catch (\Exception $e) {
                Log::warning('RAG enhancement failed for project analysis', ['error' => $e->getMessage()]);
                $prompt = $this->buildProjectAnalysisPrompt($projectContent, $caseStudyBrief);
            }
        } else {
            $prompt = $this->buildProjectAnalysisPrompt($projectContent, $caseStudyBrief);
        }
        
        $response = $this->generateContent($prompt);
        
        return $this->parseProjectAnalysisResponse($response['text']);
    }

    /**
     * Generate final evaluation summary
     *
     * @param array $cvAnalysis
     * @param array $projectAnalysis
     * @param string $jobTitle
     * @return array
     */
    public function generateFinalSummary(array $cvAnalysis, array $projectAnalysis, string $jobTitle): array
    {
        $prompt = $this->buildFinalSummaryPrompt($cvAnalysis, $projectAnalysis, $jobTitle);
        
        $response = $this->generateContent($prompt);
        
        return $this->parseFinalSummaryResponse($response['text']);
    }

    private function buildCvAnalysisPrompt(string $cvContent, string $jobTitle, string $jobDescription): string
    {
        return "
You are an expert HR analyst evaluating a candidate's CV for a {$jobTitle} position.

JOB DESCRIPTION:
{$jobDescription}

CANDIDATE CV:
{$cvContent}

Please analyze the CV and provide a structured evaluation with the following format:

MATCH_RATE: [0.0-1.0 decimal representing how well candidate matches job requirements]
TECHNICAL_SKILLS_SCORE: [1-5 score for technical skills alignment]
EXPERIENCE_SCORE: [1-5 score for experience level and relevance]
ACHIEVEMENTS_SCORE: [1-5 score for relevant achievements and impact]
CULTURAL_FIT_SCORE: [1-5 score for communication and collaboration indicators]

FEEDBACK:
[Detailed 2-3 sentences explaining strengths and gaps in candidate's background relative to job requirements]

Provide specific, actionable feedback based on the job requirements.
";
    }

    private function buildEnhancedCvAnalysisPrompt(string $cvContent, string $jobTitle, string $jobDescription, string $evaluationCriteria): string
    {
        return "
You are an expert HR analyst with access to comprehensive evaluation frameworks. Evaluate this candidate's CV for a {$jobTitle} position.

JOB DESCRIPTION:
{$jobDescription}

CANDIDATE CV:
{$cvContent}

{$evaluationCriteria}

Using the detailed criteria above, provide a structured evaluation with the following format:

MATCH_RATE: [0.0-1.0 decimal representing how well candidate matches job requirements]
TECHNICAL_SKILLS_SCORE: [1-5 score for technical skills alignment]
EXPERIENCE_SCORE: [1-5 score for experience level and relevance]
ACHIEVEMENTS_SCORE: [1-5 score for relevant achievements and impact]
CULTURAL_FIT_SCORE: [1-5 score for communication and collaboration indicators]

FEEDBACK:
[Detailed analysis explaining the scoring rationale, specific strengths, areas for improvement, and how the candidate aligns with the evaluation criteria]

Ensure your evaluation is consistent with the detailed criteria and provides actionable insights.
";
    }

    private function buildProjectAnalysisPrompt(string $projectContent, string $caseStudyBrief): string
    {
        return "
You are an expert technical reviewer evaluating a candidate's project submission. You MUST follow the exact format below.

CASE STUDY REQUIREMENTS:
{$caseStudyBrief}

CANDIDATE PROJECT REPORT:
{$projectContent}

IMPORTANT: Respond ONLY in this exact format, with no additional text:

CORRECTNESS_SCORE: 3
CODE_QUALITY_SCORE: 4
RESILIENCE_SCORE: 3
DOCUMENTATION_SCORE: 4
CREATIVITY_SCORE: 4

PROJECT_FEEDBACK:
The project demonstrates good understanding of the requirements with solid implementation of the core features. Areas for improvement include better error handling and more comprehensive documentation.
";
    }

    private function buildEnhancedProjectAnalysisPrompt(string $projectContent, string $caseStudyBrief, string $evaluationCriteria): string
    {
        return "
You are an expert technical reviewer with comprehensive evaluation frameworks. You MUST respond in the exact format specified.

CASE STUDY REQUIREMENTS:
{$caseStudyBrief}

CANDIDATE PROJECT REPORT:
{$projectContent}

{$evaluationCriteria}

IMPORTANT: Respond ONLY in this exact format, with no additional text or explanations:

CORRECTNESS_SCORE: 4
CODE_QUALITY_SCORE: 4
RESILIENCE_SCORE: 3
DOCUMENTATION_SCORE: 3
CREATIVITY_SCORE: 4

PROJECT_FEEDBACK:
Strong technical implementation with good use of modern frameworks and practices. The RAG integration shows innovation, though documentation could be more comprehensive.

Ensure your assessment follows the detailed rubrics and provides constructive, actionable feedback.
";
    }

    private function buildFinalSummaryPrompt(array $cvAnalysis, array $projectAnalysis, string $jobTitle): string
    {
        $cvScore = $cvAnalysis['match_rate'] ?? 0;
        $projectScore = $projectAnalysis['overall_score'] ?? 0;
        
        return "
You are a senior hiring manager making a final recommendation for a {$jobTitle} position.

CV EVALUATION SUMMARY:
- Match Rate: {$cvScore}
- Feedback: {$cvAnalysis['feedback']}

PROJECT EVALUATION SUMMARY:  
- Overall Score: {$projectScore}
- Feedback: {$projectAnalysis['feedback']}

Please provide a final hiring recommendation:

OVERALL_SUMMARY:
[3-4 sentences with: 1) Key strengths, 2) Areas of concern, 3) Final recommendation (hire/interview/reject), 4) Next steps if applicable]

Be direct, specific, and actionable in your assessment.
";
    }

    private function parseCvAnalysisResponse(string $response): array
    {
        // Extract structured data from response
        preg_match('/MATCH_RATE:\s*([0-9.]+)/', $response, $matchRate);
        preg_match('/TECHNICAL_SKILLS_SCORE:\s*([1-5])/', $response, $techScore);
        preg_match('/EXPERIENCE_SCORE:\s*([1-5])/', $response, $expScore);
        preg_match('/ACHIEVEMENTS_SCORE:\s*([1-5])/', $response, $achScore);
        preg_match('/CULTURAL_FIT_SCORE:\s*([1-5])/', $response, $cultScore);
        preg_match('/\*\*FEEDBACK:\*\*\s*\n*(.*?)(?:\n\n|\z)/s', $response, $feedback);

        return [
            'match_rate' => (float)($matchRate[1] ?? 0),
            'technical_skills_score' => (int)($techScore[1] ?? 3),
            'experience_score' => (int)($expScore[1] ?? 3),
            'achievements_score' => (int)($achScore[1] ?? 3), 
            'cultural_fit_score' => (int)($cultScore[1] ?? 3),
            'feedback' => trim($feedback[1] ?? 'No feedback provided'),
            'raw_response' => $response
        ];
    }

    private function parseProjectAnalysisResponse(string $response): array
    {
        preg_match('/CORRECTNESS_SCORE:\s*([1-5])/', $response, $corrScore);
        preg_match('/CODE_QUALITY_SCORE:\s*([1-5])/', $response, $qualScore);
        preg_match('/RESILIENCE_SCORE:\s*([1-5])/', $response, $resScore);
        preg_match('/DOCUMENTATION_SCORE:\s*([1-5])/', $response, $docScore);
        preg_match('/CREATIVITY_SCORE:\s*([1-5])/', $response, $creScore);
        preg_match('/PROJECT_FEEDBACK:\s*\n*(.*?)(?:\n\n|\z)/s', $response, $feedback);

        $scores = [
            'correctness' => (int)($corrScore[1] ?? 3),
            'code_quality' => (int)($qualScore[1] ?? 3),
            'resilience' => (int)($resScore[1] ?? 3),
            'documentation' => (int)($docScore[1] ?? 3),
            'creativity' => (int)($creScore[1] ?? 3),
        ];

        // Calculate weighted average (as per requirements)
        $overallScore = ($scores['correctness'] * 0.30) + 
                       ($scores['code_quality'] * 0.25) +
                       ($scores['resilience'] * 0.20) + 
                       ($scores['documentation'] * 0.15) +
                       ($scores['creativity'] * 0.10);

        return [
            'scores' => $scores,
            'overall_score' => round($overallScore, 1),
            'feedback' => trim($feedback[1] ?? 'No feedback provided'),
            'raw_response' => $response
        ];
    }

    private function parseFinalSummaryResponse(string $response): array
    {
        // Try to extract formatted response first
        preg_match('/OVERALL_SUMMARY:\s*\n?(.*?)(?:\n\n|$)/s', $response, $summary);
        
        $extractedSummary = '';
        if (!empty($summary[1])) {
            $extractedSummary = trim($summary[1]);
        } else {
            // If no formatted response, use the entire response as summary
            $extractedSummary = trim($response);
        }

        return [
            'overall_summary' => $extractedSummary ?: 'No summary provided',
            'raw_response' => $response
        ];
    }
}