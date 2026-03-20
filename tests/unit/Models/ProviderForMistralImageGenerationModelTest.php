<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralImageGenerationModel;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * @covers \SaarniLauri\AiProviderForMistral\Models\ProviderForMistralImageGenerationModel
 */
class ProviderForMistralImageGenerationModelTest extends TestCase
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
        $this->modelMetadata->method('getId')->willReturn('mistral-medium-2505');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('AI Provider for Mistral');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);
    }

    /**
     * Creates a mock instance of ProviderForMistralImageGenerationModel.
     *
     * @param ModelConfig|null $modelConfig
     * @return MockProviderForMistralImageGenerationModel
     */
    private function createModel(?ModelConfig $modelConfig = null): MockProviderForMistralImageGenerationModel
    {
        $model = new MockProviderForMistralImageGenerationModel(
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
     * Returns a minimal valid prompt.
     *
     * @return list<Message>
     */
    private function createPrompt(string $text = 'A beautiful sunset over the ocean.'): array
    {
        return [new Message(MessageRoleEnum::user(), [new MessagePart($text)])];
    }

    /**
     * Returns a JSON-encoded conversation response containing one tool_file entry.
     *
     * @param string $conversationId
     * @param string $fileId
     * @return string
     */
    private function buildConversationResponseBody(
        string $conversationId = 'conv_abc123',
        string $fileId = 'file_img001'
    ): string {
        return (string) json_encode([
            'conversation_id' => $conversationId,
            'outputs'         => [
                [
                    'type'    => 'tool.execution',
                    'content' => [],
                ],
                [
                    'type'    => 'message.output',
                    'content' => [
                        [
                            'type'      => 'tool_file',
                            'file_id'   => $fileId,
                            'file_name' => 'image_generated_0.png',
                            'file_type' => 'png',
                        ],
                        [
                            'type' => 'text',
                            'text' => 'Here is your generated image.',
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Returns a JSON-encoded agent creation response.
     *
     * @param string $agentId
     * @return string
     */
    private function buildAgentResponseBody(string $agentId = 'agent_xyz789'): string
    {
        return (string) json_encode([
            'id'    => $agentId,
            'model' => 'mistral-medium-2505',
            'tools' => [['type' => 'image_generation']],
        ]);
    }

    /**
     * Tests generateImageResult() with a pre-existing agent ID in custom options.
     * Expects the model to skip agent creation (only 2 HTTP calls: conversation + download).
     */
    public function testGenerateImageResultWithAgentIdCustomOption(): void
    {
        $imageBinary = 'fake-png-binary-data';
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz789');

        $conversationResponse = new Response(
            200,
            [],
            $this->buildConversationResponseBody('conv_abc123', 'file_img001')
        );
        $imageResponse = new Response(200, ['Content-Type' => ['image/png']], $imageBinary);

        $this->mockRequestAuthentication
            ->expects($this->exactly(2))
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls($conversationResponse, $imageResponse);

        $model = $this->createModel($config);
        $result = $model->generateImageResult($this->createPrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('conv_abc123', $result->getId());
        $this->assertCount(1, $result->getCandidates());

        $candidate = $result->getCandidates()[0];
        $this->assertEquals(FinishReasonEnum::stop(), $candidate->getFinishReason());

        $parts = $candidate->getMessage()->getParts();
        $this->assertCount(1, $parts);

        $file = $parts[0]->getFile();
        $this->assertInstanceOf(File::class, $file);
        $this->assertTrue($file->isInline());
        $this->assertSame('image/png', $file->getMimeType());
        $this->assertSame(base64_encode($imageBinary), $file->getBase64Data());
    }

    /**
     * Tests generateImageResult() without a custom agent ID.
     * Expects the model to create a new agent first (3 HTTP calls: agent + conversation + download).
     */
    public function testGenerateImageResultWithAutoCreatedAgent(): void
    {
        $imageBinary = 'fake-png-binary-data';

        $agentResponse = new Response(200, [], $this->buildAgentResponseBody('agent_new001'));
        $conversationResponse = new Response(
            200,
            [],
            $this->buildConversationResponseBody('conv_def456', 'file_img002')
        );
        $imageResponse = new Response(200, ['Content-Type' => ['image/png']], $imageBinary);

        $this->mockRequestAuthentication
            ->expects($this->exactly(3))
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls($agentResponse, $conversationResponse, $imageResponse);

        $model = $this->createModel();
        $result = $model->generateImageResult($this->createPrompt());

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertSame('conv_def456', $result->getId());
        $this->assertCount(1, $result->getCandidates());
    }

    /**
     * Tests generateImageResult() when the response contains multiple tool_file entries.
     */
    public function testGenerateImageResultWithMultipleImages(): void
    {
        $imageBinary1 = 'fake-png-binary-data-1';
        $imageBinary2 = 'fake-png-binary-data-2';

        $conversationBody = (string) json_encode([
            'conversation_id' => 'conv_multi',
            'outputs'         => [
                [
                    'type'    => 'message.output',
                    'content' => [
                        [
                            'type'    => 'tool_file',
                            'file_id' => 'file_001',
                        ],
                        [
                            'type'    => 'tool_file',
                            'file_id' => 'file_002',
                        ],
                    ],
                ],
            ],
        ]);

        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $this->mockRequestAuthentication
            ->expects($this->exactly(3))
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->exactly(3))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $conversationBody),
                new Response(200, [], $imageBinary1),
                new Response(200, [], $imageBinary2)
            );

        $model = $this->createModel($config);
        $result = $model->generateImageResult($this->createPrompt());

        $this->assertCount(2, $result->getCandidates());
        $this->assertSame(
            base64_encode($imageBinary1),
            $result->getCandidates()[0]->getMessage()->getParts()[0]->getFile()->getBase64Data()
        );
        $this->assertSame(
            base64_encode($imageBinary2),
            $result->getCandidates()[1]->getMessage()->getParts()[0]->getFile()->getBase64Data()
        );
    }

    /**
     * Tests generateImageResult() when the conversation API returns an HTTP error.
     */
    public function testGenerateImageResultConversationApiFailure(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(401, [], '{"message":"Unauthorized"}'));

        $model = $this->createModel($config);

        $this->expectException(ClientException::class);
        $model->generateImageResult($this->createPrompt());
    }

    /**
     * Tests generateImageResult() when the agent creation API returns an HTTP error.
     */
    public function testGenerateImageResultAgentCreationFailure(): void
    {
        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(500, [], '{"message":"Internal error"}'));

        $model = $this->createModel();

        $this->expectException(\WordPress\AiClient\Providers\Http\Exception\ServerException::class);
        $model->generateImageResult($this->createPrompt());
    }

    /**
     * Tests generateImageResult() when the agent creation response is missing the agent ID.
     */
    public function testGenerateImageResultMissingAgentId(): void
    {
        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], '{"model":"mistral-medium-2505"}'));

        $model = $this->createModel();

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('agent id');
        $model->generateImageResult($this->createPrompt());
    }

    /**
     * Tests generateImageResult() when the conversation response has no outputs.
     */
    public function testGenerateImageResultMissingOutputs(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], '{"conversation_id":"conv_x"}'));

        $model = $this->createModel($config);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('"outputs"');
        $model->generateImageResult($this->createPrompt());
    }

    /**
     * Tests generateImageResult() when no tool_file chunks are found in outputs.
     */
    public function testGenerateImageResultNoFileIdInOutputs(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $responseBody = (string) json_encode([
            'conversation_id' => 'conv_x',
            'outputs'         => [
                [
                    'type'    => 'message.output',
                    'content' => [
                        ['type' => 'text', 'text' => 'No image was generated.'],
                    ],
                ],
            ],
        ]);

        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturn(new Response(200, [], $responseBody));

        $model = $this->createModel($config);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('"file_id"');
        $model->generateImageResult($this->createPrompt());
    }

    /**
     * Tests generateImageResult() when the image download returns an empty body.
     */
    public function testGenerateImageResultEmptyImageBody(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $this->mockRequestAuthentication
            ->expects($this->exactly(2))
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $this->buildConversationResponseBody()),
                new Response(200, [], null)
            );

        $model = $this->createModel($config);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('image response body was empty');
        $model->generateImageResult($this->createPrompt());
    }

    /**
     * Tests that generateImageResult() uses an empty string result ID when the
     * conversation response contains no 'conversation_id' key.
     */
    public function testGenerateImageResultMissingConversationId(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $responseBody = (string) json_encode([
            'outputs' => [
                [
                    'type'    => 'message.output',
                    'content' => [
                        ['type' => 'tool_file', 'file_id' => 'file_001'],
                    ],
                ],
            ],
        ]);

        $this->mockRequestAuthentication
            ->expects($this->exactly(2))
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $responseBody),
                new Response(200, [], 'binary-image-data')
            );

        $model = $this->createModel($config);
        $result = $model->generateImageResult($this->createPrompt());

        $this->assertSame('', $result->getId());
    }

    /**
     * Tests that the TokenUsage in the result is always zero (Mistral does not
     * report token usage for image generation conversations).
     */
    public function testGenerateImageResultTokenUsageIsZero(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_xyz');

        $this->mockRequestAuthentication
            ->expects($this->exactly(2))
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->exactly(2))
            ->method('send')
            ->willReturnOnConsecutiveCalls(
                new Response(200, [], $this->buildConversationResponseBody()),
                new Response(200, [], 'binary-image-data')
            );

        $model = $this->createModel($config);
        $result = $model->generateImageResult($this->createPrompt());

        $this->assertSame(0, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(0, $result->getTokenUsage()->getCompletionTokens());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests that preparePromptParam() throws when given multiple messages.
     */
    public function testPreparePromptParamThrowsOnMultipleMessages(): void
    {
        $model = $this->createModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('single user message');

        $model->exposePreparePromptParam([
            new Message(MessageRoleEnum::user(), [new MessagePart('Hello')]),
            new Message(MessageRoleEnum::user(), [new MessagePart('World')]),
        ]);
    }

    /**
     * Tests that preparePromptParam() throws when the message role is not user.
     */
    public function testPreparePromptParamThrowsOnNonUserRole(): void
    {
        $model = $this->createModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('user message');

        $model->exposePreparePromptParam([
            new Message(MessageRoleEnum::model(), [new MessagePart('Hello')]),
        ]);
    }

    /**
     * Tests that preparePromptParam() throws when the message has no text part.
     */
    public function testPreparePromptParamThrowsOnNoTextPart(): void
    {
        $model = $this->createModel();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('text message part');

        // Create a message with an image part (no text).
        $imagePart = new MessagePart(new File(base64_encode('fake-image'), 'image/png'));
        $model->exposePreparePromptParam([
            new Message(MessageRoleEnum::user(), [$imagePart]),
        ]);
    }

    /**
     * Tests that the conversation request body contains the expected parameters.
     */
    public function testGenerateImageResultConversationRequestParameters(): void
    {
        $capturedRequests = [];
        $imageBinary = 'fake-png';
        $config = new ModelConfig();
        $config->setCustomOption(ProviderForMistralImageGenerationModel::CUSTOM_OPTION_AGENT_ID, 'agent_provided');

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(function ($request) use (&$capturedRequests, $imageBinary) {
                $capturedRequests[] = $request;
                if (count($capturedRequests) === 1) {
                    return new Response(200, [], $this->buildConversationResponseBody());
                }
                return new Response(200, [], $imageBinary);
            });

        $model = $this->createModel($config);
        $model->generateImageResult($this->createPrompt('An astronaut riding a horse'));

        $this->assertCount(2, $capturedRequests);

        // Check conversation request parameters.
        $conversationRequest = $capturedRequests[0];
        $data = $conversationRequest->getData();
        $this->assertIsArray($data);
        $this->assertSame('agent_provided', $data['agent_id']);
        $this->assertSame('An astronaut riding a horse', $data['inputs']);
        $this->assertFalse($data['stream']);
    }

    /**
     * Tests that when no agent_id is set the agent creation request uses the model metadata ID.
     */
    public function testGenerateImageResultAgentCreationUsesMetadataModelId(): void
    {
        $capturedRequests = [];
        $imageBinary = 'fake-png';

        $this->mockRequestAuthentication
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(function ($request) use (&$capturedRequests, $imageBinary) {
                $capturedRequests[] = $request;
                if (count($capturedRequests) === 1) {
                    return new Response(200, [], $this->buildAgentResponseBody('agent_created'));
                }
                if (count($capturedRequests) === 2) {
                    return new Response(200, [], $this->buildConversationResponseBody());
                }
                return new Response(200, [], $imageBinary);
            });

        $model = $this->createModel();
        $model->generateImageResult($this->createPrompt());

        $this->assertCount(3, $capturedRequests);

        // Check agent creation request.
        $agentRequest = $capturedRequests[0];
        $data = $agentRequest->getData();
        $this->assertIsArray($data);
        $this->assertSame('mistral-medium-2505', $data['model']);
        $this->assertSame([['type' => 'image_generation']], $data['tools']);
    }
}
