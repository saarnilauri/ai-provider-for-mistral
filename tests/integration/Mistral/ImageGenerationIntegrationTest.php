<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Integration tests for Mistral image generation.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * Generated images are saved to tests/integration/images/ for visual inspection.
 * That directory is gitignored so generated files are never committed.
 *
 * @group integration
 * @group mistral
 *
 * @coversNothing
 */
class ImageGenerationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private ProviderRegistry $registry;

    /**
     * Absolute path to the directory where generated test images are saved.
     */
    private string $imagesDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);

        $this->imagesDir = dirname(__DIR__) . '/images';
    }

    /**
     * Tests basic image generation with a simple prompt.
     *
     * The generated PNG is saved to tests/integration/images/ so a local
     * tester can inspect the result visually.
     */
    public function testSimpleImageGeneration(): void
    {
        $result = AiClient::prompt('A red apple on a white background.', $this->registry)
            ->usingProvider('mistral')
            ->generateImageResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $candidates = $result->getCandidates();
        $this->assertNotEmpty($candidates, 'Expected at least one image candidate.');

        $candidate = $candidates[0];
        $parts = $candidate->getMessage()->getParts();
        $this->assertNotEmpty($parts, 'Expected at least one message part.');

        $file = $parts[0]->getFile();
        $this->assertNotNull($file, 'Expected a file in the first message part.');
        $this->assertSame('image/png', $file->getMimeType());

        $base64Data = $file->getBase64Data();
        $this->assertNotNull($base64Data, 'Expected non-null base64 image data.');
        $this->assertNotEmpty($base64Data, 'Expected non-empty base64 image data.');

        $binaryData = base64_decode($base64Data, true);
        $this->assertNotFalse($binaryData, 'Expected valid base64-encoded image data.');
        $this->assertNotEmpty($binaryData, 'Expected non-empty decoded image data.');

        $outputPath = $this->imagesDir . '/test_simple_image_generation.png';
        file_put_contents($outputPath, $binaryData);
        $this->assertFileExists($outputPath);

        // Verify provider and model metadata are correctly populated.
        $this->assertSame('mistral', $result->getProviderMetadata()->getId());
        $this->assertNotEmpty($result->getModelMetadata()->getId());

        // Verify the conversation ID is returned as the result ID.
        $this->assertNotEmpty($result->getId(), 'Expected a non-empty conversation ID.');
    }
}
