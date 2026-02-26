<?php

declare(strict_types=1);

namespace AiProviderForMistral\Provider;

use AiProviderForMistral\Metadata\ProviderForMistralModelMetadataDirectory;
use AiProviderForMistral\Models\ProviderForMistralImageGenerationModel;
use AiProviderForMistral\Models\ProviderForMistralTextGenerationModel;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Class for the WordPress AI Client provider for Mistral.
 *
 * @since 0.1.0
 */
class ProviderForMistral extends AbstractApiProvider
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function baseUrl(): string
    {
        return 'https://api.mistral.ai/v1';
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isImageGeneration()) {
                return new ProviderForMistralImageGenerationModel($modelMetadata, $providerMetadata);
            }
        }
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new ProviderForMistralTextGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . implode(', ', $capabilities)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        return new ProviderMetadata(
            'mistral',
            'AI Provider for Mistral',
            ProviderTypeEnum::cloud(),
            'https://console.mistral.ai/api-keys',
            RequestAuthenticationMethod::apiKey()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // Check valid API access by attempting to list models.
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new ProviderForMistralModelMetadataDirectory();
    }
}
