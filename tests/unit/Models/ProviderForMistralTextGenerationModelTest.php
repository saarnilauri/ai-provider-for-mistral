<?php

declare(strict_types=1);

namespace AiProviderForMistral\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * @covers \AiProviderForMistral\Models\ProviderForMistralTextGenerationModel
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
