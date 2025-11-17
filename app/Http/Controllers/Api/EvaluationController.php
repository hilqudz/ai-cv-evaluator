<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EvaluationService;
use App\Jobs\ProcessEvaluationJob;
use App\Models\EvaluationJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class EvaluationController extends Controller
{
    protected EvaluationService $evaluationService;
    
    public function __construct(EvaluationService $evaluationService)
    {
        $this->evaluationService = $evaluationService;
    }
    
    /**
     * Start evaluation process
     * POST /api/evaluate
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function evaluate(Request $request): JsonResponse
    {
        try {
            // Validate request - job title required, at least one upload ID
            $request->validate([
                'job_title' => 'required|string|max:255',
                'cv_upload_id' => 'sometimes|required|integer|exists:uploads,id',
                'project_report_upload_id' => 'sometimes|required|integer|exists:uploads,id',
            ]);
            
            // Must have at least one upload ID
            if (!$request->has('cv_upload_id') && !$request->has('project_report_upload_id')) {
                return response()->json([
                    'success' => false,
                    'message' => 'At least one upload ID (cv_upload_id or project_report_upload_id) must be provided'
                ], 422);
            }
            
            // Create evaluation job
            $evaluationJob = $this->evaluationService->createEvaluationJob(
                $request->input('job_title'),
                $request->input('cv_upload_id'),
                $request->input('project_report_upload_id')
            );
            
            // Dispatch queue job for AI processing
            ProcessEvaluationJob::dispatch($evaluationJob);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $evaluationJob->id,
                    'status' => $evaluationJob->status
                ]
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
                'message' => 'Evaluation creation failed: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get evaluation result
     * GET /api/result/{id}
     * 
     * @param string $id
     * @return JsonResponse
     */
    public function getResult(string $id): JsonResponse
    {
        try {
            $evaluationJob = $this->evaluationService->getEvaluationJob($id);
            
            if (!$evaluationJob) {
                return response()->json([
                    'success' => false,
                    'message' => 'Evaluation job not found'
                ], 404);
            }
            
            $response = [
                'id' => $evaluationJob->id,
                'status' => $evaluationJob->status,
            ];
            
            // If completed, include result in the specified format
            if ($evaluationJob->status === 'completed' && $evaluationJob->result) {
                $rawResult = json_decode($evaluationJob->result, true);
                $response['result'] = $this->formatEvaluationResult($rawResult);
            }
            
            return response()->json($response);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve evaluation result: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Format evaluation result to match API specification
     * 
     * @param array $rawResult
     * @return array
     */
    private function formatEvaluationResult(array $rawResult): array
    {
        $formatted = [
            'cv_match_rate' => 0.0,
            'cv_feedback' => '',
            'project_score' => 0.0,
            'project_feedback' => '',
            'overall_summary' => ''
        ];
        
        // Extract CV analysis data
        if (isset($rawResult['cv_analysis'])) {
            $cvAnalysis = $rawResult['cv_analysis'];
            $formatted['cv_match_rate'] = $cvAnalysis['match_rate'] ?? 0.0;
            $formatted['cv_feedback'] = $cvAnalysis['feedback'] ?? '';
        }
        
        // Extract project analysis data
        if (isset($rawResult['project_analysis'])) {
            $projectAnalysis = $rawResult['project_analysis'];
            $formatted['project_score'] = $projectAnalysis['overall_score'] ?? 0.0;
            $formatted['project_feedback'] = $projectAnalysis['feedback'] ?? '';
        }
        
        // Generate overall summary
        if (isset($rawResult['final_summary'])) {
            $formatted['overall_summary'] = $rawResult['final_summary']['overall_summary'] ?? '';
        } else {
            // Generate simple overall summary if no final_summary exists
            $formatted['overall_summary'] = $this->generateSimpleOverallSummary($formatted);
        }
        
        return $formatted;
    }
    
    /**
     * Generate simple overall summary when no final summary exists
     * 
     * @param array $formatted
     * @return string
     */
    private function generateSimpleOverallSummary(array $formatted): string
    {
        $cvRate = $formatted['cv_match_rate'];
        $projectScore = $formatted['project_score'];
        
        if ($cvRate > 0 && $projectScore > 0) {
            $overallScore = ($cvRate * 0.6) + ($projectScore * 0.08); // project_score is out of 5, normalize to 0.4
            
            if ($overallScore >= 0.7) {
                return "Strong candidate with excellent CV match ({$cvRate}) and solid project performance ({$projectScore}/5). Recommended for next interview stage.";
            } elseif ($overallScore >= 0.5) {
                return "Moderate candidate with good potential. CV match rate: {$cvRate}, Project score: {$projectScore}/5. Consider for interview with focus areas identified.";
            } else {
                return "Candidate shows some promise but has gaps. CV match: {$cvRate}, Project: {$projectScore}/5. Requires significant development in key areas.";
            }
        } elseif ($cvRate > 0) {
            $recommendation = $cvRate >= 0.7 ? 'Strong CV match' : 'CV shows potential but has gaps';
            return "{$recommendation}. Match rate: {$cvRate}. Project evaluation pending.";
        } elseif ($projectScore > 0) {
            $recommendation = $projectScore >= 3.5 ? 'Strong project implementation' : 'Project shows promise with areas for improvement';
            return "{$recommendation}. Score: {$projectScore}/5. CV evaluation pending.";
        }
        
        return "Evaluation incomplete. Insufficient data to provide comprehensive assessment.";
    }
}
