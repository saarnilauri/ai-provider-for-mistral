<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Builders\PromptBuilder;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Integration tests that reproduce how the WordPress AI plugin generates images.
 *
 * The AI plugin (`ai/image-generation` and `ai/image-prompt-generation` abilities)
 * does not send the short, clean prompts used in {@see ImageGenerationIntegrationTest}.
 * It builds the prompt builder like this (see `Generate_Image::get_prompt_builder()`):
 * - a request timeout of 90 seconds,
 * - an inline output file type,
 * - a model preference list containing only Google and OpenAI image models,
 *   so Mistral is only picked as a fallback,
 * - the site guidelines appended to the user's prompt (not as a system instruction).
 *
 * For featured images the prompt is first written by a text model from the post
 * content, using the plugin's image prompt system instruction.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * Generated images are saved to tests/integration/images/ for visual inspection.
 *
 * @group integration
 * @group mistral
 * @group ai-plugin
 *
 * @coversNothing
 */
class AiPluginImageGenerationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Copy of the AI plugin's `includes/Abilities/Image/image-prompt-system-instruction.php`.
     */
    private const IMAGE_PROMPT_SYSTEM_INSTRUCTION = <<<'INSTRUCTION'
You are a helpful assistant that generates a single, self-contained image generation prompt suitable for use with an image generation LLM.

You will be given:
- Some content to use as inspiration for the final generated image
- Additional context, provided in a structured format
- Some optional style instructions to apply to the final generated image

Your task is to synthesize this information into a single, complete image generation prompt that can be passed directly to another LLM to immediately generate an image.

Requirements:
- Incorporate relevant context faithfully and accurately
- Do not reference the existence or structure of the input context
- Do not include explanations, headings, or commentary
- Output only the final image generation prompt text

The generated prompt should describe an image that visually represents the content's core topic and tone. Use the provided content as factual grounding, but do not include text, captions, logos, or branding in the image unless explicitly specified.

The prompt should:
- Be written as a direct instruction to an image generation model
- Clearly describe the subject, setting, and visual style
- Reflect the content's theme and context without being overly literal
- Avoid mentioning the content, author, or website
- Be concise but descriptive enough to produce a high-quality, editorial-style image

Output only the final image generation prompt, with no explanations or additional commentary.
INSTRUCTION;

    /**
     * Guidelines in the format produced by the AI plugin's `Guidelines::format_for_prompt()`.
     */
    private const GUIDELINES = "<guidelines>\n"
        . '<site-context>A Finnish outdoor gear shop that publishes hiking guides and product news.</site-context>'
        . "\n"
        . '<image-guidelines>Use natural light and muted, earthy colours. Prefer photographic realism. '
        . 'Never show people\'s faces up close.</image-guidelines>'
        . "\n</guidelines>";

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
     * Tests the block "Generate Image" modal flow: a short prompt typed by the user,
     * without any site guidelines configured.
     */
    public function testInlineImageGenerationWithUserPrompt(): void
    {
        $result = $this->createAiPluginImagePromptBuilder(
            $this->buildAiPluginImagePrompt('A cosy wooden cabin by a frozen lake at dusk')
        )->generateImageResult();

        $this->assertMistralImageResult($result, 'test_ai_plugin_inline_image');
    }

    /**
     * Tests the block "Generate Image" modal flow when the site has guidelines configured.
     *
     * The AI plugin appends the guidelines to the image prompt, so the Mistral agent
     * receives an editorial-standards preamble and an XML block along with the request.
     */
    public function testInlineImageGenerationWithSiteGuidelines(): void
    {
        $result = $this->createAiPluginImagePromptBuilder(
            $this->buildAiPluginImagePrompt('A cosy wooden cabin by a frozen lake at dusk', self::GUIDELINES)
        )->generateImageResult();

        $this->assertMistralImageResult($result, 'test_ai_plugin_inline_image_guidelines');
    }

    /**
     * Tests the "Generate featured image" flow.
     *
     * First a text model writes an image prompt from the post content, and then that
     * prompt (with the site guidelines appended) is used to generate the image.
     */
    public function testFeaturedImageGenerationFromPostContent(): void
    {
        $imagePrompt = $this->generateAiPluginImagePrompt(
            'Autumn is the best time to hike in Lapland. The mosquitoes are gone, the fells turn red '
                . 'and orange during ruska, and the first northern lights appear in the evening sky. '
                . 'Pack layers, a headlamp and waterproof boots, and remember that the days get short fast.',
            [
                'title' => 'Five reasons to hike in Lapland this autumn',
                'type'  => 'post',
            ]
        );

        $this->assertNotSame('', trim($imagePrompt), 'Expected the text model to return an image prompt.');

        $this->throttle();

        $result = $this->createAiPluginImagePromptBuilder(
            $this->buildAiPluginImagePrompt($imagePrompt, self::GUIDELINES)
        )->generateImageResult();

        $this->assertMistralImageResult($result, 'test_ai_plugin_featured_image');
    }

    /**
     * Tests the "Refine" flow, where the current image is passed back as a reference.
     *
     * Mistral image models only accept text input, so the prompt builder must report
     * that image generation is not supported. The AI plugin then shows its
     * "Image refinement failed" error instead of sending the request.
     */
    public function testImageRefinementWithReferenceImageIsNotSupported(): void
    {
        // A 1x1 transparent PNG.
        $referenceImage = new File(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
            'image/png'
        );

        $promptBuilder = $this->createAiPluginImagePromptBuilder(
            $this->buildAiPluginImagePrompt('Make the sky more orange')
        )->withFile($referenceImage);

        $this->assertFalse(
            $promptBuilder->isSupportedForImageGeneration(),
            'Expected Mistral not to be selected for image refinement with a reference image.'
        );
    }

    /**
     * Builds the image prompt the same way as the AI plugin's `Generate_Image::get_prompt_builder()`.
     *
     * The preamble is copied verbatim, including the HTML-encoded apostrophe.
     *
     * @param string $prompt     The image prompt.
     * @param string $guidelines Formatted guidelines, or an empty string for none.
     * @return string The prompt sent to the provider.
     */
    private function buildAiPluginImagePrompt(string $prompt, string $guidelines = ''): string
    {
        if ($guidelines !== '') {
            $prompt .= "\n\n" . 'The following guidelines represent the site&#039;s editorial standards. '
                . 'Apply them where relevant. If guidelines conflict with the above input, prioritize accuracy.';
            $prompt .= "\n\n" . $guidelines;
        }

        return $prompt;
    }

    /**
     * Creates an image prompt builder configured like the AI plugin's `Generate_Image` ability.
     *
     * @param string $prompt The full image prompt.
     * @return PromptBuilder The configured prompt builder.
     */
    private function createAiPluginImagePromptBuilder(string $prompt): PromptBuilder
    {
        $requestOptions = new RequestOptions();
        $requestOptions->setTimeout(90);

        return AiClient::prompt($prompt, $this->registry)
            ->usingRequestOptions($requestOptions)
            ->asOutputFileType(FileTypeEnum::inline())
            // The AI plugin's get_preferred_image_models() list, none of which are registered here.
            ->usingModelPreference(
                ['google', 'gemini-3.1-flash-image-preview'],
                ['google', 'gemini-3-pro-image-preview'],
                ['google', 'gemini-2.5-flash-image'],
                ['google', 'imagen-4.0-generate-001'],
                ['openai', 'gpt-image-2.5-flare'],
                ['openai', 'gpt-image-2']
            );
    }

    /**
     * Generates an image prompt from post content like the AI plugin's `Generate_Image_Prompt` ability.
     *
     * @param string                $content The post content.
     * @param array<string, string> $context Additional post context, keyed by field name.
     * @return string The generated image prompt.
     */
    private function generateAiPluginImagePrompt(string $content, array $context): string
    {
        $contextLines = [];
        foreach ($context as $key => $value) {
            $contextLines[] = sprintf('%s: %s', ucwords(str_replace('_', ' ', $key)), $value);
        }

        $prompt = '<content>' . $content . '</content>'
            . "\n\n<additional-context>" . implode("\n", $contextLines) . '</additional-context>';

        return AiClient::prompt($prompt, $this->registry)
            ->usingSystemInstruction(self::IMAGE_PROMPT_SYSTEM_INSTRUCTION)
            ->usingProvider('mistral')
            ->generateText();
    }

    /**
     * Asserts that the result holds a Mistral-generated inline image and saves it for inspection.
     *
     * Mirrors what the AI plugin reads from the result: `toImageFile()->getBase64Data()`.
     *
     * @param GenerativeAiResult $result   The image generation result.
     * @param string             $baseName The file name to save the image as, without an extension.
     */
    private function assertMistralImageResult(GenerativeAiResult $result, string $baseName): void
    {
        $this->assertSame('mistral', $result->getProviderMetadata()->getId());

        $imageFile = $result->toImageFile();
        $this->assertTrue($imageFile->isInline(), 'Expected an inline image file.');
        $this->assertContains($imageFile->getMimeType(), ['image/png', 'image/jpeg', 'image/webp']);

        $base64Data = trim($imageFile->getBase64Data() ?? '');
        $this->assertNotSame('', $base64Data, 'Expected non-empty base64 image data.');

        $binaryData = base64_decode($base64Data, true);
        $this->assertNotFalse($binaryData, 'Expected valid base64-encoded image data.');

        $outputPath = $this->imagesDir . '/' . $baseName . '.' . $imageFile->getMimeTypeObject()->toExtension();
        file_put_contents($outputPath, $binaryData);
        $this->assertFileExists($outputPath);
    }
}
