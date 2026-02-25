<?php

declare(strict_types=1);

namespace AiProviderForMistral\Tests\Unit\Metadata;

use AiProviderForMistral\Metadata\ProviderForMistralModelMetadataDirectory;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForMistralModelMetadataDirectory.
 */
class MockProviderForMistralModelMetadataDirectory extends ProviderForMistralModelMetadataDirectory
{
    /**
     * Exposes parseResponseToModelMetadataList for testing.
     *
     * @param Response $response
     * @return list<ModelMetadata>
     */
    public function exposeParseResponseToModelMetadataList(Response $response): array
    {
        return $this->parseResponseToModelMetadataList($response);
    }
}
