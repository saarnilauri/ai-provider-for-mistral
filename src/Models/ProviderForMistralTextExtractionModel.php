<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Models;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextExtraction\Contracts\TextExtractionModelInterface;
use WordPress\AiClient\Results\DTO\BoundingBox;
use WordPress\AiClient\Results\DTO\ExtractedImage;
use WordPress\AiClient\Results\DTO\ExtractedPage;
use WordPress\AiClient\Results\DTO\PageDimensions;
use WordPress\AiClient\Results\DTO\TextExtractionResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Class for text extraction (OCR) models used by the provider for Mistral.
 *
 * Uses Mistral's dedicated OCR endpoint (`POST /v1/ocr`), which returns structured
 * per-page markdown along with embedded image crops and page dimensions.
 *
 * Provider-specific OCR request parameters (e.g. `pages`, `include_image_base64`,
 * `image_limit`, `image_min_size`) can be passed through via the model config's
 * custom options and are forwarded to the API as-is.
 *
 * @since n.e.x.t
 *
 * @phpstan-type OcrImageData array{
 *     id?: string,
 *     top_left_x?: int|null, top_left_y?: int|null,
 *     bottom_right_x?: int|null, bottom_right_y?: int|null,
 *     image_base64?: string|null
 * }
 * @phpstan-type OcrDimensionsData array{dpi?: int|null, height?: int|null, width?: int|null}
 * @phpstan-type OcrPageData array{
 *     index?: int,
 *     markdown?: string,
 *     images?: list<OcrImageData>,
 *     dimensions?: OcrDimensionsData|null
 * }
 * @phpstan-type OcrResponseData array{
 *     model?: string,
 *     pages?: list<OcrPageData>,
 *     usage_info?: array{pages_processed?: int, doc_size_bytes?: int|null}
 * }
 */
class ProviderForMistralTextExtractionModel extends AbstractApiBasedModel implements TextExtractionModelInterface
{
    /**
     * Request payload keys that are managed by this class and cannot be
     * overridden via custom options.
     *
     * @since n.e.x.t
     *
     * @var list<string>
     */
    private const RESERVED_PAYLOAD_KEYS = ['model', 'document'];

    /**
     * {@inheritDoc}
     *
     * @since n.e.x.t
     */
    public function extractTextResult(File $document): TextExtractionResult
    {
        $payload = [
            'model' => $this->metadata()->getId(),
            'document' => $this->prepareDocumentParam($document),
        ];

        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (!in_array($key, self::RESERVED_PAYLOAD_KEYS, true)) {
                $payload[$key] = $value;
            }
        }

        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForMistral::url('ocr'),
            ['Content-Type' => 'application/json'],
            $payload,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToTextExtractionResult($response);
    }

    /**
     * Prepares the `document` request parameter from the given file.
     *
     * Remote files are passed by URL; inline files are passed as data URIs, both of
     * which the Mistral OCR endpoint accepts in the `document_url` / `image_url` fields.
     *
     * @since n.e.x.t
     *
     * @param File $document The document to process.
     * @return array<string, string> The document parameter.
     */
    protected function prepareDocumentParam(File $document): array
    {
        $location = $document->isRemote() ? (string) $document->getUrl() : (string) $document->getDataUri();

        if ($document->isImage()) {
            return [
                'type' => 'image_url',
                'image_url' => $location,
            ];
        }

        return [
            'type' => 'document_url',
            'document_url' => $location,
        ];
    }

    /**
     * Parses the OCR endpoint response into a text extraction result.
     *
     * @since n.e.x.t
     *
     * @param Response $response The OCR API response.
     * @return TextExtractionResult The parsed result.
     * @throws ResponseException If the response is missing page data.
     */
    protected function parseResponseToTextExtractionResult(Response $response): TextExtractionResult
    {
        /** @var OcrResponseData|null $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['pages']) || !is_array($responseData['pages']) || $responseData['pages'] === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'pages');
        }

        $pages = [];
        foreach ($responseData['pages'] as $pageData) {
            $pages[] = $this->parsePageData($pageData);
        }

        return new TextExtractionResult(
            uniqid('mistral-ocr-'),
            $pages,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            ['raw' => $responseData]
        );
    }

    /**
     * Parses a single OCR page entry into an extracted page.
     *
     * @since n.e.x.t
     *
     * @param OcrPageData $pageData The page data from the API response.
     * @return ExtractedPage The parsed page.
     */
    protected function parsePageData(array $pageData): ExtractedPage
    {
        $dimensions = $this->parseDimensionsData($pageData['dimensions'] ?? null);

        $images = [];
        if (isset($pageData['images']) && is_array($pageData['images'])) {
            foreach ($pageData['images'] as $imageData) {
                if (is_array($imageData) && isset($imageData['id']) && is_string($imageData['id'])) {
                    $images[] = $this->parseImageData($imageData, $dimensions);
                }
            }
        }

        // Mistral page indices are 0-based; SDK page numbers are 1-based.
        $pageIndex = isset($pageData['index']) && is_int($pageData['index']) ? $pageData['index'] : 0;

        return new ExtractedPage(
            $pageIndex + 1,
            isset($pageData['markdown']) && is_string($pageData['markdown']) ? $pageData['markdown'] : '',
            $images,
            $dimensions
        );
    }

    /**
     * Parses the page dimensions entry, if present.
     *
     * @since n.e.x.t
     *
     * @param OcrDimensionsData|null $dimensionsData The dimensions data from the API response.
     * @return PageDimensions|null The parsed dimensions, or null if unavailable.
     */
    protected function parseDimensionsData(?array $dimensionsData): ?PageDimensions
    {
        if (
            !is_array($dimensionsData)
            || !isset($dimensionsData['width'], $dimensionsData['height'])
            || !is_int($dimensionsData['width'])
            || !is_int($dimensionsData['height'])
            || $dimensionsData['width'] < 1
            || $dimensionsData['height'] < 1
        ) {
            return null;
        }

        $dpi = isset($dimensionsData['dpi']) && is_int($dimensionsData['dpi']) ? $dimensionsData['dpi'] : null;

        return new PageDimensions($dimensionsData['width'], $dimensionsData['height'], $dpi);
    }

    /**
     * Parses a single OCR image entry into an extracted image.
     *
     * Mistral reports image locations as pixel coordinates; these are normalized to the
     * 0–1 range using the page dimensions when available.
     *
     * @since n.e.x.t
     *
     * @param OcrImageData $imageData The image data from the API response.
     * @param PageDimensions|null $dimensions The page dimensions, used to normalize coordinates.
     * @return ExtractedImage The parsed image.
     */
    protected function parseImageData(array $imageData, ?PageDimensions $dimensions): ExtractedImage
    {
        $file = null;
        if (isset($imageData['image_base64']) && is_string($imageData['image_base64'])) {
            // The API returns either a data URI or plain base64 data; File auto-detects
            // data URIs, while plain base64 needs an explicit MIME type.
            if (str_starts_with($imageData['image_base64'], 'data:')) {
                $file = new File($imageData['image_base64']);
            } else {
                $file = new File($imageData['image_base64'], $this->guessImageMimeType($imageData['id'] ?? ''));
            }
        }

        return new ExtractedImage(
            $imageData['id'] ?? '',
            $file,
            $this->parseBoundingBox($imageData, $dimensions)
        );
    }

    /**
     * Converts pixel image coordinates into a normalized bounding box.
     *
     * @since n.e.x.t
     *
     * @param OcrImageData $imageData The image data from the API response.
     * @param PageDimensions|null $dimensions The page dimensions to normalize against.
     * @return BoundingBox|null The bounding box, or null if coordinates or dimensions are unavailable.
     */
    protected function parseBoundingBox(array $imageData, ?PageDimensions $dimensions): ?BoundingBox
    {
        if ($dimensions === null) {
            return null;
        }

        $topLeftX = $imageData['top_left_x'] ?? null;
        $topLeftY = $imageData['top_left_y'] ?? null;
        $bottomRightX = $imageData['bottom_right_x'] ?? null;
        $bottomRightY = $imageData['bottom_right_y'] ?? null;

        if (!is_int($topLeftX) || !is_int($topLeftY) || !is_int($bottomRightX) || !is_int($bottomRightY)) {
            return null;
        }

        $pageWidth = (float) $dimensions->getWidth();
        $pageHeight = (float) $dimensions->getHeight();

        $clamp = static fn (float $value): float => max(0.0, min(1.0, $value));

        $left = $clamp($topLeftX / $pageWidth);
        $top = $clamp($topLeftY / $pageHeight);
        $right = $clamp($bottomRightX / $pageWidth);
        $bottom = $clamp($bottomRightY / $pageHeight);

        if ($right < $left || $bottom < $top) {
            return null;
        }

        return new BoundingBox($left, $top, $right - $left, $bottom - $top);
    }

    /**
     * Guesses the MIME type of an extracted image from its identifier's file extension.
     *
     * @since n.e.x.t
     *
     * @param string $imageId The image identifier (typically a filename like `img-0.jpeg`).
     * @return string The MIME type; defaults to JPEG when the extension is unknown.
     */
    protected function guessImageMimeType(string $imageId): string
    {
        $extension = strtolower(pathinfo($imageId, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'png':
                return 'image/png';
            case 'gif':
                return 'image/gif';
            case 'webp':
                return 'image/webp';
            case 'avif':
                return 'image/avif';
            default:
                return 'image/jpeg';
        }
    }
}
