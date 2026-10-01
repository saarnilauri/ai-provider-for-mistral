<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Provider;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralEmbeddingGenerationModel;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralImageGenerationModel;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextGenerationModel;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * @covers \SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral
 */
class ProviderForMistralTest extends TestCase
{
    /**
     * Creates model metadata with the given capabilities.
     *
     * @param string $modelId
     * @param list<CapabilityEnum> $capabilities
     * @return ModelMetadata
     */
    private function createMetadata(string $modelId, array $capabilities): ModelMetadata
    {
        return new ModelMetadata($modelId, $modelId, $capabilities, []);
    }

    /**
     * Tests that a text generation model gets the text generation model class.
     */
    public function testTextGenerationCapabilityCreatesTextModel(): void
    {
        $model = MockProviderForMistral::exposeCreateModel(
            $this->createMetadata('mistral-large-latest', [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()]),
            ProviderForMistral::metadata()
        );

        $this->assertInstanceOf(ProviderForMistralTextGenerationModel::class, $model);
    }

    /**
     * Tests that image generation wins over text generation when a model has both.
     */
    public function testImageGenerationCapabilityTakesPrecedenceOverText(): void
    {
        $model = MockProviderForMistral::exposeCreateModel(
            $this->createMetadata('mistral-medium-2505', [CapabilityEnum::textGeneration(), CapabilityEnum::imageGeneration()]),
            ProviderForMistral::metadata()
        );

        $this->assertInstanceOf(ProviderForMistralImageGenerationModel::class, $model);
    }

    /**
     * Tests that an embedding model gets the embedding model class where the client supports it.
     */
    public function testEmbeddingGenerationCapabilityCreatesEmbeddingModel(): void
    {
        if (!ProviderForMistral::supportsEmbeddingGeneration()) {
            $this->markTestSkipped(sprintf(
                'Embedding generation needs AI client %s or newer, and %s is installed.',
                ProviderForMistral::EMBEDDING_GENERATION_MIN_CLIENT_VERSION,
                AiClient::VERSION
            ));
        }

        $model = MockProviderForMistral::exposeCreateModel(
            $this->createMetadata('mistral-embed', [CapabilityEnum::embeddingGeneration()]),
            ProviderForMistral::metadata()
        );

        $this->assertInstanceOf(ProviderForMistralEmbeddingGenerationModel::class, $model);
    }

    /**
     * Tests that embedding metadata built by hand throws on a client without embedding support.
     *
     * CapabilityEnum::embeddingGeneration() already exists on 1.3.1 even though nothing else
     * of the feature does, so such metadata can be built there. Constructing the embedding
     * model class would be a fatal error, so the factory must refuse it with the usual
     * exception instead.
     */
    public function testEmbeddingGenerationCapabilityThrowsWithoutClientSupport(): void
    {
        if (ProviderForMistral::supportsEmbeddingGeneration()) {
            $this->markTestSkipped(sprintf(
                'AI client %s supports embedding generation, so this only runs below %s.',
                AiClient::VERSION,
                ProviderForMistral::EMBEDDING_GENERATION_MIN_CLIENT_VERSION
            ));
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported model capabilities');

        MockProviderForMistral::exposeCreateModel(
            $this->createMetadata('mistral-embed', [CapabilityEnum::embeddingGeneration()]),
            ProviderForMistral::metadata()
        );
    }

    /**
     * Tests that a model with no usable capability throws.
     */
    public function testModelWithoutUsableCapabilityThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported model capabilities');

        MockProviderForMistral::exposeCreateModel(
            $this->createMetadata('mistral-moderation-latest', []),
            ProviderForMistral::metadata()
        );
    }
}
