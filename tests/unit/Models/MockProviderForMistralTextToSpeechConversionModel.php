<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Models;

use SaarniLauri\AiProviderForMistral\Models\ProviderForMistralTextToSpeechConversionModel;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForMistralTextToSpeechConversionModel.
 */
class MockProviderForMistralTextToSpeechConversionModel extends ProviderForMistralTextToSpeechConversionModel
{
    /**
     * Character limit used instead of the default, if set.
     *
     * @var int|null
     */
    public ?int $maxTextLengthOverride = null;

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
     * {@inheritDoc}
     */
    protected function maxTextLength(): int
    {
        return $this->maxTextLengthOverride ?? parent::maxTextLength();
    }

    /**
     * Exposes stripId3v2Tag for testing.
     *
     * @param string $audioData
     * @return string
     */
    public function exposeStripId3v2Tag(string $audioData): string
    {
        return $this->stripId3v2Tag($audioData);
    }
}
