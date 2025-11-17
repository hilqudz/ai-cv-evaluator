<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Log;
use Exception;

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
            throw new Exception('ChromaDB Cloud credentials not configured');
        }
    }

    /**
     * Check if ChromaDB Cloud is available
     *
     * @return bool
     */
    public function isServerRunning(): bool
    {
        // For cloud mode, assume it's always available if config is set
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
        $this->ensureServerRunning();
        
        $script = storage_path('app/scripts/chroma_cloud_operations.py');

        // Generate IDs if not provided
        if (empty($ids)) {
            $ids = array_map(fn() => uniqid(), $documents);
        }

        $command = [
            $this->pythonPath, $script, 'add_documents',
            '--collection', $collectionName,
            '--mode', $this->mode,
            '--documents', json_encode($documents),
            '--metadatas', json_encode($metadatas ?: array_fill(0, count($documents), [])),
            '--ids', json_encode($ids)
        ];
        
        if ($this->mode === 'cloud') {
            $command = array_merge($command, [
                '--api_key', $this->cloudConfig['api_key'],
                '--tenant', $this->cloudConfig['tenant'],
                '--database', $this->cloudConfig['database']
            ]);
        }

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
        $this->ensureServerRunning();
        
        $script = storage_path('app/scripts/chroma_cloud_operations.py');

        $command = [
            $this->pythonPath, $script, 'query_collection',
            '--collection', $collectionName,
            '--mode', $this->mode,
            '--query', $queryText,
            '--n_results', (string)$nResults,
            '--where', json_encode($where)
        ];
        
        if ($this->mode === 'cloud') {
            $command = array_merge($command, [
                '--api_key', $this->cloudConfig['api_key'],
                '--tenant', $this->cloudConfig['tenant'],
                '--database', $this->cloudConfig['database']
            ]);
        }

        $result = Process::run($command);

        if (!$result->successful()) {
            throw new Exception('Failed to query collection: ' . $result->errorOutput());
        }

        return json_decode($result->output(), true);
    }

    /**
     * Initialize job descriptions collection with default data
     *
     * @return array
     * @throws Exception
     */
    public function initializeJobDescriptions(): array
    {
        $jobDescriptions = [
            [
                'id' => 'software_developer',
                'title' => 'Software Developer',
                'description' => "We are seeking a skilled Software Developer to join our team. 

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
- Experience with microservices architecture",
                'level' => 'mid',
                'category' => 'engineering',
                'skills' => ['PHP', 'Laravel', 'JavaScript', 'React', 'Python', 'SQL', 'Git'],
                'experience_years' => 2
            ],
            [
                'id' => 'senior_software_engineer',
                'title' => 'Senior Software Engineer',
                'description' => "We are looking for a Senior Software Engineer to lead technical initiatives.

Key Requirements:
- Bachelor's or Master's degree in Computer Science
- 5+ years of software development experience
- Strong leadership and mentoring skills
- Advanced proficiency in multiple programming languages
- Experience with system architecture and design
- Experience leading technical projects
- Knowledge of software engineering best practices
- Experience with performance optimization
- Strong analytical and problem-solving skills

Technical Skills:
- Proficiency in backend technologies (Node.js, Python, Java, Go)
- Experience with frontend frameworks (React, Vue, Angular)
- Database design and optimization
- Cloud platforms and DevOps practices
- Microservices and distributed systems",
                'level' => 'senior',
                'category' => 'engineering',
                'skills' => ['Python', 'Node.js', 'React', 'PostgreSQL', 'AWS', 'Docker', 'Microservices'],
                'experience_years' => 5
            ],
            [
                'id' => 'data_scientist',
                'title' => 'Data Scientist',
                'description' => "We are looking for a Data Scientist to analyze complex data and provide insights.

Key Requirements:
- Master's degree in Data Science, Statistics, or related field
- 3+ years of experience in data analysis and machine learning
- Proficiency in Python, R, or SQL
- Experience with ML libraries (scikit-learn, TensorFlow, PyTorch)
- Statistical analysis and data visualization skills
- Experience with data preprocessing and feature engineering
- Knowledge of big data tools (Spark, Hadoop)
- Strong analytical and problem-solving skills

Technical Skills:
- Machine Learning algorithms and techniques
- Data visualization tools (Tableau, Power BI, matplotlib)
- Big data processing frameworks
- Statistical modeling and hypothesis testing
- A/B testing and experimentation",
                'level' => 'mid',
                'category' => 'data',
                'skills' => ['Python', 'R', 'SQL', 'TensorFlow', 'scikit-learn', 'Tableau', 'Statistics'],
                'experience_years' => 3
            ],
            [
                'id' => 'product_manager',
                'title' => 'Product Manager',
                'description' => "We need a Product Manager to drive product strategy and development.

Key Requirements:
- Bachelor's degree in Business, Engineering, or related field
- 4+ years of product management experience
- Strong analytical and strategic thinking skills
- Experience with product roadmap planning
- Knowledge of agile development methodologies
- Excellent communication and leadership skills
- Data-driven decision making experience
- Customer-focused mindset

Core Competencies:
- Product strategy and roadmap development
- Market research and competitive analysis
- User experience and design thinking
- Stakeholder management
- Metrics and analytics
- Cross-functional team collaboration",
                'level' => 'mid',
                'category' => 'product',
                'skills' => ['Product Strategy', 'Agile', 'Analytics', 'User Research', 'Roadmap Planning'],
                'experience_years' => 4
            ]
        ];

        // Create collection
        $this->createCollection('job_descriptions', [
            'description' => 'Job descriptions and requirements for candidate matching'
        ]);

        // Add job descriptions as documents
        $documents = array_column($jobDescriptions, 'description');
        $metadatas = array_map(function($job) {
            unset($job['description']);
            return $job;
        }, $jobDescriptions);
        $ids = array_column($jobDescriptions, 'id');

        return $this->addDocuments('job_descriptions', $documents, $metadatas, $ids);
    }

    /**
     * Initialize evaluation rubrics collection
     *
     * @return array
     * @throws Exception
     */
    public function initializeEvaluationRubrics(): array
    {
        $rubrics = [
            [
                'id' => 'cv_technical_skills',
                'category' => 'cv_evaluation',
                'type' => 'technical_skills',
                'criteria' => "Technical Skills Assessment Criteria:

5 - Exceptional: Advanced expertise in multiple relevant technologies, contributions to open source, deep understanding of advanced concepts
4 - Proficient: Strong skills in core technologies, some advanced knowledge, good understanding of best practices  
3 - Competent: Solid foundation in required technologies, basic understanding of advanced concepts
2 - Developing: Limited experience with required technologies, basic skills only
1 - Inadequate: Minimal or no relevant technical skills demonstrated

Evaluation Focus:
- Programming language proficiency
- Framework and library experience
- Database and system design knowledge
- Development tools and methodologies
- Problem-solving approach and complexity of projects handled",
                'weight' => 0.25
            ],
            [
                'id' => 'cv_experience_relevance',
                'category' => 'cv_evaluation',
                'type' => 'experience',
                'criteria' => "Experience Relevance Assessment:

5 - Exceptional: Extensive directly relevant experience, leadership roles, significant impact demonstrated
4 - Proficient: Strong relevant experience, some leadership, clear career progression
3 - Competent: Adequate relevant experience, steady career growth
2 - Developing: Some relevant experience but limited depth or breadth
1 - Inadequate: Minimal relevant experience or significant gaps

Evaluation Focus:
- Years of relevant experience
- Complexity and scale of previous roles
- Industry relevance and domain knowledge
- Career progression and growth trajectory
- Leadership and mentoring experience",
                'weight' => 0.30
            ],
            [
                'id' => 'project_code_quality',
                'category' => 'project_evaluation',
                'type' => 'code_quality',
                'criteria' => "Code Quality Assessment Criteria:

5 - Exceptional: Exemplary code structure, advanced design patterns, excellent documentation, comprehensive testing
4 - Proficient: Well-structured code, good practices followed, adequate documentation and testing
3 - Competent: Generally well-organized code, basic best practices, some documentation
2 - Developing: Inconsistent code quality, limited documentation, basic structure
1 - Inadequate: Poor code organization, no documentation, difficult to understand

Evaluation Focus:
- Code organization and structure
- Use of design patterns and principles
- Documentation quality and completeness
- Testing coverage and quality
- Error handling and edge cases
- Performance considerations",
                'weight' => 0.25
            ],
            [
                'id' => 'project_technical_implementation',
                'category' => 'project_evaluation', 
                'type' => 'technical_correctness',
                'criteria' => "Technical Implementation Assessment:

5 - Exceptional: Innovative solutions, optimal architecture, handles all requirements plus extras
4 - Proficient: Solid technical implementation, good architecture choices, meets all requirements
3 - Competent: Adequate implementation, basic architecture, meets most requirements
2 - Developing: Limited implementation, questionable architecture choices, meets some requirements
1 - Inadequate: Poor implementation, significant technical issues, fails to meet basic requirements

Evaluation Focus:
- Correctness of implementation
- Architecture and design decisions
- Technology choices and justification
- Scalability and maintainability considerations
- Security and performance aspects
- Innovation and creativity in solutions",
                'weight' => 0.30
            ]
        ];

        // Create collection
        $this->createCollection('evaluation_rubrics', [
            'description' => 'Evaluation criteria and rubrics for consistent assessment'
        ]);

        // Add rubrics as documents
        $documents = array_column($rubrics, 'criteria');
        $metadatas = array_map(function($rubric) {
            unset($rubric['criteria']);
            return $rubric;
        }, $rubrics);
        $ids = array_column($rubrics, 'id');

        return $this->addDocuments('evaluation_rubrics', $documents, $metadatas, $ids);
    }

    /**
     * Find best matching job description
     *
     * @param string $jobTitle
     * @param array $skills
     * @param string $experienceLevel
     * @return array|null
     * @throws Exception
     */
    public function findBestMatchingJob(string $jobTitle, array $skills = [], string $experienceLevel = ''): ?array
    {
        $query = $jobTitle;
        if (!empty($skills)) {
            $query .= ' ' . implode(' ', $skills);
        }
        if (!empty($experienceLevel)) {
            $query .= ' ' . $experienceLevel;
        }

        $results = $this->queryCollection('job_descriptions', $query, 1);
        
        if (empty($results['documents']) || empty($results['documents'][0])) {
            return null;
        }

        return [
            'document' => $results['documents'][0][0],
            'metadata' => $results['metadatas'][0][0] ?? [],
            'distance' => $results['distances'][0][0] ?? 1.0
        ];
    }

    /**
     * Get evaluation criteria for specific category
     *
     * @param string $category
     * @param string $type
     * @return array
     * @throws Exception
     */
    public function getEvaluationCriteria(string $category, string $type = ''): array
    {
        $where = ['category' => $category];
        if (!empty($type)) {
            $where['type'] = $type;
        }

        $query = "$category $type evaluation criteria";
        
        return $this->queryCollection('evaluation_rubrics', $query, 10, $where);
    }

    /**
     * Ensure ChromaDB server is running
     *
     * @throws Exception
     */
    private function ensureServerRunning(): void
    {
        if (!$this->isServerStarted && !$this->startServer()) {
            throw new Exception('ChromaDB server is not available');
        }
    }

    /**
     * Create ChromaDB startup script
     *
     * @param string $scriptPath
     */
    private function createStartScript(string $scriptPath): void
    {
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $script = <<<'PYTHON'
#!/usr/bin/env python3
import sys
import argparse
import chromadb
from chromadb.config import Settings

def main():
    parser = argparse.ArgumentParser(description='Start ChromaDB server')
    parser.add_argument('--host', default='127.0.0.1', help='Host to bind to')
    parser.add_argument('--port', default='8001', help='Port to bind to')
    
    args = parser.parse_args()
    
    try:
        # Start ChromaDB server
        client = chromadb.HttpClient(
            host=args.host,
            port=int(args.port),
            settings=Settings(
                chroma_server_host=args.host,
                chroma_server_http_port=int(args.port),
                allow_reset=True
            )
        )
        
        print(f"ChromaDB server started on {args.host}:{args.port}")
        
        # Keep server running
        while True:
            try:
                client.heartbeat()
                time.sleep(10)
            except KeyboardInterrupt:
                print("Shutting down ChromaDB server...")
                break
                
    except Exception as e:
        print(f"Error starting ChromaDB server: {e}", file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    import time
    main()
PYTHON;

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
    }

    /**
     * Create ChromaDB operations script
     *
     * @param string $scriptPath
     */
    private function createOperationsScript(string $scriptPath): void
    {
        $directory = dirname($scriptPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $script = <<<'PYTHON'
#!/usr/bin/env python3
import sys
import json
import argparse
import chromadb
from chromadb.config import Settings

def main():
    parser = argparse.ArgumentParser(description='ChromaDB operations')
    parser.add_argument('operation', choices=['create_collection', 'add_documents', 'query_collection'])
    parser.add_argument('--host', default='127.0.0.1')
    parser.add_argument('--port', default='8001')
    parser.add_argument('--name', help='Collection name')
    parser.add_argument('--collection', help='Collection name for operations')
    parser.add_argument('--metadata', help='Collection metadata as JSON')
    parser.add_argument('--documents', help='Documents as JSON array')
    parser.add_argument('--metadatas', help='Metadatas as JSON array')
    parser.add_argument('--ids', help='IDs as JSON array')
    parser.add_argument('--query', help='Query text')
    parser.add_argument('--n_results', type=int, default=5)
    parser.add_argument('--where', help='Where clause as JSON')
    
    args = parser.parse_args()
    
    try:
        client = chromadb.HttpClient(
            host=args.host,
            port=int(args.port)
        )
        
        if args.operation == 'create_collection':
            metadata = json.loads(args.metadata) if args.metadata else {}
            collection = client.get_or_create_collection(
                name=args.name,
                metadata=metadata
            )
            print(json.dumps({'success': True, 'name': collection.name}))
            
        elif args.operation == 'add_documents':
            collection = client.get_collection(args.collection)
            documents = json.loads(args.documents)
            metadatas = json.loads(args.metadatas) if args.metadatas else None
            ids = json.loads(args.ids)
            
            collection.add(
                documents=documents,
                metadatas=metadatas,
                ids=ids
            )
            print(json.dumps({'success': True, 'added': len(documents)}))
            
        elif args.operation == 'query_collection':
            collection = client.get_collection(args.collection)
            where = json.loads(args.where) if args.where else None
            
            results = collection.query(
                query_texts=[args.query],
                n_results=args.n_results,
                where=where
            )
            print(json.dumps(results))
            
    except Exception as e:
        print(json.dumps({'error': str(e)}), file=sys.stderr)
        sys.exit(1)

if __name__ == "__main__":
    main()
PYTHON;

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);
    }
}