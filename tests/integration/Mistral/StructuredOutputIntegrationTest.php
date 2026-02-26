<?php

declare(strict_types=1);

namespace AiProviderForMistral\Tests\Integration\Mistral;

use AiProviderForMistral\Provider\ProviderForMistral;
use AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Integration tests for Mistral structured output.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * @group integration
 * @group mistral
 * @group structured-output
 *
 * @coversNothing
 */
class StructuredOutputIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);
    }

    /**
     * Tests structured output in basic JSON mode (no schema).
     */
    public function testStructuredOutputWithJsonMode(): void
    {
        $result = AiClient::prompt(
            'Return a JSON object with a single key "answer" whose value is the string "42".',
            $this->registry
        )
            ->usingProvider('mistral')
            ->asJsonResponse()
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $text = $result->toText();
        $this->assertNotEmpty($text);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, 'Response should be valid JSON. Got: ' . $text);
    }

    /**
     * Tests structured output with an explicit JSON schema.
     */
    public function testStructuredOutputWithJsonSchema(): void
    {
        $schema = [
            'name'   => 'city_info',
            'strict' => true,
            'schema' => [
                'type'                 => 'object',
                'properties'           => [
                    'city'       => [
                        'type'        => 'string',
                        'description' => 'Name of the city',
                    ],
                    'country'    => [
                        'type'        => 'string',
                        'description' => 'Name of the country',
                    ],
                    'population' => [
                        'type'        => 'integer',
                        'description' => 'Approximate population of the city',
                    ],
                ],
                'required'             => ['city', 'country', 'population'],
                'additionalProperties' => false,
            ],
        ];

        $result = AiClient::prompt(
            'Provide information about Paris, France. Use an approximate population of 2000000.',
            $this->registry
        )
            ->usingProvider('mistral')
            ->asJsonResponse($schema)
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $text = $result->toText();
        $this->assertNotEmpty($text);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, 'Response should be valid JSON. Got: ' . $text);

        $this->assertArrayHasKey('city', $decoded);
        $this->assertArrayHasKey('country', $decoded);
        $this->assertArrayHasKey('population', $decoded);

        $this->assertIsString($decoded['city']);
        $this->assertIsString($decoded['country']);
        $this->assertIsInt($decoded['population']);

        $this->assertStringContainsStringIgnoringCase('paris', $decoded['city']);
        $this->assertStringContainsStringIgnoringCase('france', $decoded['country']);
    }

    /**
     * Tests that a raw JSON schema (no 'name' key) is accepted without a 422.
     *
     * Mistral requires a 'name' field inside the json_schema object. The provider
     * automatically injects a default name when the caller omits it.
     */
    public function testStructuredOutputWithRawJsonSchema(): void
    {
        $schema = [
            'type'       => 'object',
            'properties' => [
                'answer' => [
                    'type'        => 'string',
                    'description' => 'The answer',
                ],
            ],
            'required'             => ['answer'],
            'additionalProperties' => false,
        ];

        $result = AiClient::prompt(
            'Reply with a JSON object. Set "answer" to the string "42".',
            $this->registry
        )
            ->usingProvider('mistral')
            ->asJsonResponse($schema)
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $text = $result->toText();
        $this->assertNotEmpty($text);

        $decoded = json_decode($text, true);
        $this->assertIsArray($decoded, 'Response should be valid JSON. Got: ' . $text);
        $this->assertArrayHasKey('answer', $decoded);
        $this->assertIsString($decoded['answer']);
    }
}
