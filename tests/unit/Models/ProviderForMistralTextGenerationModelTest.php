<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;

/**
 * @covers \SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextGenerationModel
 */
class ProviderForMistralTextGenerationModelTest extends TestCase
{
    /**
     * @var ModelMetadata&\PHPUnit\Framework\MockObject\MockObject
     */
    private $modelMetadata;

    /**
     * @var ProviderMetadata&\PHPUnit\Framework\MockObject\MockObject
     */
    private $providerMetadata;

    /**
     * @var HttpTransporterInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockHttpTransporter;

    /**
     * @var RequestAuthenticationInterface&\PHPUnit\Framework\MockObject\MockObject
     */
    private $mockRequestAuthentication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelMetadata = $this->createStub(ModelMetadata::class);
        $this->modelMetadata->method('getId')->willReturn('mistral-large-latest');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('AI Provider for Mistral');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);
    }

    /**
     * Creates a mock instance of ProviderForMistralTextGenerationModel.
     *
     * @param ModelConfig|null $modelConfig
     * @return MockProviderForMistralTextGenerationModel
     */
    private function createModel(?ModelConfig $modelConfig = null): MockProviderForMistralTextGenerationModel
    {
        $model = new MockProviderForMistralTextGenerationModel(
            $this->modelMetadata,
            $this->providerMetadata,
            $this->mockHttpTransporter,
            $this->mockRequestAuthentication
        );

        if ($modelConfig) {
            $model->setConfig($modelConfig);
        }

        return $model;
    }

    /**
     * Tests generateTextResult() method on success.
     */
    public function testGenerateTextResultSuccess(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'chatcmpl_123',
                'choices' => [
                    [
                        'message' => [
                            'role' => 'assistant',
                            'content' => 'Hi there!',
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 10,
                    'completion_tokens' => 5,
                    'total_tokens' => 15,
                ],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();
        $result = $model->generateTextResult($prompt);

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('chatcmpl_123', $result->getId());
        $this->assertCount(1, $result->getCandidates());
        $this->assertSame('Hi there!', $result->getCandidates()[0]->getMessage()->getParts()[0]->getText());
        $this->assertEquals(FinishReasonEnum::stop(), $result->getCandidates()[0]->getFinishReason());
        $this->assertSame(10, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(5, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(15, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests generateTextResult() method on API failure.
     */
    public function testGenerateTextResultApiFailure(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(401, [], '{"message": "Invalid API key"}');

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Unauthorized (401) - Invalid API key');

        $model->generateTextResult($prompt);
    }

    /**
     * Tests that generateTextResult() throws TokenLimitReachedException when finish_reason is "length".
     */
    public function testGenerateTextResultThrowsOnLengthFinishReason(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'chatcmpl_123',
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => 'Truncated...'],
                        'finish_reason' => 'length',
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 100, 'total_tokens' => 110],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $config = new ModelConfig();
        $config->setMaxTokens(100);
        $model = $this->createModel($config);

        $this->expectException(TokenLimitReachedException::class);
        $this->expectExceptionMessage('Generation stopped due to token limit (100) with finish reason "length".');

        $model->generateTextResult($prompt);
    }

    /**
     * Tests that generateTextResult() throws TokenLimitReachedException without max tokens in message
     * when no max_tokens is configured.
     */
    public function testGenerateTextResultThrowsOnLengthFinishReasonWithoutMaxTokens(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'chatcmpl_123',
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => 'Truncated...'],
                        'finish_reason' => 'length',
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 100, 'total_tokens' => 110],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        $model = $this->createModel();

        $exception = null;
        try {
            $model->generateTextResult($prompt);
        } catch (TokenLimitReachedException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(TokenLimitReachedException::class, $exception);
        $this->assertNull($exception->getMaxTokens());
        $this->assertStringContainsString('"length"', $exception->getMessage());
    }

    /**
     * Tests that remote document files are serialized as Mistral's document_url content chunk.
     */
    public function testDocumentPartSerializationEmitsDocumentUrl(): void
    {
        $model = $this->createModel();
        $file = new File('https://example.com/report.pdf', 'application/pdf');
        $part = new MessagePart($file);

        $data = $model->exposeGetMessagePartContentData($part);

        $this->assertSame(
            [
                'type'         => 'document_url',
                'document_url' => 'https://example.com/report.pdf',
            ],
            $data
        );
    }

    /**
     * Tests that inline (non-remote) document files throw a clear error.
     */
    public function testInlineDocumentPartThrows(): void
    {
        $model = $this->createModel();
        $file = new File(
            'data:application/pdf;base64,' . base64_encode('%PDF-1.4 fake'),
            'application/pdf'
        );
        $part = new MessagePart($file);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mistral chat requires document files to be provided as a URL.');

        $model->exposeGetMessagePartContentData($part);
    }

    /**
     * Tests that generateTextResult() throws TokenLimitReachedException when finish_reason
     * is "model_length", which Mistral uses for its own context limit.
     */
    public function testGenerateTextResultThrowsOnModelLengthFinishReason(): void
    {
        $model = $this->createModelForFinishReason('model_length');

        $exception = null;
        try {
            $model->generateTextResult([new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])]);
        } catch (TokenLimitReachedException $e) {
            $exception = $e;
        }

        $this->assertInstanceOf(TokenLimitReachedException::class, $exception);
        $this->assertNull($exception->getMaxTokens());
        $this->assertStringContainsString('"model_length"', $exception->getMessage());
    }

    /**
     * Tests that generateTextResult() reports a finish_reason of "error" as an upstream
     * failure rather than letting it reach the base class as an unreadable response.
     */
    public function testGenerateTextResultThrowsServerExceptionOnErrorFinishReason(): void
    {
        $model = $this->createModelForFinishReason('error');

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Mistral stopped generating with finish reason "error" for choice 0');

        $model->generateTextResult([new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])]);
    }

    /**
     * Tests that tool use is forced on the opening turn, so that the model calls a tool
     * instead of describing a call in prose.
     */
    public function testToolChoiceIsForcedOnTheOpeningTurn(): void
    {
        $model = $this->createModel($this->configWithTools());
        $params = $model->exposePrepareGenerateTextParams(
            [new Message(MessageRoleEnum::user(), [new MessagePart('What is the weather in Helsinki?')])]
        );

        $this->assertSame('any', $params['tool_choice'] ?? null);
    }

    /**
     * Tests that tool use is not forced once the model has taken a turn of its own.
     *
     * Without this the model can never answer: "any" obliges it to call something on
     * every request, so a conversation that replays previous turns as text - as an agent
     * loop that stores its transcript does - calls tools until the caller gives up.
     */
    public function testToolChoiceIsNotForcedAfterAnAssistantTurn(): void
    {
        $model = $this->createModel($this->configWithTools());
        $params = $model->exposePrepareGenerateTextParams([
            new Message(MessageRoleEnum::user(), [new MessagePart('What is the weather in Helsinki?')]),
            new Message(MessageRoleEnum::model(), [new MessagePart('[calling get_weather with {"city":"Helsinki"}]')]),
            new Message(MessageRoleEnum::user(), [new MessagePart('[result of get_weather] 21C and sunny')]),
        ]);

        $this->assertArrayNotHasKey('tool_choice', $params);
    }

    /**
     * Tests that tool use is not forced after a native tool response either.
     */
    public function testToolChoiceIsNotForcedAfterAToolResponse(): void
    {
        $model = $this->createModel($this->configWithTools());
        $params = $model->exposePrepareGenerateTextParams([
            new Message(MessageRoleEnum::user(), [new MessagePart('What is the weather in Helsinki?')]),
            new Message(MessageRoleEnum::model(), [
                new MessagePart(new FunctionCall('call_1', 'get_weather', ['city' => 'Helsinki'])),
            ]),
            new Message(MessageRoleEnum::user(), [
                new MessagePart(new FunctionResponse('call_1', 'get_weather', ['temperature' => 21])),
            ]),
        ]);

        $this->assertArrayNotHasKey('tool_choice', $params);
    }

    /**
     * Tests that a tool_choice the caller asked for is left alone.
     */
    public function testCallerSuppliedToolChoiceIsKept(): void
    {
        $config = $this->configWithTools();
        $config->setCustomOptions(['tool_choice' => 'none']);

        $model = $this->createModel($config);
        $params = $model->exposePrepareGenerateTextParams(
            [new Message(MessageRoleEnum::user(), [new MessagePart('Just say hello.')])]
        );

        $this->assertSame('none', $params['tool_choice'] ?? null);
    }

    /**
     * A model config declaring one tool.
     */
    private function configWithTools(): ModelConfig
    {
        $config = new ModelConfig();
        $config->setFunctionDeclarations([
            new FunctionDeclaration(
                'get_weather',
                'Returns the current weather for a city.',
                [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => ['city'],
                ]
            ),
        ]);

        return $config;
    }

    /**
     * A model whose single response carries the given finish reason.
     */
    private function createModelForFinishReason(string $finishReason): MockProviderForMistralTextGenerationModel
    {
        $response = new Response(
            200,
            [],
            json_encode([
                'id' => 'chatcmpl_123',
                'choices' => [
                    [
                        'message' => ['role' => 'assistant', 'content' => 'Partial...'],
                        'finish_reason' => $finishReason,
                    ],
                ],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
            ])
        );

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn($response);

        return $this->createModel();
    }

    /**
     * Tests prepareGenerateTextParams() with JSON output.
     */
    public function testPrepareGenerateTextParamsWithJsonOutput(): void
    {
        $prompt = [new Message(MessageRoleEnum::user(), [new MessagePart('Hello')])];
        $config = new ModelConfig();
        $config->setOutputMimeType('application/json');

        $model = $this->createModel($config);
        $params = $model->exposePrepareGenerateTextParams($prompt);

        $this->assertArrayHasKey('response_format', $params);
        $this->assertSame('json_object', $params['response_format']['type'] ?? null);
    }
}
