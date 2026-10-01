<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Provider;

use SaarniLauri\AiProviderForMistral\Metadata\ProviderForMistralModelMetadataDirectory;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralEmbeddingGenerationModel;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralImageGenerationModel;
use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextGenerationModel;
use WordPress\AiClient\AiClient;
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
     * The AI client version that introduced embedding generation.
     *
     * @since x.x.x
     *
     * @var string
     */
    public const EMBEDDING_GENERATION_MIN_CLIENT_VERSION = '1.4.0';

    /**
     * Determines whether the AI client in use can generate embeddings.
     *
     * Below the required version the embedding interface, the builder, the result DTOs
     * and the dimensions option are all absent, which makes the provider's embedding
     * model class impossible to load. Nothing may advertise the capability or construct
     * that class unless this returns true.
     *
     * @since x.x.x
     *
     * @return bool True if embedding generation is available.
     */
    public static function supportsEmbeddingGeneration(): bool
    {
        return version_compare(AiClient::VERSION, self::EMBEDDING_GENERATION_MIN_CLIENT_VERSION, '>=');
    }

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
        /*
         * The version is checked again here, and not only where the capability is
         * advertised, so that metadata built by hand falls through to the exception
         * below instead of failing to load the model class.
         */
        if (self::supportsEmbeddingGeneration()) {
            foreach ($capabilities as $capability) {
                if ($capability->isEmbeddingGeneration()) {
                    return new ProviderForMistralEmbeddingGenerationModel($modelMetadata, $providerMetadata);
                }
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
        $providerMetadataArgs = [
            'mistral',
            'Mistral',
            ProviderTypeEnum::cloud(),
            'https://console.mistral.ai/api-keys',
            RequestAuthenticationMethod::apiKey()
        ];
        // Provider description support was added in 1.2.0.
        if (version_compare(AiClient::VERSION, '1.2.0', '>=')) {
            /*
             * Embeddings are only mentioned where the client can generate them. Each
             * variant stays a literal string so that translation tooling can extract it.
             */
            $supportsEmbeddings = self::supportsEmbeddingGeneration();
            // For WordPress, we should translate the description.
            if (function_exists('__')) {
                $providerMetadataArgs[] = $supportsEmbeddings
                    // phpcs:ignore Generic.Files.LineLength.TooLong
                    ? __('Text, image, and embedding generation with Mistral AI models.', 'ai-provider-for-mistral')
                    : __('Text and image generation with Mistral AI models.', 'ai-provider-for-mistral');
            } else {
                $providerMetadataArgs[] = $supportsEmbeddings
                    ? 'Text, image, and embedding generation with Mistral AI models.'
                    : 'Text and image generation with Mistral AI models.';
            }
        }
        // Provider logoPath support was added in 1.3.0.
        if (version_compare(AiClient::VERSION, '1.3.0', '>=')) {
            $providerMetadataArgs[] = dirname(__DIR__, 2) . '/assets/images/mistral.svg';
        }
        return new ProviderMetadata(...$providerMetadataArgs);
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
