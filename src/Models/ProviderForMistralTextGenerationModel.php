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
     * Overrides the base implementation to throw a {@see TokenLimitReachedException}
     * when the finish reason is "length", which Mistral uses to signal that the
     * configured max_tokens limit was reached.
     *
     * @since 0.3.0
     *
     * @param ChoiceData $choiceData
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index): Candidate
    {
        if (isset($choiceData['finish_reason']) && 'length' === $choiceData['finish_reason']) {
            $maxTokens = $this->getConfig()->getMaxTokens();
            throw new TokenLimitReachedException(
                $maxTokens !== null
                    ? sprintf('Generation stopped due to token limit (%d) with finish reason "length".', $maxTokens)
                    : 'Generation stopped due to token limit with finish reason "length".',
                $maxTokens
            );
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index);
    }

    /**
     * {@inheritDoc}
     *
     * Mistral requires a `name` field inside the `json_schema` object. When
     * the caller provides a raw JSON schema (no `name` key at the top level),
     * this method wraps it in the expected `{name, schema}` envelope so that
     * the request is never rejected with a 422.
     *
     * @since 0.4.0
     *
     * @param array<string, mixed>|null $outputSchema The output schema.
     * @return array<string, mixed> The prepared response format parameter.
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        if (is_array($outputSchema)) {
            // If the schema already has a 'name' key it is already in the
            // full json_schema envelope format ({ name, schema, ... }).
            if (!isset($outputSchema['name'])) {
                $outputSchema = [
                    'name'   => 'response',
                    'schema' => $outputSchema,
                ];
            }

            return [
                'type'        => 'json_schema',
                'json_schema' => $outputSchema,
            ];
        }

        return [
            'type' => 'json_object',
        ];
    }

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
