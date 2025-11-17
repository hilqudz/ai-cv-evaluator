# 🤖 AI CV Evaluator

A comprehensive backend service that automates CV and project evaluation using AI. Built with Laravel 12, Google Gemini 2.0 Flash, and ChromaDB for RAG-enhanced analysis.

## 🚀 Features

- **AI-Powered CV Analysis** - Semantic matching against job requirements
- **Project Report Evaluation** - Technical assessment with detailed scoring
- **RAG Enhancement** - ChromaDB vector database for contextual evaluation
- **Asynchronous Processing** - Redis queue system for background jobs
- **RESTful API** - Clean endpoints for upload, evaluate, and results
- **Production Ready** - Cloud-based services (Supabase, Redis Cloud, ChromaDB Cloud)


## 🛠️ Tech Stack

- **Framework**: Laravel 12
- **Database**: PostgreSQL (Supabase)
- **Queue**: Redis 
- **AI/LLM**: Google Gemini 2.0 Flash
- **Vector DB**: ChromaDB 

## 🏗️ Design Choices

### Core Design Decisions

**1. Laravel 12 Framework**
- **Why**: Mature ecosystem, robust queue system, excellent ORM
- **Benefits**: Built-in job queues, migration system, artisan CLI
- **Trade-off**: PHP learning curve vs rapid development

**2. Microservice-Ready Architecture**
- **Pattern**: Single responsibility services (upload, evaluation, result)
- **Why**: Scalable, testable, maintainable components
- **Implementation**: Separate controllers for each domain

**3. Queue-Based Processing**
- **Why**: AI processing is slow (5-30 seconds per evaluation)
- **Pattern**: Async job processing with Redis
- **Benefits**: Non-blocking API, retry mechanisms, scalability

**4. RAG (Retrieval-Augmented Generation)**
- **Why**: Context-aware AI responses vs generic evaluations
- **Implementation**: ChromaDB for vector similarity search
- **Impact**: More relevant, accurate evaluations

**5. Multi-Model AI Strategy**
- **Choice**: Google Gemini 2.0 Flash
- **Why**: Superior document understanding, cost-effective
- **Alternative considered**: OpenAI GPT-4 (more expensive)

### Scalability Considerations

**Horizontal Scaling:**
- Stateless API design
- Queue workers can be distributed
- Database connection pooling ready

**Performance Optimization:**
- Async processing prevents timeout
- Structured prompts reduce AI latency
- Vector search for relevant context only 

## 📋 Prerequisites

- PHP 8.2+
- Composer
- Node.js & npm
- poppler-utils (for PDF processing)

### Install poppler-utils:

**macOS:**
```bash
brew install poppler
```

**Ubuntu/Debian:**
```bash
sudo apt-get install poppler-utils
```

**Windows:**
```bash
**Windows:**
```bash
# Download from: https://blog.alivate.com.au/poppler-windows/
```

## 🚀 Quick Start

### 1. Clone Repository

```bash
git clone <your-repo-url>
cd ai-cv-evaluator
```

### 2. Install Dependencies

```bash
# PHP dependencies
composer install

# Node.js dependencies
npm install
```

### 3. Environment Setup

```bash
# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate
```

### 4. Configure Environment Variables

Edit `.env` file with your credentials:

```bash
# Database Configuration (Supabase)
DATABASE_URL=postgresql://postgres.xxx:password@aws-0-ap-southeast-1.pooler.supabase.com:6543/postgres
DB_CONNECTION=pgsql
DB_HOST=aws-0-ap-southeast-1.pooler.supabase.com
DB_PORT=6543
DB_DATABASE=postgres
DB_USERNAME=postgres.xxx
DB_PASSWORD=your-supabase-password

# Redis Configuration (Redis Cloud)
REDIS_URL=redis://default:password@redis-xxxxx.xxx.xxx.cloud.redislabs.com:port
QUEUE_CONNECTION=redis

# Google Gemini API
GEMINI_API_KEY=your-gemini-api-key-here

# ChromaDB Cloud Configuration
CHROMADB_API_KEY=your-chromadb-api-key
CHROMADB_TENANT=your-tenant-id
CHROMADB_DATABASE=ai-cv-evaluator
CHROMADB_PYTHON_PATH=python3
```

### 5. Database Setup

```bash
# Run migrations
php artisan migrate

# Optional: Seed with sample data
php artisan db:seed
```

### 6. ChromaDB Setup (Required for RAG Enhancement)

This system implements **Retrieval-Augmented Generation (RAG)** for enhanced AI analysis. ChromaDB provides contextual evaluation through vector similarity search.

```bash
# 1. Sign up at https://www.trychroma.com/ and create a database
# 2. Get your credentials from ChromaDB dashboard
# 3. Add to .env file:
CHROMADB_API_KEY=your-api-key-here
CHROMADB_TENANT=your-tenant-id-here
CHROMADB_DATABASE=ai-cv-evaluator
CHROMADB_PYTHON_PATH=python3

# 4. Create collections and add sample data:
# You'll need to manually create these collections in ChromaDB:
# - job_descriptions (for job matching)
# - cv_evaluation_rubrics (for CV scoring criteria)  
# - project_evaluation_rubrics (for project assessment)
# - case_study_brief (for project requirements)

# 5. Test ChromaDB connection
php artisan test:chromadb
```

**Note**: The RAG system enhances evaluation accuracy by retrieving relevant job descriptions and evaluation criteria for contextual AI analysis.

### 7. Test Gemini Integration

```bash
# Test Gemini API connection and functionality
php artisan test:gemini
```

## 🏃‍♂️ Running the Application

### Development Mode

**Terminal 1 - Laravel Server:**
```bash
php artisan serve
```

**Terminal 2 - Queue Worker:**
```bash
php artisan queue:work redis --tries=3 --timeout=300
```

## 📡 API Endpoints

### 1. Upload Files

```bash
POST /api/upload
Content-Type: multipart/form-data

# Upload CV only
curl -X POST http://localhost:8000/api/upload \
  -F "cv=@path/to/cv.pdf"

# Upload CV and Project Report
curl -X POST http://localhost:8000/api/upload \
  -F "cv=@path/to/cv.pdf" \
  -F "project_report=@path/to/project.pdf"
```

### 2. Start Evaluation

```bash
POST /api/evaluate
Content-Type: application/json

curl -X POST http://localhost:8000/api/evaluate \
  -H "Content-Type: application/json" \
  -d '{
    "job_title": "Backend Developer",
    "cv_upload_id": 1,
    "project_report_upload_id": 2
  }'
```

### 3. Get Results

```bash
GET /api/result/{job_id}

curl http://localhost:8000/api/result/550e8400-e29b-41d4-a716-446655440000
```

## 🧪 Testing

### Run Tests

```bash
# Run all tests (Feature + Unit)
php artisan test

# Run specific test suites
php artisan test --testsuite=Feature  # API endpoint tests
php artisan test --testsuite=Unit     # Service class tests

# Run with coverage (requires Xdebug)
php artisan test --coverage
```

### Available Test Files

**Feature Tests** (`tests/Feature/`):
- `EvaluationTest.php` - Test evaluation API endpoints
- `UploadTest.php` - Test file upload functionality

**Unit Tests** (`tests/Unit/`):
- `GeminiServiceTest.php` - Test AI integration and response parsing
- `PdfTextExtractorTest.php` - Test PDF text extraction

### Manual Integration Testing

```bash
# Test Gemini API integration
php artisan test:gemini

# Setup and test ChromaDB connection
php artisan setup:chromadb
```

## 🔧 Configuration

### Queue Worker Configuration

**Background Job Processing** (required for AI evaluation):

```bash
# Production-optimized queue worker
php artisan queue:work redis \
  --tries=3 \           # Max 3 attempts (AI API resilience)
  --timeout=300 \       # 5min timeout (AI + PDF + ChromaDB processing)
  --memory=512 \        # 512MB limit (PDF parsing + vector operations)
  --sleep=3 \           # 3s sleep (external API rate limiting)
  --max-jobs=100        # Restart after 100 jobs (memory leak prevention)
```

**Settings Source:**
- `ProcessEvaluationJob.php`: `$tries=3`, `$timeout=300` 
- `config/queue.php`: Redis connection (`QUEUE_CONNECTION=redis`)
- Production best practices for AI workloads


## 📊 API Examples

### Complete Evaluation Flow

```bash
# 1. Upload files
UPLOAD_RESPONSE=$(curl -s -X POST http://localhost:8000/api/upload \
  -F "cv=@sample_cv.pdf" \
  -F "project_report=@sample_project.pdf")

CV_ID=$(echo $UPLOAD_RESPONSE | jq -r '.data.cv.id')
PROJECT_ID=$(echo $UPLOAD_RESPONSE | jq -r '.data.project_report.id')

# 2. Start evaluation
EVAL_RESPONSE=$(curl -s -X POST http://localhost:8000/api/evaluate \
  -H "Content-Type: application/json" \
  -d "{
    \"job_title\": \"Senior Laravel Developer\",
    \"cv_upload_id\": $CV_ID,
    \"project_report_upload_id\": $PROJECT_ID
  }")

JOB_ID=$(echo $EVAL_RESPONSE | jq -r '.data.id')

# 3. Wait for completion and get results
sleep 30
curl http://localhost:8000/api/result/$JOB_ID | jq .
```

## 🔍 Troubleshooting

### Common Issues

**1. ChromaDB Connection Failed:**
```bash
# Check ChromaDB credentials
python3 -c "
import chromadb
client = chromadb.CloudClient(
    api_key='your-api-key',
    tenant='your-tenant-id',
    database='ai-cv-evaluator'
)
print('ChromaDB connected:', len(client.list_collections()))
"
```

**2. Gemini API Errors:**
```bash
# Test Gemini API directly
curl -X POST https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=YOUR_API_KEY \
  -H "Content-Type: application/json" \
  -d '{"contents":[{"parts":[{"text":"Hello"}]}]}'
```

**3. Queue Worker Not Processing:**
```bash
# Check Redis connection
redis-cli -u $REDIS_URL ping

# Clear failed jobs
php artisan queue:flush

# Restart queue worker
php artisan queue:restart
```

**4. PDF Processing Issues:**
```bash
# Test PDF extraction
pdftotext -v
php artisan test:pdf-extraction
```

### Logs

```bash
# Application logs
tail -f storage/logs/laravel.log

# Queue logs
php artisan pail --filter=queue

# Error logs only
tail -f storage/logs/laravel.log | grep ERROR
```

## 📈 Performance Optimization

### Production Settings

```bash
# Optimize for production
composer install --optimize-autoloader --no-dev
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Queue optimization
php artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
```

### Scaling

- **Queue Workers**: Run multiple workers for high throughput
- **Database**: Use connection pooling (pgbouncer)
- **Redis**: Use Redis Cluster for high availability
- **AI Processing**: Implement rate limiting for API calls

## 🤝 Contributing

1. Fork the repository
2. Create feature branch (`git checkout -b feature/amazing-feature`)
3. Commit changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open Pull Request
