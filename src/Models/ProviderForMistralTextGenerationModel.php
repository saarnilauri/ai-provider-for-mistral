<?php

declare(strict_types=1);

namespace AiProviderForMistral\Models;

use AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;

/**
 * Class for text generation models used by the provider for Mistral.
 *
 * @since 0.1.0
 *
 * @phpstan-import-type ChoiceData from AbstractOpenAiCompatibleTextGenerationModel
 */
class ProviderForMistralTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        return new Request(
            $method,
            ProviderForMistral::url($path),
            $headers,
            $data,
            $this->getRequestOptions()
        );
    }
}
