<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextGenerationModel;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForMistralTextGenerationModel.
 */
class MockProviderForMistralTextGenerationModel extends ProviderForMistralTextGenerationModel
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
     * Exposes prepareGenerateTextParams for testing.
     *
     * @param list<Message> $prompt
     * @return array<string, mixed>
     */
    public function exposePrepareGenerateTextParams(array $prompt): array
    {
        return $this->prepareGenerateTextParams($prompt);
    }

    /**
     * Exposes getMessagePartContentData for testing.
     *
     * @param MessagePart $part
     * @return array<string, mixed>|null
     */
    public function exposeGetMessagePartContentData(MessagePart $part): ?array
    {
        return $this->getMessagePartContentData($part);
    }
}
