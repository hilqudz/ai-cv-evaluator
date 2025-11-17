<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * ChromaDBService - Cloud-only implementation
 * 
 * Production-ready service for ChromaDB Cloud integration
 * Handles vector database operations for RAG system
 */
class ChromaDBService
{
    private string $apiKey;
    private string $tenant;
    private string $database;
    private string $pythonPath;

    public function __construct()
    {
        $this->apiKey = config('services.chromadb.api_key');
        $this->tenant = config('services.chromadb.tenant');
        $this->database = config('services.chromadb.database', 'ai-cv-evaluator');
        $this->pythonPath = config('services.chromadb.python_path', 'python3');
        
        if (empty($this->apiKey) || empty($this->tenant)) {
            throw new Exception('ChromaDB Cloud credentials not configured. Please set CHROMADB_API_KEY and CHROMADB_TENANT in your .env file.');
        }
    }

    /**
     * Check if ChromaDB Cloud is available
     *
     * @return bool
     */
    public function isServerRunning(): bool
    {
        return !empty($this->apiKey) && !empty($this->tenant);
    }

    /**
     * Create or get collection
     *
     * @param string $collectionName
     * @param array $metadata
     * @return array
     * @throws Exception
     */
    public function createCollection(string $collectionName, array $metadata = []): array
    {
        $this->ensureServiceAvailable();
        
        $script = storage_path('app/scripts/chroma_cloud_operations.py');
        
        $command = [
            $this->pythonPath, $script, 'create_collection',
            '--name', $collectionName,
            '--mode', 'cloud',
            '--api_key', $this->apiKey,
            '--tenant', $this->tenant,
            '--database', $this->database,
            '--metadata', json_encode($metadata)
        ];
        
        $result = Process::run($command);

        if (!$result->successful()) {
            throw new Exception('Failed to create collection: ' . $result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    /**
     * Add documents to collection
     *
     * @param string $collectionName
     * @param array $documents
     * @param array $metadatas
     * @param array $ids
     * @return array
     * @throws Exception
     */
    public function addDocuments(
        string $collectionName,
        array $documents,
        array $metadatas = [],
        array $ids = []
    ): array {
        $this->ensureServiceAvailable();
        
        $script = storage_path('app/scripts/chroma_cloud_operations.py');

        // Generate IDs if not provided
        if (empty($ids)) {
            $ids = array_map(fn() => uniqid(), $documents);
        }

        $command = [
            $this->pythonPath, $script, 'add_documents',
            '--collection', $collectionName,
            '--mode', 'cloud',
            '--api_key', $this->apiKey,
            '--tenant', $this->tenant,
            '--database', $this->database,
            '--documents', json_encode($documents),
            '--metadatas', json_encode($metadatas ?: array_fill(0, count($documents), [])),
            '--ids', json_encode($ids)
        ];

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new Exception('Failed to add documents: ' . $result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    /**
     * Query collection for similar documents
     *
     * @param string $collectionName
     * @param string $queryText
     * @param int $nResults
     * @param array $where
     * @return array
     * @throws Exception
     */
    public function queryCollection(
        string $collectionName,
        string $queryText,
        int $nResults = 5,
        array $where = []
    ): array {
        $this->ensureServiceAvailable();
        
        $script = storage_path('app/scripts/chroma_cloud_operations.py');

        $command = [
            $this->pythonPath, $script, 'query_collection',
            '--collection', $collectionName,
            '--mode', 'cloud',
            '--api_key', $this->apiKey,
            '--tenant', $this->tenant,
            '--database', $this->database,
            '--query', $queryText,
            '--n_results', (string)$nResults,
            '--where', json_encode($where)
        ];

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new Exception('Failed to query collection: ' . $result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    /**
     * Find best matching job description
     *
     * @param string $jobTitle
     * @return array|null
     * @throws Exception
     */
    public function findBestMatchingJob(string $jobTitle): ?array
    {
        try {
            $results = $this->queryCollection('job_descriptions', $jobTitle, 1);
            
            if (empty($results['results']['documents'])) {
                Log::warning('No matching job found for title: ' . $jobTitle);
                return null;
            }

            return [
                'document' => $results['results']['documents'][0],
                'metadata' => $results['results']['metadatas'][0] ?? [],
                'distance' => $results['results']['distances'][0] ?? 1.0
            ];

        } catch (Exception $e) {
            Log::error('Failed to find matching job', [
                'job_title' => $jobTitle,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get evaluation criteria from vector database
     *
     * @param string $evaluationType 'cv_evaluation' or 'project_evaluation'
     * @return array
     * @throws Exception
     */
    public function getEvaluationCriteria(string $evaluationType): array
    {
        try {
            $collectionName = $evaluationType === 'cv_evaluation' 
                ? 'cv_evaluation_rubrics' 
                : 'project_evaluation_rubrics';
            
            $query = $evaluationType === 'cv_evaluation' 
                ? 'CV evaluation scoring rubric criteria' 
                : 'Project evaluation scoring rubric criteria';

            return $this->queryCollection($collectionName, $query, 1);

        } catch (Exception $e) {
            Log::error('Failed to get evaluation criteria', [
                'evaluation_type' => $evaluationType,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get case study brief for project evaluation
     *
     * @return array
     * @throws Exception
     */
    public function getCaseStudyBrief(): array
    {
        try {
            return $this->queryCollection('case_study_brief', 'AI CV evaluation case study requirements', 1);

        } catch (Exception $e) {
            Log::error('Failed to get case study brief', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * List all collections
     *
     * @return array
     * @throws Exception
     */
    public function listCollections(): array
    {
        $this->ensureServiceAvailable();
        
        $script = storage_path('app/scripts/chroma_cloud_operations.py');

        $command = [
            $this->pythonPath, $script, 'list_collections',
            '--mode', 'cloud',
            '--api_key', $this->apiKey,
            '--tenant', $this->tenant,
            '--database', $this->database
        ];

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new Exception('Failed to list collections: ' . $result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    /**
     * Health check for ChromaDB Cloud service
     *
     * @return array
     */
    public function healthCheck(): array
    {
        try {
            $collections = $this->listCollections();
            
            return [
                'status' => 'healthy',
                'service' => 'ChromaDB Cloud',
                'collections_count' => count($collections['collections'] ?? []),
                'collections' => $collections['collections'] ?? [],
                'timestamp' => now()->toISOString()
            ];

        } catch (Exception $e) {
            return [
                'status' => 'unhealthy',
                'service' => 'ChromaDB Cloud',
                'error' => $e->getMessage(),
                'timestamp' => now()->toISOString()
            ];
        }
    }

    /**
     * Ensure service is available
     *
     * @throws Exception
     */
    private function ensureServiceAvailable(): void
    {
        if (!$this->isServerRunning()) {
            throw new Exception('ChromaDB Cloud service not available. Please check your credentials.');
        }
    }
}