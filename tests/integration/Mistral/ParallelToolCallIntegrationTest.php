<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * Integration tests for parallel tool calling.
 *
 * Reproduces WordPress/php-ai-client#286: when a model answers with more than one
 * tool call in a single turn, the tool results have to be sent back to the model.
 * WordPress core bundles them into one message — see
 * `WP_AI_Client_Ability_Function_Resolver::execute_abilities()`, which returns a single
 * `UserMessage` holding one function-response `MessagePart` per executed ability.
 *
 * `AbstractOpenAiCompatibleTextGenerationModel::prepareMessagesParam()` only special-cases
 * a message that holds *exactly one* function-response part. With two or more parts it
 * falls through to the generic branch, where `getMessagePartContentData()` throws
 * "The API only allows a single function response, as the only content of the message."
 *
 * These tests describe the behaviour we want: a message carrying several function
 * responses should be sent as one `role: tool` entry per response, which is what the
 * OpenAI-compatible chat completions API expects. They therefore FAIL until the
 * upstream SDK is fixed.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * @group integration
 * @group mistral
 * @group function-calling
 * @group parallel-tool-calls
 *
 * @coversNothing
 */
class ParallelToolCallIntegrationTest extends TestCase
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
     * Reproduces issue #286 the way WordPress core hits it.
     *
     * Mirrors the agentic loop in core: prompt with abilities as tools, execute every
     * returned function call, hand all results back in a single user message, prompt again.
     */
    public function testParallelToolResultsInOneMessageCanBeSentBack(): void
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

        $getTime = new FunctionDeclaration(
            'get_time',
            'Get the current local time for a location',
            [
                'type' => 'object',
                'properties' => [
                    'location' => ['type' => 'string', 'description' => 'City name'],
                ],
                'required' => ['location'],
            ]
        );

        $task = 'What is the weather and the current local time in Tokyo? '
            . 'Call both get_weather and get_time before answering.';

        // Turn 1: the model should answer with two tool calls in a single message.
        $result1 = AiClient::prompt($task, $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($getWeather, $getTime)
            ->generateTextResult();

        $modelMessage = $result1->toMessage();
        $functionCalls = $this->extractFunctionCalls($modelMessage);

        if (count($functionCalls) < 2) {
            $this->markTestSkipped(
                sprintf(
                    'The model returned %d tool call(s); this test needs a parallel tool call to be meaningful.',
                    count($functionCalls)
                )
            );
        }

        // Turn 2: execute every call and bundle the results into ONE user message, exactly
        // like WP_AI_Client_Ability_Function_Resolver::execute_abilities() does.
        $responseParts = [];
        foreach ($functionCalls as $functionCall) {
            $responseParts[] = new MessagePart(
                new FunctionResponse(
                    $functionCall->getId() ?? 'call_' . $functionCall->getName(),
                    (string) $functionCall->getName(),
                    $this->fakeToolResult((string) $functionCall->getName())
                )
            );
        }

        $this->assertCount(
            count($functionCalls),
            $responseParts,
            'Expected one function response part per function call'
        );

        $messages = [
            new UserMessage([new MessagePart($task)]),
            new ModelMessage($this->sendableParts($modelMessage)),
            new UserMessage($responseParts),
        ];

        // This test makes two back-to-back chat completion calls; pace the second
        // one to stay under the provider's per-second rate limit.
        $this->throttle();

        // Before the fix this throws InvalidArgumentException:
        // "The API only allows a single function response, as the only content of the message."
        $result2 = AiClient::prompt($messages, $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($getWeather, $getTime)
            ->generateTextResult();

        $responseText = $result2->toText();
        $this->assertNotEmpty($responseText, 'Expected a text response that uses both tool results');
        $this->assertTrue(
            stripos($responseText, '22') !== false || stripos($responseText, 'sunny') !== false,
            'Expected the model to use the get_weather result. Got: ' . $responseText
        );
        $this->assertTrue(
            stripos($responseText, '14:30') !== false || stripos($responseText, '2:30') !== false,
            'Expected the model to use the get_time result. Got: ' . $responseText
        );
    }

    /**
     * Same defect, reached through the SDK's own prompt builder.
     *
     * `PromptBuilder::withFunctionResponse()` appends to the current user message, so two
     * calls produce one message holding two function-response parts — the same unsendable
     * shape as above, without any WordPress code involved.
     */
    public function testTwoFunctionResponsesViaPromptBuilderCanBeSentBack(): void
    {
        $getWeather = new FunctionDeclaration(
            'get_weather',
            'Get the current weather for a location',
            [
                'type' => 'object',
                'properties' => ['location' => ['type' => 'string']],
                'required' => ['location'],
            ]
        );

        $getTime = new FunctionDeclaration(
            'get_time',
            'Get the current local time for a location',
            [
                'type' => 'object',
                'properties' => ['location' => ['type' => 'string']],
                'required' => ['location'],
            ]
        );

        $task = 'What is the weather and the current local time in Tokyo? '
            . 'Call both get_weather and get_time before answering.';

        $result1 = AiClient::prompt($task, $this->registry)
            ->usingProvider('mistral')
            ->usingFunctionDeclarations($getWeather, $getTime)
            ->generateTextResult();

        $modelMessage = $result1->toMessage();
        $functionCalls = $this->extractFunctionCalls($modelMessage);

        if (count($functionCalls) < 2) {
            $this->markTestSkipped(
                sprintf(
                    'The model returned %d tool call(s); this test needs a parallel tool call to be meaningful.',
                    count($functionCalls)
                )
            );
        }

        $builder = AiClient::prompt(null, $this->registry)
            ->usingProvider('mistral')
            ->withHistory(
                new UserMessage([new MessagePart($task)]),
                new ModelMessage($this->sendableParts($modelMessage))
            )
            ->usingFunctionDeclarations($getWeather, $getTime);

        foreach ($functionCalls as $functionCall) {
            $builder->withFunctionResponse(
                new FunctionResponse(
                    $functionCall->getId() ?? 'call_' . $functionCall->getName(),
                    (string) $functionCall->getName(),
                    $this->fakeToolResult((string) $functionCall->getName())
                )
            );
        }

        $this->throttle();

        $result2 = $builder->generateTextResult();

        $this->assertNotEmpty(
            $result2->toText(),
            'Expected a text response that uses both tool results'
        );
    }

    /**
     * Returns every function call in a message, in order.
     *
     * @return list<FunctionCall>
     */
    private function extractFunctionCalls(Message $message): array
    {
        $functionCalls = [];
        foreach ($message->getParts() as $part) {
            if ($part->getType()->isFunctionCall()) {
                $functionCall = $part->getFunctionCall();
                if ($functionCall instanceof FunctionCall) {
                    $functionCalls[] = $functionCall;
                }
            }
        }

        return $functionCalls;
    }

    /**
     * Strips parts that cannot be sent back to the provider.
     *
     * Thought parts are for display only; providers reject or ignore them as input.
     *
     * @return list<MessagePart>
     */
    private function sendableParts(Message $message): array
    {
        $parts = [];
        foreach ($message->getParts() as $part) {
            if ($part->getChannel()->isThought()) {
                continue;
            }
            $parts[] = $part;
        }

        return $parts;
    }

    /**
     * Canned tool results, so the assertions can look for known values.
     *
     * @return array<string, mixed>
     */
    private function fakeToolResult(string $functionName): array
    {
        if ($functionName === 'get_time') {
            return ['time' => '14:30', 'timezone' => 'Asia/Tokyo'];
        }

        return ['temperature' => 22, 'unit' => 'celsius', 'condition' => 'sunny'];
    }
}
