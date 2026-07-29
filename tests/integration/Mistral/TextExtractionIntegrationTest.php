<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\ExtractedPage;
use WordPress\AiClient\Results\DTO\TextExtractionResult;

/**
 * Integration tests for the text extraction (OCR) capability against the real Mistral API.
 *
 * Covers:
 *  - OCR models are listed with the text extraction capability.
 *  - Extraction from a remote PDF URL via the fluent AiClient::document() API.
 *  - Extraction from a local PDF file (inline base64 upload).
 *
 * Extracted markdown and embedded images are saved to tests/integration/extractions/
 * for detailed inspection.
 *
 * Requires the MISTRAL_API_KEY environment variable. The local-file test additionally
 * requires a PDF fixture: set the MISTRAL_TEST_PDF environment variable to its path, or
 * place it at tests/fixtures/text-extraction-sample.pdf.
 *
 * @group integration
 * @group mistral
 *
 * @coversNothing
 */
class TextExtractionIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Publicly accessible PDF used in Mistral's own document QnA examples.
     */
    private const DOCUMENT_URL = 'https://arxiv.org/pdf/1805.04770';

    /**
     * Default location of the local PDF fixture used by the local-file test.
     */
    private const DEFAULT_FIXTURE_PATH = __DIR__ . '/../../fixtures/text-extraction-sample.pdf';

    /**
     * Default location of the local image fixture used by the image test.
     */
    private const DEFAULT_IMAGE_FIXTURE_PATH = __DIR__ . '/../../fixtures/text-extraction-sample.png';

    private ProviderRegistry $registry;

    /**
     * Absolute path to the directory where extraction outputs are saved.
     */
    private string $extractionsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);

        $this->extractionsDir = dirname(__DIR__) . '/extractions';
    }

    public function testOcrModelsAreListedWithTextExtractionCapability(): void
    {
        $models = ProviderForMistral::modelMetadataDirectory()->listModelMetadata();

        $ocrModels = [];
        foreach ($models as $model) {
            if (str_starts_with($model->getId(), 'mistral-ocr')) {
                $ocrModels[] = $model;
            }
        }

        $this->assertNotEmpty($ocrModels, 'Expected /v1/models to list at least one mistral-ocr model.');

        foreach ($ocrModels as $model) {
            $capabilities = array_map('strval', $model->getSupportedCapabilities());
            $this->assertSame(
                ['text_extraction'],
                $capabilities,
                sprintf('Expected model "%s" to declare exactly the text extraction capability.', $model->getId())
            );
        }
    }

    public function testExtractTextFromRemotePdfUrl(): void
    {
        $config = new ModelConfig();
        // Only process the first two pages (Mistral page indices are 0-based) to keep costs
        // low while verifying multi-page extraction, and request embedded image data to
        // exercise the full response parsing.
        $config->setCustomOptions([
            'pages' => [0, 1],
            'include_image_base64' => true,
        ]);

        // The URL is extensionless, so the MIME type must be provided explicitly.
        $document = new File(self::DOCUMENT_URL, 'application/pdf');

        $result = AiClient::document($document, $this->registry)
            ->usingProvider('mistral')
            ->usingModelConfig($config)
            ->extractTextResult();

        $this->saveExtraction($result, 'test_remote_pdf_extraction');

        $this->assertInstanceOf(TextExtractionResult::class, $result);
        $this->assertSame(2, $result->getPageCount(), 'Expected exactly the two requested pages.');

        $firstPage = $result->getPages()[0];
        $this->assertInstanceOf(ExtractedPage::class, $firstPage);
        $this->assertSame(1, $firstPage->getPageNumber());
        $this->assertNotSame('', trim($firstPage->getMarkdown()));
        $this->assertStringContainsStringIgnoringCase(
            'neural network',
            $firstPage->getMarkdown(),
            'Expected the extracted first page of the paper to mention its title subject.'
        );

        $secondPage = $result->getPages()[1];
        $this->assertSame(2, $secondPage->getPageNumber());
        $this->assertNotSame('', trim($secondPage->getMarkdown()), 'Expected non-empty content on page 2.');

        // Page 2 of the paper contains a figure, so it covers embedded image extraction.
        $this->assertNotEmpty($secondPage->getImages(), 'Expected page 2 to contain an embedded image.');
        $image = $secondPage->getImages()[0];
        $this->assertNotSame('', $image->getId());
        $this->assertNotNull($image->getFile(), 'Expected image data (include_image_base64 was requested).');
        $this->assertNotSame('', (string) $image->getFile()->getBase64Data());
        $boundingBox = $image->getBoundingBox();
        $this->assertNotNull($boundingBox, 'Expected a normalized bounding box for the embedded image.');
        $this->assertGreaterThan(0.0, $boundingBox->getWidth());
        $this->assertGreaterThan(0.0, $boundingBox->getHeight());

        $this->assertNotNull($firstPage->getDimensions(), 'Expected Mistral OCR to report page dimensions.');
        $this->assertArrayHasKey('raw', $result->getAdditionalData());
    }

    public function testExtractTextFromLocalPdfFile(): void
    {
        $fixturePath = $this->resolveLocalPdfFixturePath();
        if ($fixturePath === null) {
            $this->markTestSkipped(
                'Skipping: no local PDF fixture found. Set MISTRAL_TEST_PDF to a PDF path, or place '
                . 'one at tests/fixtures/text-extraction-sample.pdf.'
            );
        }

        $document = new File($fixturePath, 'application/pdf');
        $this->assertTrue($document->isInline(), 'Expected the local PDF to be read as inline data.');

        $result = AiClient::document($document, $this->registry)
            ->usingProvider('mistral')
            ->extractTextResult();

        $this->saveExtraction($result, 'test_local_pdf_extraction');

        $this->assertGreaterThanOrEqual(1, $result->getPageCount());

        $markdown = $result->toMarkdown();
        $this->assertNotSame('', trim($markdown), 'Expected non-empty extracted content.');

        foreach ($result->getPages() as $index => $page) {
            $this->assertSame($index + 1, $page->getPageNumber(), 'Expected sequential 1-based page numbers.');
        }
    }

    public function testExtractTextFromLocalImageFile(): void
    {
        $fixturePath = $this->resolveLocalImageFixturePath();
        if ($fixturePath === null) {
            $this->markTestSkipped(
                'Skipping: no local image fixture found. Set MISTRAL_TEST_IMAGE to an image path, or '
                . 'place one at tests/fixtures/text-extraction-sample.png.'
            );
        }

        $document = new File($fixturePath);
        $this->assertTrue($document->isImage(), 'Expected the fixture to be detected as an image.');

        $result = AiClient::document($document, $this->registry)
            ->usingProvider('mistral')
            ->extractTextResult();

        $this->saveExtraction($result, 'test_local_image_extraction');

        // An image is processed as a single page.
        $this->assertSame(1, $result->getPageCount());

        $page = $result->getPages()[0];
        $this->assertSame(1, $page->getPageNumber());
        $this->assertNotSame('', trim($page->getMarkdown()), 'Expected non-empty extracted content.');
    }

    /**
     * Saves an extraction result to tests/integration/extractions/ for detailed inspection.
     *
     * Writes the extracted content as `{name}.md` and any embedded images returned by the
     * API as `{name}_{imageId}` alongside it.
     *
     * @param TextExtractionResult $result The extraction result to save.
     * @param string $name Base name for the saved files.
     */
    private function saveExtraction(TextExtractionResult $result, string $name): void
    {
        file_put_contents($this->extractionsDir . '/' . $name . '.md', $result->toMarkdown());

        foreach ($result->getPages() as $page) {
            foreach ($page->getImages() as $image) {
                $file = $image->getFile();
                if ($file === null) {
                    continue;
                }

                $base64Data = $file->getBase64Data();
                if ($base64Data === null) {
                    continue;
                }

                $imageName = basename($image->getId());
                if ($imageName === '') {
                    continue;
                }

                file_put_contents(
                    $this->extractionsDir . '/' . $name . '_' . $imageName,
                    base64_decode($base64Data)
                );
            }
        }
    }

    /**
     * Resolves the path of the local PDF fixture, if available.
     *
     * @return string|null The fixture path, or null if no fixture is available.
     */
    private function resolveLocalPdfFixturePath(): ?string
    {
        $envPath = $_ENV['MISTRAL_TEST_PDF'] ?? getenv('MISTRAL_TEST_PDF');
        if (is_string($envPath) && $envPath !== '' && file_exists($envPath)) {
            return $envPath;
        }

        if (file_exists(self::DEFAULT_FIXTURE_PATH)) {
            return self::DEFAULT_FIXTURE_PATH;
        }

        return null;
    }

    /**
     * Resolves the path of the local image fixture, if available.
     *
     * @return string|null The fixture path, or null if no fixture is available.
     */
    private function resolveLocalImageFixturePath(): ?string
    {
        $envPath = $_ENV['MISTRAL_TEST_IMAGE'] ?? getenv('MISTRAL_TEST_IMAGE');
        if (is_string($envPath) && $envPath !== '' && file_exists($envPath)) {
            return $envPath;
        }

        if (file_exists(self::DEFAULT_IMAGE_FIXTURE_PATH)) {
            return self::DEFAULT_IMAGE_FIXTURE_PATH;
        }

        return null;
    }
}
