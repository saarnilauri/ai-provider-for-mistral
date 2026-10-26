<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralEmbeddingGenerationModel;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForMistralEmbeddingGenerationModel.
 */
class MockProviderForMistralEmbeddingGenerationModel extends ProviderForMistralEmbeddingGenerationModel
{
    /**
     * Constructor.
     *
     * @param ModelMetadata $metadata
     * @param ProviderMetadata $providerMetadata
     * @param HttpTransporterInterface $httpTransporter
     * @param RequestAuthenticationInterface $requestAuthentication
     */
    public function __construct(
        ModelMetadata $metadata,
        ProviderMetadata $providerMetadata,
        HttpTransporterInterface $httpTransporter,
        RequestAuthenticationInterface $requestAuthentication
    ) {
        parent::__construct($metadata, $providerMetadata);

        $this->setHttpTransporter($httpTransporter);
        $this->setRequestAuthentication($requestAuthentication);
    }

    /**
     * Exposes prepareGenerateEmbeddingsParams for testing.
     *
     * @param list<MessagePart> $inputs
     * @return array<string, mixed>
     */
    public function exposePrepareGenerateEmbeddingsParams(array $inputs): array
    {
        return $this->prepareGenerateEmbeddingsParams($inputs);
    }
}
