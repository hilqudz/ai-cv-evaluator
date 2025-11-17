<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;

class GeminiServiceTest extends TestCase
{
    use RefreshDatabase;

    private GeminiService $geminiService;

    public function setUp(): void
    {
        parent::setUp();
        
        // Mock Gemini API configuration
        Config::set('services.gemini.api_key', 'test_api_key');
        Config::set('services.gemini.base_url', 'https://api.gemini.google.com');
        
        $this->geminiService = new GeminiService();
    }

    /**
     * Test successful CV analysis
     */
    public function test_successful_cv_analysis(): void
    {
        $cvText = "John Doe\nSoftware Developer\nLaravel, PHP, JavaScript\n5 years experience";
        $jobTitle = "Senior Laravel Developer";
        $jobDescription = "Looking for a Senior Laravel Developer with 3+ years experience";

        // Mock successful API response
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'overall_score' => 8.5,
                                        'strengths' => ['Laravel expertise', 'Good experience'],
                                        'weaknesses' => ['No mention of testing'],
                                        'recommendations' => ['Add testing skills'],
                                        'fit_assessment' => 'Strong match for the position',
                                        'detailed_analysis' => [
                                            'technical_skills' => 9,
                                            'experience_level' => 8,
                                            'role_alignment' => 8
                                        ]
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $result = $this->geminiService->analyzeCv($cvText, $jobTitle, $jobDescription);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('overall_score', $result);
        $this->assertArrayHasKey('strengths', $result);
        $this->assertArrayHasKey('weaknesses', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertEquals(8.5, $result['overall_score']);
    }

    /**
     * Test API error handling
     */
    public function test_api_error_handling(): void
    {
        $cvText = "Test CV content";
        $jobTitle = "Test position";
        $jobDescription = "Test job description";

        // Mock API error response
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'message' => 'API rate limit exceeded',
                    'code' => 429
                ]
            ], 429)
        ]);

        $this->expectException(\Exception::class);
        $this->geminiService->analyzeCv($cvText, $jobTitle, $jobDescription);
    }

    /**
     * Test malformed API response handling
     */
    public function test_malformed_response_handling(): void
    {
        $cvText = "Test CV content";
        $jobTitle = "Test position";
        $jobDescription = "Test job description";

        // Mock malformed response
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => 'Invalid JSON content'
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $this->expectException(\Exception::class);
        $this->geminiService->analyzeCv($cvText, $jobTitle, $jobDescription);
    }

    /**
     * Test empty CV text handling
     */
    public function test_empty_cv_text(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geminiService->analyzeCv('', 'Some job title', 'Some job description');
    }

    /**
     * Test empty job description handling
     */
    public function test_empty_job_description(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->geminiService->analyzeCv('Some CV text', 'Some job title', '');
    }

    /**
     * Test prompt generation
     */
    public function test_prompt_generation(): void
    {
        $cvText = "John Doe, Laravel Developer";
        $jobTitle = "Senior PHP Developer";
        $jobDescription = "Senior PHP Developer position";

        // Use reflection to test private method
        $reflection = new \ReflectionClass($this->geminiService);
        $method = $reflection->getMethod('buildAnalysisPrompt');
        $method->setAccessible(true);

        $prompt = $method->invokeArgs($this->geminiService, [$cvText, $jobTitle, $jobDescription]);

        $this->assertIsString($prompt);
        $this->assertStringContainsString($cvText, $prompt);
        $this->assertStringContainsString($jobDescription, $prompt);
        $this->assertStringContainsString('overall_score', $prompt);
    }

    /**
     * Test response validation
     */
    public function test_response_validation(): void
    {
        $validResponse = [
            'overall_score' => 8.0,
            'strengths' => ['Laravel', 'PHP'],
            'weaknesses' => ['Testing'],
            'recommendations' => ['Add testing'],
            'fit_assessment' => 'Good match'
        ];

        $invalidResponse = [
            'score' => 8.0, // Missing 'overall_score'
            'strengths' => ['Laravel']
            // Missing required fields
        ];

        // Use reflection to test private validation method
        $reflection = new \ReflectionClass($this->geminiService);
        $method = $reflection->getMethod('validateResponse');
        $method->setAccessible(true);

        $this->assertTrue($method->invokeArgs($this->geminiService, [$validResponse]));
        $this->assertFalse($method->invokeArgs($this->geminiService, [$invalidResponse]));
    }

    /**
     * Test retry mechanism on temporary failures
     */
    public function test_retry_mechanism(): void
    {
        $cvText = "Test CV";
        $jobDescription = "Test job";

        // Mock temporary failure followed by success
        Http::fake([
            '*' => Http::sequence()
                ->push(['error' => ['message' => 'Temporary error']], 500)
                ->push(['error' => ['message' => 'Still failing']], 500)
                ->push([
                    'candidates' => [
                        [
                            'content' => [
                                'parts' => [
                                    [
                                        'text' => json_encode([
                                            'overall_score' => 7.5,
                                            'strengths' => ['Good skills'],
                                            'weaknesses' => ['Some gaps'],
                                            'recommendations' => ['Improve X'],
                                            'fit_assessment' => 'Decent match'
                                        ])
                                    ]
                                ]
                            ]
                        ]
                    ]
                ], 200)
        ]);

        $result = $this->geminiService->analyzeCv($cvText, 'Test Job', $jobDescription);

        $this->assertIsArray($result);
        $this->assertEquals(7.5, $result['overall_score']);
    }

    /**
     * Test API timeout handling
     */
    public function test_timeout_handling(): void
    {
        $cvText = "Test CV content";
        $jobTitle = "Test Job";
        $jobDescription = "Test job description";

        // Mock timeout
        Http::fake([
            '*' => function () {
                throw new \GuzzleHttp\Exception\RequestException(
                    'Request timeout',
                    new \GuzzleHttp\Psr7\Request('POST', 'test')
                );
            }
        ]);

        $this->expectException(\Exception::class);
        $this->geminiService->analyzeCv($cvText, $jobTitle, $jobDescription);
    }

    /**
     * Test large CV text handling
     */
    public function test_large_cv_text(): void
    {
        $largeCvText = str_repeat("Experience: Laravel Developer. ", 1000);
        $jobDescription = "Laravel position";

        // Mock successful response for large content
        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'overall_score' => 6.0,
                                        'strengths' => ['Extensive experience'],
                                        'weaknesses' => ['Repetitive content'],
                                        'recommendations' => ['Diversify skills'],
                                        'fit_assessment' => 'Adequate match'
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $result = $this->geminiService->analyzeCv($largeCvText, 'Laravel Developer', $jobDescription);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('overall_score', $result);
    }

    /**
     * Test special characters in CV text
     */
    public function test_special_characters(): void
    {
        $cvWithSpecialChars = "João Silva\n★ Senior Developer ★\n• Laravel Expert\n→ 5+ years";
        $jobDescription = "Senior Laravel Developer";

        Http::fake([
            '*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [
                                [
                                    'text' => json_encode([
                                        'overall_score' => 9.0,
                                        'strengths' => ['Strong technical skills'],
                                        'weaknesses' => ['None identified'],
                                        'recommendations' => ['Continue growth'],
                                        'fit_assessment' => 'Excellent match'
                                    ])
                                ]
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $result = $this->geminiService->analyzeCv($cvWithSpecialChars, 'Senior Laravel Developer', $jobDescription);

        $this->assertIsArray($result);
        $this->assertEquals(9.0, $result['overall_score']);
    }

    /**
     * Test configuration validation
     */
    public function test_configuration_validation(): void
    {
        // Test missing API key
        Config::set('services.gemini.api_key', null);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Gemini API key not configured');
        
        new GeminiService();
    }

    protected function tearDown(): void
    {
        Http::flush();
        parent::tearDown();
    }
}