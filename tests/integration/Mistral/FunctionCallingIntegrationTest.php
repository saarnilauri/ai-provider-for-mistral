<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
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
     * Tests function calling with no arguments.
     */
    public function testFunctionCallingWithNoArguments(): void
    {
        $sayHi = new FunctionDeclaration(
            'say_hi',
            'Says hi to the user. Call this function when the user asks for a greeting.',
            null
        );

        $result = AiClient::prompt('Please greet me. Use the say_hi function. Do not answer directly.', $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($sayHi)
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $functionCall = $this->extractFunctionCall($result);
        $this->assertNotNull($functionCall, 'Expected a function call in the response');
        $this->assertSame('say_hi', $functionCall->getName());
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
     * Tests function calling with multiple function declarations.
     */
    public function testMultipleFunctionDeclarations(): void
    {
        $getWeather = new FunctionDeclaration(
            'get_weather',
            'Get weather for a location',
            ['type' => 'object', 'properties' => ['location' => ['type' => 'string']], 'required' => ['location']]
        );

        $getTime = new FunctionDeclaration(
            'get_time',
            'Get current time in a timezone',
            ['type' => 'object', 'properties' => ['timezone' => ['type' => 'string']], 'required' => ['timezone']]
        );

        $searchWeb = new FunctionDeclaration(
            'search_web',
            'Search the web for information',
            ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]
        );

        // Prompt should trigger get_time specifically
        $result = AiClient::prompt(
            'What time is it in London right now? Use the get_time function.',
            $this->registry
        )
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($getWeather, $getTime, $searchWeb)
            ->generateTextResult();

        $functionCall = $this->extractFunctionCall($result);
        $this->assertNotNull($functionCall, 'Expected a function call');
        $this->assertSame('get_time', $functionCall->getName(), 'Expected get_time to be selected');

        $args = $functionCall->getArgs();
        $this->assertIsArray($args);
        $this->assertArrayHasKey('timezone', $args);
        $this->assertStringContainsStringIgnoringCase('london', $args['timezone']);
    }

    /**
     * Tests function calling with optional parameters.
     */
    public function testFunctionCallingWithOptionalParameters(): void
    {
        $searchProducts = new FunctionDeclaration(
            'search_products',
            'Search for products in the catalog',
            [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query'],
                    'category' => ['type' => 'string', 'description' => 'Optional category filter'],
                    'max_price' => ['type' => 'number', 'description' => 'Optional max price'],
                ],
                'required' => ['query'],
            ]
        );

        $result = AiClient::prompt('Find me some laptops', $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($searchProducts)
            ->generateTextResult();

        $functionCall = $this->extractFunctionCall($result);
        $this->assertNotNull($functionCall, 'Expected a function call');
        $this->assertSame('search_products', $functionCall->getName());

        $args = $functionCall->getArgs();
        $this->assertIsArray($args);
        $this->assertArrayHasKey('query', $args);
        $this->assertStringContainsStringIgnoringCase('laptop', $args['query']);
    }

    /**
     * Tests function calling with nested object parameters.
     */
    public function testFunctionCallingWithNestedParameters(): void
    {
        $createOrder = new FunctionDeclaration(
            'create_order',
            'Create an order with shipping address',
            [
                'type' => 'object',
                'properties' => [
                    'product' => ['type' => 'string', 'description' => 'Product name'],
                    'shipping_address' => [
                        'type' => 'object',
                        'properties' => [
                            'street' => ['type' => 'string'],
                            'city' => ['type' => 'string'],
                            'zip' => ['type' => 'string'],
                        ],
                        'required' => ['street', 'city'],
                    ],
                ],
                'required' => ['product', 'shipping_address'],
            ]
        );

        $result = AiClient::prompt(
            'Order a book and ship it to 123 Main St, New York, 10001',
            $this->registry
        )
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($createOrder)
            ->generateTextResult();

        $functionCall = $this->extractFunctionCall($result);
        $this->assertNotNull($functionCall, 'Expected a function call');
        $this->assertSame('create_order', $functionCall->getName());

        $args = $functionCall->getArgs();
        $this->assertIsArray($args);
        $this->assertArrayHasKey('shipping_address', $args);
        $this->assertIsArray($args['shipping_address']);
        $this->assertArrayHasKey('city', $args['shipping_address']);
        $this->assertStringContainsStringIgnoringCase('new york', $args['shipping_address']['city']);
    }

    /**
     * Tests function calling with array parameters.
     */
    public function testFunctionCallingWithArrayParameters(): void
    {
        $addToCart = new FunctionDeclaration(
            'add_to_cart',
            'Add multiple items to shopping cart',
            [
                'type' => 'object',
                'properties' => [
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'quantity' => ['type' => 'integer'],
                            ],
                            'required' => ['name', 'quantity'],
                        ],
                        'description' => 'List of items to add',
                    ],
                ],
                'required' => ['items'],
            ]
        );

        $result = AiClient::prompt('Add 2 apples and 3 oranges to my cart', $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($addToCart)
            ->generateTextResult();

        $functionCall = $this->extractFunctionCall($result);
        $this->assertNotNull($functionCall, 'Expected a function call');
        $this->assertSame('add_to_cart', $functionCall->getName());

        $args = $functionCall->getArgs();
        $this->assertIsArray($args);
        $this->assertArrayHasKey('items', $args);
        $this->assertIsArray($args['items']);
        $this->assertCount(2, $args['items']);

        foreach ($args['items'] as $item) {
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('quantity', $item);
            $this->assertIsInt($item['quantity']);
        }
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
