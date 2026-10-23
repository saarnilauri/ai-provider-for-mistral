<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextToSpeechConversionModel;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\ProviderRegistry;

/**
 * Integration tests for Mistral text-to-speech.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * Generated audio is saved to tests/integration/audio/ so it can be listened to.
 * That directory is gitignored so generated files are never committed.
 *
 * @group integration
 * @group mistral
 *
 * @coversNothing
 */
class TextToSpeechIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private const MODEL_ID = 'voxtral-mini-tts-3-0';

    private ProviderRegistry $registry;

    /**
     * Absolute path to the directory where generated test audio is saved.
     */
    private string $audioDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);

        $this->audioDir = dirname(__DIR__) . '/audio';
    }

    /**
     * Tests text-to-speech with the provider choosing the model and voice.
     */
    public function testConvertTextToSpeechWithProviderDefaults(): void
    {
        $file = AiClient::prompt('Hello from WordPress. This is Mistral text to speech.', $this->registry)
            ->usingProvider('mistral')
            ->convertTextToSpeech();

        $this->assertAudioFile($file, 'audio/mpeg');
        $this->saveAudio($file, 'test_provider_defaults');
    }

    /**
     * Tests speech generation inferred from asking only for audio output.
     */
    public function testGenerateSpeechWithExplicitVoice(): void
    {
        $file = AiClient::prompt('Your order shipped this morning and should arrive on Thursday.', $this->registry)
            ->usingModel(ProviderForMistral::model(self::MODEL_ID))
            ->asOutputSpeechVoice('voice_485')
            ->generateSpeech();

        $this->assertAudioFile($file, 'audio/mpeg');
        $this->saveAudio($file, 'test_explicit_voice');
    }

    /**
     * Tests a non-default output format.
     */
    public function testConvertTextToSpeechAsWav(): void
    {
        $file = AiClient::prompt('This audio is a WAV file.', $this->registry)
            ->usingModel(ProviderForMistral::model(self::MODEL_ID))
            ->asOutputMimeType('audio/wav')
            ->convertTextToSpeech();

        $this->assertAudioFile($file, 'audio/wav');
        $this->assertStringStartsWith('RIFF', (string) base64_decode((string) $file->getBase64Data(), true));
        $this->saveAudio($file, 'test_wav');
    }

    /**
     * Tests that text longer than one request is narrated in chunks and joined into one MP3.
     */
    public function testLongTextIsNarratedInChunks(): void
    {
        $text = implode("\n\n", [
            'WordPress began in 2003 as a fork of an earlier blogging tool. Over the following two decades it '
            . 'grew into the software behind a large share of the web, from personal journals to newsrooms.',
            'Much of that growth came from its plugin system. Developers extend the core with actions and filters, '
            . 'which let them change behavior without editing the files that ship with each release.',
            'Themes followed a similar path. What started as a handful of template files became block themes, '
            . 'where the layout of every page can be edited in the same visual editor as the content itself.',
            'The block editor changed how people write as well. Paragraphs, images, and quotes are separate blocks, '
            . 'and each one can be moved, styled, or reused without touching any markup by hand.',
            'Accessibility has become a shared concern along the way. Contributors review new features for keyboard '
            . 'navigation, screen reader support, and color contrast before they are merged.',
            'The PHP AI Client brings the plugin idea to generative models. A provider plugin describes which models '
            . 'it offers and what they can do, and the client picks a suitable model for each prompt.',
            'Text generation was the first capability, followed by image generation. Speech is a natural next step, '
            . 'as it lets a site offer an audio version of an article to people who prefer to listen.',
            'Narrating a full article is different from reading out a single sentence, though. The text is long, '
            . 'and speech models produce their best results when each request stays reasonably short.',
            'This provider therefore splits long text at paragraph and sentence boundaries. Each piece is narrated '
            . 'in its own request, and the audio is joined into a single file in the original order.',
            'Splitting at a paragraph break is the least audible choice, as a speaker would pause there anyway. '
            . 'When a paragraph is too long, the end of a sentence is the next best place to split.',
            'Some audio formats can be joined simply by appending one file to another. MP3 is one of them, which is '
            . 'why it is the default, while WAV and FLAC files describe their whole length in a header.',
            'If you can hear this sentence, the last chunk made it into the file, and the narration ended '
            . 'where the article ends.',
        ]);

        $model = ProviderForMistral::model(self::MODEL_ID);
        $this->assertInstanceOf(ProviderForMistralTextToSpeechConversionModel::class, $model);
        $this->assertGreaterThan(1, count($model->splitTextForRequests($text, 'mp3')));

        $file = AiClient::prompt($text, $this->registry)
            ->usingModel($model)
            ->convertTextToSpeech();

        $this->assertAudioFile($file, 'audio/mpeg');
        $this->saveAudio($file, 'test_long_text');
    }

    /**
     * Asserts that the file holds non-empty audio of the expected MIME type.
     *
     * @param File   $file
     * @param string $mimeType
     */
    private function assertAudioFile(File $file, string $mimeType): void
    {
        $this->assertSame($mimeType, $file->getMimeType());

        $binaryData = base64_decode((string) $file->getBase64Data(), true);
        $this->assertNotFalse($binaryData, 'Expected valid base64-encoded audio data.');
        $this->assertGreaterThan(1000, strlen($binaryData), 'Expected more than a trivial amount of audio.');
    }

    /**
     * Saves the audio for listening.
     *
     * @param File   $file
     * @param string $name
     */
    private function saveAudio(File $file, string $name): void
    {
        $outputPath = $this->audioDir . '/' . $name . '.' . $file->getMimeTypeObject()->toExtension();
        file_put_contents($outputPath, base64_decode((string) $file->getBase64Data(), true));
        $this->assertFileExists($outputPath);
    }
}
