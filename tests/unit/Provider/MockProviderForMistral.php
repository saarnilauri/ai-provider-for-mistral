<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Provider;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Mock class for testing ProviderForMistral.
 */
class MockProviderForMistral extends ProviderForMistral
{
    /**
     * Exposes createModel for testing.
     *
     * @param ModelMetadata $modelMetadata
     * @param ProviderMetadata $providerMetadata
     * @return ModelInterface
     */
    public static function exposeCreateModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        return static::createModel($modelMetadata, $providerMetadata);
    }
}
