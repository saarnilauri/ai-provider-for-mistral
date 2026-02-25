<?php

declare(strict_types=1);

namespace AiProviderForMistral\Tests\Integration\Mistral;

use AiProviderForMistral\Provider\ProviderForMistral;
use AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Integration tests for Mistral function calling.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * @group integration
 * @group mistral
 * @group function-calling
 *
 * @coversNothing
 */
class FunctionCallingIntegrationTest extends TestCase
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
     * Tests function calling with multiple arguments.
     */
    public function testFunctionCallingWithMultipleArguments(): void
    {
        $getWeather = new FunctionDeclaration(
            'get_weather',
            'Get the current weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => [
                        'type' => 'string',
                        'description' => 'The city and country, e.g. Paris, France',
                    ],
                    'unit' => [
                        'type' => 'string',
                        'enum' => ['celsius', 'fahrenheit'],
                        'description' => 'The temperature unit',
                    ],
                ],
                'required' => ['location', 'unit'],
            ]
        );

        $result = AiClient::prompt(
            'Call get_weather for Paris, France using celsius. Do not answer directly.',
            $this->registry
        )
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($getWeather)
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $functionCall = $this->extractFunctionCall($result);
        $this->assertNotNull($functionCall, 'Expected a function call in the response');
        $this->assertSame('get_weather', $functionCall->getName());

        $args = $functionCall->getArgs();
        $this->assertIsArray($args);
        $this->assertArrayHasKey('location', $args);
        $this->assertArrayHasKey('unit', $args);
        $this->assertStringContainsStringIgnoringCase('paris', $args['location']);
        $this->assertSame('celsius', $args['unit']);
    }

    /**
     * Tests multi-turn function calling with function response.
     */
    public function testMultiTurnFunctionCalling(): void
    {
        $getWeather = new FunctionDeclaration(
            'get_weather',
            'Get the current weather for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['location'],
            ]
        );

        $result1 = AiClient::prompt('Call get_weather for Tokyo. Do not answer directly.', $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($getWeather)
            ->generateTextResult();

        $functionCall = $this->extractFunctionCall($result1);
        $this->assertNotNull($functionCall, 'Expected a function call in the response');
        $this->assertSame('get_weather', $functionCall->getName());

        $userMessage = new UserMessage([new MessagePart('What is the weather in Tokyo?')]);
        $assistantMessage = $result1->getCandidates()[0]->getMessage();

        $functionResponse = new FunctionResponse(
            $functionCall->getId() ?? 'call_123',
            'get_weather',
            ['temperature' => 22, 'condition' => 'sunny']
        );

        $result2 = AiClient::prompt(null, $this->registry)
            ->usingProvider('mistral')
            ->withHistory($userMessage, $assistantMessage)
            ->withFunctionResponse($functionResponse)
            ->usingFunctionDeclarations($getWeather)
            ->generateTextResult();

        $responseText = $result2->toText();
        $this->assertNotEmpty($responseText, 'Expected a text response');
        $this->assertTrue(
            stripos($responseText, '22') !== false ||
            stripos($responseText, 'sunny') !== false ||
            stripos($responseText, 'Tokyo') !== false,
            'Expected model to use function result in response. Got: ' . $responseText
        );
    }

    /**
     * Extracts the first function call from a result.
     *
     * @param GenerativeAiResult $result The result to extract from.
     * @return FunctionCall|null The function call or null if not found.
     */
    private function extractFunctionCall(GenerativeAiResult $result): ?FunctionCall
    {
        $candidates = $result->getCandidates();
        if (empty($candidates)) {
            return null;
        }

        $message = $candidates[0]->getMessage();
        foreach ($message->getParts() as $part) {
            if ($part->getType()->isFunctionCall()) {
                return $part->getFunctionCall();
            }
        }

        return null;
    }
}
