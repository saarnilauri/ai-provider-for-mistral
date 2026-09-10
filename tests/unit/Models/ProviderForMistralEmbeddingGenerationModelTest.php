<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralEmbeddingGenerationModel;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\EmbeddingResult;

/**
 * @covers \SaarniLauri\AiProviderForMistral\Models\ProviderForMistralEmbeddingGenerationModel
 */
class ProviderForMistralEmbeddingGenerationModelTest extends TestCase
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

        if (!ProviderForMistral::supportsEmbeddingGeneration()) {
            $this->markTestSkipped(sprintf(
                'Embedding generation needs AI client %s or newer, and %s is installed.',
                ProviderForMistral::EMBEDDING_GENERATION_MIN_CLIENT_VERSION,
                AiClient::VERSION
            ));
        }

        $this->modelMetadata = $this->createStub(ModelMetadata::class);
        $this->modelMetadata->method('getId')->willReturn('mistral-embed');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('AI Provider for Mistral');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);
    }

    /**
     * Creates a mock instance of ProviderForMistralEmbeddingGenerationModel.
     *
     * @param ModelConfig|null $modelConfig
     * @return MockProviderForMistralEmbeddingGenerationModel
     */
    private function createModel(?ModelConfig $modelConfig = null): MockProviderForMistralEmbeddingGenerationModel
    {
        $model = new MockProviderForMistralEmbeddingGenerationModel(
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
     * Builds a list of text message parts.
     *
     * @param list<string> $texts
     * @return list<MessagePart>
     */
    private function createInputs(array $texts): array
    {
        return array_map(static fn (string $text): MessagePart => new MessagePart($text), $texts);
    }

    /**
     * Builds a JSON-encoded embeddings response body.
     *
     * @param list<array{embedding: list<float|int>, index: int}> $entries
     * @param array<string, mixed> $overrides
     * @return string
     */
    private function buildResponseBody(array $entries, array $overrides = []): string
    {
        $data = array_map(
            static fn (array $entry): array => [
                'object' => 'embedding',
                'embedding' => $entry['embedding'],
                'index' => $entry['index'],
            ],
            $entries
        );

        return (string) json_encode(array_merge(
            [
                'id' => 'emb_abc123',
                'object' => 'list',
                'model' => 'mistral-embed',
                'data' => $data,
                'usage' => ['prompt_tokens' => 15, 'completion_tokens' => 0, 'total_tokens' => 15],
            ],
            $overrides
        ));
    }

    /**
     * Arranges the transporter to return the given response and captures the request sent.
     *
     * @param Response $response
     * @param Request|null $capturedRequest
     * @return void
     */
    private function expectSend(Response $response, ?Request &$capturedRequest = null): void
    {
        $this->mockRequestAuthentication
            ->expects($this->once())
            ->method('authenticateRequest')
            ->willReturnArgument(0);

        $this->mockHttpTransporter
            ->expects($this->once())
            ->method('send')
            ->willReturnCallback(
                static function (Request $request) use ($response, &$capturedRequest): Response {
                    $capturedRequest = $request;

                    return $response;
                }
            );
    }

    /**
     * Tests that several inputs are sent as one batch and come back as one vector each.
     */
    public function testGenerateEmbeddingResultSendsOneBatchAndReturnsOneVectorPerInput(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [0.1, 0.2, 0.3], 'index' => 0],
            ['embedding' => [0.4, 0.5, 0.6], 'index' => 1],
        ]));

        $capturedRequest = null;
        $this->expectSend($response, $capturedRequest);

        $model = $this->createModel();
        $result = $model->generateEmbeddingResult($this->createInputs(['first', 'second']));

        $this->assertInstanceOf(Request::class, $capturedRequest);
        $this->assertSame('https://api.mistral.ai/v1/embeddings', $capturedRequest->getUri());

        $data = $capturedRequest->getData();
        $this->assertIsArray($data);
        $this->assertSame('mistral-embed', $data['model']);
        $this->assertSame(['first', 'second'], $data['input']);
        $this->assertArrayNotHasKey('output_dimension', $data);

        $this->assertInstanceOf(EmbeddingResult::class, $result);
        $this->assertSame('emb_abc123', $result->getId());
        $this->assertSame(3, $result->getDimensions());
        $this->assertCount(2, $result->getEmbeddings());
        $this->assertSame([0.1, 0.2, 0.3], $result->getEmbeddings()[0]->getValues());
        $this->assertSame([0.4, 0.5, 0.6], $result->getEmbeddings()[1]->getValues());
        $this->assertSame(15, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(15, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests that vectors are placed by their own index rather than by arrival order.
     *
     * Embeddings map positionally onto the inputs, so a response that arrives out of order
     * would otherwise silently pair each vector with the wrong text.
     */
    public function testEmbeddingsArePlacedByIndexNotArrivalOrder(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [0.9], 'index' => 2],
            ['embedding' => [0.7], 'index' => 0],
            ['embedding' => [0.8], 'index' => 1],
        ]));

        $this->expectSend($response);

        $model = $this->createModel();
        $result = $model->generateEmbeddingResult($this->createInputs(['a', 'b', 'c']));

        $this->assertSame([0.7], $result->getEmbeddings()[0]->getValues());
        $this->assertSame([0.8], $result->getEmbeddings()[1]->getValues());
        $this->assertSame([0.9], $result->getEmbeddings()[2]->getValues());
    }

    /**
     * Tests that configured dimensions are sent as output_dimension.
     */
    public function testDimensionsAreSentAsOutputDimension(): void
    {
        $config = new ModelConfig();
        $config->setDimensions(256);

        $params = $this->createModel($config)
            ->exposePrepareGenerateEmbeddingsParams($this->createInputs(['text']));

        $this->assertSame(256, $params['output_dimension']);
    }

    /**
     * Tests that custom options are merged into the request parameters.
     */
    public function testCustomOptionsAreMerged(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption('output_dtype', 'int8');

        $params = $this->createModel($config)
            ->exposePrepareGenerateEmbeddingsParams($this->createInputs(['text']));

        $this->assertSame('int8', $params['output_dtype']);
    }

    /**
     * Tests that a custom option cannot quietly overwrite a parameter the model sets.
     */
    public function testConflictingCustomOptionThrows(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption('input', ['smuggled']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicts with an existing parameter');

        $this->createModel($config)->exposePrepareGenerateEmbeddingsParams($this->createInputs(['text']));
    }

    /**
     * Tests that an empty input list is rejected before a request is made.
     */
    public function testEmptyInputThrows(): void
    {
        $this->mockHttpTransporter->expects($this->never())->method('send');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one input');

        $this->createModel()->generateEmbeddingResult([]);
    }

    /**
     * Tests that a non-text input is rejected, since the API only takes text.
     */
    public function testNonTextInputThrows(): void
    {
        $file = new File('https://example.com/document.pdf', 'application/pdf');

        $this->mockHttpTransporter->expects($this->never())->method('send');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only accepts text embedding inputs');

        $this->createModel()->generateEmbeddingResult([new MessagePart($file)]);
    }

    /**
     * Tests that a whitespace-only input is rejected.
     */
    public function testBlankTextInputThrows(): void
    {
        $this->mockHttpTransporter->expects($this->never())->method('send');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty text');

        $this->createModel()->generateEmbeddingResult($this->createInputs(['   ']));
    }

    /**
     * Tests that a response without embeddings is reported rather than returned empty.
     */
    public function testMissingDataThrows(): void
    {
        $response = new Response(200, [], (string) json_encode(['id' => 'emb_1', 'object' => 'list']));

        $this->expectSend($response);

        $this->expectException(ResponseException::class);

        $this->createModel()->generateEmbeddingResult($this->createInputs(['text']));
    }

    /**
     * Tests that fewer vectors than inputs is reported rather than silently misaligned.
     */
    public function testFewerEmbeddingsThanInputsThrows(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [0.1], 'index' => 0],
        ]));

        $this->expectSend($response);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('1 embeddings for 2 inputs');

        $this->createModel()->generateEmbeddingResult($this->createInputs(['first', 'second']));
    }

    /**
     * Tests that a repeated index is reported rather than leaving an input unpaired.
     */
    public function testRepeatedIndexThrows(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [0.1], 'index' => 0],
            ['embedding' => [0.2], 'index' => 0],
        ]));

        $this->expectSend($response);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('repeats an index');

        $this->createModel()->generateEmbeddingResult($this->createInputs(['first', 'second']));
    }

    /**
     * Tests that an index outside the range of the inputs sent is reported.
     */
    public function testIndexOutsideInputRangeThrows(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [0.1], 'index' => 7],
        ]));

        $this->expectSend($response);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('within the range of the inputs sent');

        $this->createModel()->generateEmbeddingResult($this->createInputs(['first']));
    }

    /**
     * Tests that an empty vector is reported rather than reaching the result DTO.
     */
    public function testEmptyVectorThrows(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [], 'index' => 0],
        ]));

        $this->expectSend($response);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('non-empty embedding vector');

        $this->createModel()->generateEmbeddingResult($this->createInputs(['first']));
    }

    /**
     * Tests that vectors of differing length are reported, since a result carries one
     * dimension count for every embedding in it.
     */
    public function testMismatchedVectorLengthsThrow(): void
    {
        $response = new Response(200, [], $this->buildResponseBody([
            ['embedding' => [0.1, 0.2], 'index' => 0],
            ['embedding' => [0.3], 'index' => 1],
        ]));

        $this->expectSend($response);

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('differ in length');

        $this->createModel()->generateEmbeddingResult($this->createInputs(['first', 'second']));
    }

    /**
     * Tests that a response without usage counts still produces a result.
     */
    public function testMissingUsageIsZeroed(): void
    {
        $response = new Response(200, [], $this->buildResponseBody(
            [['embedding' => [0.1], 'index' => 0]],
            ['usage' => null]
        ));

        $this->expectSend($response);

        $result = $this->createModel()->generateEmbeddingResult($this->createInputs(['first']));

        $this->assertSame(0, $result->getTokenUsage()->getPromptTokens());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests that the model advertises itself as an embedding generation model.
     */
    public function testImplementsEmbeddingGenerationModelInterface(): void
    {
        $this->assertInstanceOf(ProviderForMistralEmbeddingGenerationModel::class, $this->createModel());
    }
}
