<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextToSpeechConversionModel;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * @covers \SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextToSpeechConversionModel
 */
class ProviderForMistralTextToSpeechConversionModelTest extends TestCase
{
    /**
     * @var ModelMetadata&\PHPUnit\Framework\MockObject\Stub
     */
    private $modelMetadata;

    /**
     * @var ProviderMetadata&\PHPUnit\Framework\MockObject\Stub
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

    /**
     * Requests sent through the mock transporter.
     *
     * @var list<Request>
     */
    private array $sentRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->modelMetadata = $this->createStub(ModelMetadata::class);
        $this->modelMetadata->method('getId')->willReturn('voxtral-mini-tts-3-0');
        $this->providerMetadata = $this->createStub(ProviderMetadata::class);
        $this->providerMetadata->method('getName')->willReturn('AI Provider for Mistral');
        $this->mockHttpTransporter = $this->createMock(HttpTransporterInterface::class);
        $this->mockRequestAuthentication = $this->createMock(RequestAuthenticationInterface::class);
        $this->mockRequestAuthentication->method('authenticateRequest')->willReturnArgument(0);
        $this->sentRequests = [];
    }

    /**
     * Creates a model instance.
     *
     * @param ModelConfig|null $modelConfig
     * @return MockProviderForMistralTextToSpeechConversionModel
     */
    private function createModel(?ModelConfig $modelConfig = null): MockProviderForMistralTextToSpeechConversionModel
    {
        $model = new MockProviderForMistralTextToSpeechConversionModel(
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
     * Returns a prompt with a single user message.
     *
     * @param list<MessagePart>|null $parts
     * @return list<Message>
     */
    private function createPrompt(?array $parts = null): array
    {
        return [new Message(MessageRoleEnum::user(), $parts ?? [new MessagePart('Hello from WordPress.')])];
    }

    /**
     * Makes the transporter answer each request with the given audio, recording the requests.
     *
     * @param list<string> $audioChunks Raw audio returned for each request, in order.
     */
    private function respondWithAudio(array $audioChunks): void
    {
        $this->mockHttpTransporter
            ->method('send')
            ->willReturnCallback(function (Request $request) use ($audioChunks): Response {
                $audio = $audioChunks[count($this->sentRequests)] ?? end($audioChunks);
                $this->sentRequests[] = $request;

                return new Response(200, [], (string) json_encode(['audio_data' => base64_encode($audio)]));
            });
    }

    /**
     * Returns the decoded body of a sent request.
     *
     * @param int $index
     * @return array<string, mixed>
     */
    private function requestData(int $index): array
    {
        $data = $this->sentRequests[$index]->getData();
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * Tests a basic conversion with the default voice and format.
     */
    public function testConvertTextToSpeechResultWithDefaults(): void
    {
        $this->respondWithAudio(['fake-mp3-audio']);

        $result = $this->createModel()->convertTextToSpeechResult($this->createPrompt());

        $this->assertCount(1, $this->sentRequests);
        $this->assertStringEndsWith('/audio/speech', (string) $this->sentRequests[0]->getUri());
        $this->assertSame(
            [
                'model'           => 'voxtral-mini-tts-3-0',
                'input'           => 'Hello from WordPress.',
                'response_format' => 'mp3',
                'stream'          => false,
                'voice_id'        => ProviderForMistralTextToSpeechConversionModel::DEFAULT_VOICE_ID,
            ],
            $this->requestData(0)
        );

        $this->assertCount(1, $result->getCandidates());
        $candidate = $result->getCandidates()[0];
        $this->assertEquals(FinishReasonEnum::stop(), $candidate->getFinishReason());

        $file = $candidate->getMessage()->getParts()[0]->getFile();
        $this->assertInstanceOf(File::class, $file);
        $this->assertSame('audio/mpeg', $file->getMimeType());
        $this->assertSame(base64_encode('fake-mp3-audio'), $file->getBase64Data());
        $this->assertSame(0, $result->getTokenUsage()->getTotalTokens());
    }

    /**
     * Tests that generateSpeechResult() narrates the prompt like convertTextToSpeechResult().
     */
    public function testGenerateSpeechResultNarratesThePrompt(): void
    {
        $this->respondWithAudio(['fake-mp3-audio']);

        $result = $this->createModel()->generateSpeechResult($this->createPrompt());

        $this->assertSame('Hello from WordPress.', $this->requestData(0)['input']);
        $this->assertSame('audio/mpeg', $result->toFile()->getMimeType());
    }

    /**
     * Tests that older models default to a voice they accept, as they reject the Voxtral 3 voices.
     */
    public function testOlderModelsDefaultToLegacyVoice(): void
    {
        $this->modelMetadata = $this->createStub(ModelMetadata::class);
        $this->modelMetadata->method('getId')->willReturn('voxtral-mini-tts-latest');
        $this->respondWithAudio(['audio']);

        $this->createModel()->convertTextToSpeechResult($this->createPrompt());

        $this->assertSame(
            ProviderForMistralTextToSpeechConversionModel::LEGACY_DEFAULT_VOICE_ID,
            $this->requestData(0)['voice_id']
        );
    }

    /**
     * Tests that the outputSpeechVoice option is sent as the voice ID.
     */
    public function testOutputSpeechVoiceIsSentAsVoiceId(): void
    {
        $this->respondWithAudio(['audio']);
        $config = new ModelConfig();
        $config->setOutputSpeechVoice(' gb_jane_neutral ');

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());

        $this->assertSame('gb_jane_neutral', $this->requestData(0)['voice_id']);
    }

    /**
     * Tests that an audio part in the prompt is sent as reference audio instead of a voice ID.
     */
    public function testAudioPartIsSentAsReferenceAudio(): void
    {
        $this->respondWithAudio(['audio']);
        $config = new ModelConfig();
        $config->setOutputSpeechVoice('voice_478');
        $referenceAudio = base64_encode('reference-recording');

        $this->createModel($config)->convertTextToSpeechResult(
            $this->createPrompt([
                new MessagePart('Say this in my voice.'),
                new MessagePart(new File($referenceAudio, 'audio/mpeg')),
            ])
        );

        $data = $this->requestData(0);
        $this->assertSame('Say this in my voice.', $data['input']);
        $this->assertSame($referenceAudio, $data['ref_audio']);
        $this->assertArrayNotHasKey('voice_id', $data);
    }

    /**
     * Tests that remote reference audio is rejected.
     */
    public function testRemoteReferenceAudioIsRejected(): void
    {
        $this->mockHttpTransporter->expects($this->never())->method('send');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('inline file');

        $this->createModel()->convertTextToSpeechResult(
            $this->createPrompt([
                new MessagePart('Say this.'),
                new MessagePart(new File('https://example.com/voice.mp3', 'audio/mpeg')),
            ])
        );
    }

    /**
     * Tests that text parts from all messages are joined into the input.
     */
    public function testTextPartsAreJoined(): void
    {
        $this->respondWithAudio(['audio']);

        $this->createModel()->convertTextToSpeechResult([
            new Message(MessageRoleEnum::user(), [new MessagePart('First part.'), new MessagePart('Second part.')]),
        ]);

        $this->assertSame('First part. Second part.', $this->requestData(0)['input']);
    }

    /**
     * Tests that a prompt without text is rejected.
     */
    public function testPromptWithoutTextIsRejected(): void
    {
        $this->mockHttpTransporter->expects($this->never())->method('send');

        $this->expectException(InvalidArgumentException::class);

        $this->createModel()->convertTextToSpeechResult($this->createPrompt([new MessagePart('   ')]));
    }

    /**
     * Tests the mapping from output MIME types to response formats and back.
     *
     * @dataProvider mimeTypeProvider
     */
    public function testOutputMimeTypeSelectsResponseFormat(
        string $mimeType,
        string $expectedFormat,
        string $expectedMimeType
    ): void {
        $this->respondWithAudio(['audio']);
        $config = new ModelConfig();
        $config->setOutputMimeType($mimeType);

        $result = $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());

        $this->assertSame($expectedFormat, $this->requestData(0)['response_format']);
        $this->assertSame($expectedMimeType, $result->toFile()->getMimeType());
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public function mimeTypeProvider(): array
    {
        return [
            'mp3'  => ['audio/mpeg', 'mp3', 'audio/mpeg'],
            'wav'  => ['audio/wav', 'wav', 'audio/wav'],
            'flac' => ['audio/flac', 'flac', 'audio/flac'],
            'ogg'  => ['audio/ogg', 'opus', 'audio/ogg'],
            'pcm'  => ['audio/pcm', 'pcm', 'audio/pcm'],
        ];
    }

    /**
     * Tests that an unsupported output MIME type is rejected.
     */
    public function testUnsupportedOutputMimeTypeIsRejected(): void
    {
        $config = new ModelConfig();
        $config->setOutputMimeType('audio/aac');

        $this->expectException(InvalidArgumentException::class);

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests that the response_format custom option overrides the MIME type and is not sent twice.
     */
    public function testResponseFormatCustomOption(): void
    {
        $this->respondWithAudio(['audio']);
        $config = new ModelConfig();
        $config->setOutputMimeType('audio/wav');
        $config->setCustomOption('response_format', 'pcm');

        $result = $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());

        $this->assertSame('pcm', $this->requestData(0)['response_format']);
        $this->assertSame('audio/pcm', $result->toFile()->getMimeType());
    }

    /**
     * Tests that an unknown response_format custom option is rejected.
     */
    public function testInvalidResponseFormatCustomOptionIsRejected(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption('response_format', 'aac');

        $this->expectException(InvalidArgumentException::class);

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests that other custom options are passed through to the request.
     */
    public function testCustomOptionsArePassedThrough(): void
    {
        $this->respondWithAudio(['audio']);
        $config = new ModelConfig();
        $config->setCustomOption('expressiveness', 2.5);
        $config->setCustomOption('phoneme_hints', [['word' => 'Valu', 'phonemes' => ['v', 'a', 'l', 'u']]]);

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());

        $data = $this->requestData(0);
        $this->assertSame(2.5, $data['expressiveness']);
        $this->assertSame([['word' => 'Valu', 'phonemes' => ['v', 'a', 'l', 'u']]], $data['phoneme_hints']);
    }

    /**
     * Tests that custom options cannot override provider-managed parameters.
     */
    public function testCustomOptionCannotOverrideProviderManagedParameter(): void
    {
        $config = new ModelConfig();
        $config->setCustomOption('stream', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"stream"');

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests that long text is narrated in several requests and the MP3 audio joined without repeated ID3 tags.
     */
    public function testLongTextIsNarratedInChunksAndJoined(): void
    {
        $id3Tag = "ID3\x04\x00\x00\x00\x00\x00\x05" . 'TSSE!';
        $this->respondWithAudio([$id3Tag . 'first', $id3Tag . 'second', $id3Tag . 'third']);

        $model = $this->createModel();
        $model->maxTextLengthOverride = 20;

        $result = $model->convertTextToSpeechResult(
            $this->createPrompt([new MessagePart('First sentence. Second sentence. Third one.')])
        );

        $this->assertCount(3, $this->sentRequests);
        $this->assertSame('First sentence. ', $this->requestData(0)['input']);
        $this->assertSame('Second sentence. ', $this->requestData(1)['input']);
        $this->assertSame('Third one.', $this->requestData(2)['input']);

        $this->assertSame(base64_encode($id3Tag . 'firstsecondthird'), $result->toFile()->getBase64Data());
    }

    /**
     * Tests that reference audio is sent with every chunk so the cloned voice stays consistent.
     */
    public function testReferenceAudioIsSentWithEveryChunk(): void
    {
        $this->respondWithAudio(['audio']);
        $referenceAudio = base64_encode('reference-recording');

        $model = $this->createModel();
        $model->maxTextLengthOverride = 20;
        $model->convertTextToSpeechResult(
            $this->createPrompt([
                new MessagePart('First sentence. Second sentence.'),
                new MessagePart(new File($referenceAudio, 'audio/wav')),
            ])
        );

        $this->assertCount(2, $this->sentRequests);
        $this->assertSame($referenceAudio, $this->requestData(0)['ref_audio']);
        $this->assertSame($referenceAudio, $this->requestData(1)['ref_audio']);
    }

    /**
     * Tests that long text in a format that cannot be joined is rejected before any request is sent.
     *
     * @dataProvider unjoinableFormatProvider
     */
    public function testLongTextInUnjoinableFormatIsRejected(string $mimeType): void
    {
        $this->mockHttpTransporter->expects($this->never())->method('send');
        $config = new ModelConfig();
        $config->setOutputMimeType($mimeType);

        $model = $this->createModel($config);
        $model->maxTextLengthOverride = 20;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be joined');

        $model->convertTextToSpeechResult(
            $this->createPrompt([new MessagePart('First sentence. Second sentence.')])
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public function unjoinableFormatProvider(): array
    {
        return [
            'wav'  => ['audio/wav'],
            'flac' => ['audio/flac'],
            'opus' => ['audio/ogg'],
        ];
    }

    /**
     * Tests that short text in a format that cannot be joined is still narrated.
     */
    public function testShortTextInUnjoinableFormatIsNarrated(): void
    {
        $this->respondWithAudio(['audio']);
        $config = new ModelConfig();
        $config->setOutputMimeType('audio/wav');

        $this->createModel($config)->convertTextToSpeechResult($this->createPrompt());

        $this->assertCount(1, $this->sentRequests);
    }

    /**
     * Tests that a caller-supplied chunk size is capped at the model's limit.
     */
    public function testSplitTextForRequestsCapsCallerLimit(): void
    {
        $model = $this->createModel();
        $model->maxTextLengthOverride = 20;
        $text = 'First sentence. Second sentence.';

        $this->assertCount(2, $model->splitTextForRequests($text, 'mp3', 1000));
        $this->assertCount(4, $model->splitTextForRequests($text, 'mp3', 10));

        // The larger caller limit is capped, so the text still needs splitting, which WAV cannot do.
        $this->expectException(InvalidArgumentException::class);
        $model->splitTextForRequests($text, 'wav', 100);
    }

    /**
     * Tests that the default limit narrates typical text in a single request.
     */
    public function testDefaultLimitKeepsShortTextInOneRequest(): void
    {
        $text = str_repeat('A sentence of moderate length. ', 60);

        $this->assertSame([$text], $this->createModel()->splitTextForRequests($text, 'mp3'));
        $this->assertCount(
            2,
            $this->createModel()->splitTextForRequests($text . $text, 'mp3')
        );
    }

    /**
     * Tests that a response without audio data is rejected.
     */
    public function testMissingAudioDataIsRejected(): void
    {
        $this->mockHttpTransporter->method('send')->willReturn(new Response(200, [], '{"object":"speech"}'));

        $this->expectException(ResponseException::class);
        $this->expectExceptionMessage('audio_data');

        $this->createModel()->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests that empty audio data is rejected.
     */
    public function testEmptyAudioDataIsRejected(): void
    {
        $this->mockHttpTransporter->method('send')->willReturn(new Response(200, [], '{"audio_data":""}'));

        $this->expectException(ResponseException::class);

        $this->createModel()->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests that API errors are surfaced.
     */
    public function testApiErrorIsSurfaced(): void
    {
        $this->mockHttpTransporter->method('send')->willReturn(
            new Response(
                404,
                [],
                (string) json_encode([
                    'object'  => 'error',
                    'message' => "Voice 'nope' not found.",
                    'type'    => 'invalid_voice',
                ])
            )
        );

        $this->expectException(ClientException::class);

        $this->createModel()->convertTextToSpeechResult($this->createPrompt());
    }

    /**
     * Tests ID3v2 tag stripping.
     */
    public function testStripId3v2Tag(): void
    {
        $model = $this->createModel();

        $this->assertSame('frames', $model->exposeStripId3v2Tag("ID3\x04\x00\x00\x00\x00\x00\x03abcframes"));
        // Footer flag adds a 10-byte footer after the tag.
        $this->assertSame(
            'frames',
            $model->exposeStripId3v2Tag("ID3\x04\x00\x10\x00\x00\x00\x03abc3DI\x04\x00\x10\x00\x00\x00\x03frames")
        );
        // Syncsafe size spanning two bytes: 0x01 0x00 = 128 bytes.
        $this->assertSame(
            'frames',
            $model->exposeStripId3v2Tag("ID3\x04\x00\x00\x00\x00\x01\x00" . str_repeat('x', 128) . 'frames')
        );
        $this->assertSame("\xFF\xF3frames", $model->exposeStripId3v2Tag("\xFF\xF3frames"));
    }
}
