<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Models;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Class for embedding generation models used by the provider for Mistral.
 *
 * Embeddings come from the `/v1/embeddings` endpoint, which takes a batch of texts
 * and returns one vector per text.
 *
 * Only `codestral-embed` accepts `output_dimension`; `mistral-embed` returns a fixed
 * 1024 dimensions and answers the parameter with a 400. The model metadata declares the
 * option only for the models that support it, so a request for a shortened vector is
 * resolved onto one of those rather than reaching a model that would reject it.
 *
 * @since x.x.x
 *
 * @phpstan-type EmbeddingData array{object?: string, embedding?: list<float|int>, index?: int}
 * @phpstan-type UsageData array{prompt_tokens?: int, completion_tokens?: int, total_tokens?: int}
 * @phpstan-type ResponseData array{
 *     id?: string,
 *     object?: string,
 *     model?: string,
 *     data?: list<EmbeddingData>,
 *     usage?: UsageData
 * }
 */
class ProviderForMistralEmbeddingGenerationModel extends AbstractApiBasedModel implements
    EmbeddingGenerationModelInterface
{
    /**
     * {@inheritDoc}
     *
     * @since x.x.x
     *
     * @param list<MessagePart> $inputs The inputs to embed, one embedding generated per input.
     * @return EmbeddingResult Result containing one embedding per input, in input order.
     */
    public function generateEmbeddingResult(array $inputs): EmbeddingResult
    {
        $params = $this->prepareGenerateEmbeddingsParams($inputs);

        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForMistral::url('embeddings'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToEmbeddingResult($response, count($inputs));
    }

    /**
     * Prepares the given inputs and the model configuration into parameters for the API request.
     *
     * @since x.x.x
     *
     * @param list<MessagePart> $inputs The inputs to embed, one embedding generated per input.
     * @return array<string, mixed> The parameters for the API request.
     * @throws InvalidArgumentException If the inputs are invalid, or a custom option conflicts
     *                                 with a parameter the model sets itself.
     */
    protected function prepareGenerateEmbeddingsParams(array $inputs): array
    {
        if (!array_is_list($inputs)) {
            throw new InvalidArgumentException('Embedding input must be provided as a list of message parts.');
        }

        if ($inputs === []) {
            throw new InvalidArgumentException('The API requires at least one input.');
        }

        $texts = [];
        foreach ($inputs as $index => $part) {
            $texts[] = $this->preparePartInput($part, $index);
        }

        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $texts,
        ];

        $dimensions = $this->getConfig()->getDimensions();
        if ($dimensions !== null) {
            $params['output_dimension'] = $dimensions;
        }

        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing parameter.', $key)
                );
            }

            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares a single message part into one embeddings input string.
     *
     * @since x.x.x
     *
     * @param MessagePart $part The message part that makes up one embedding input.
     * @param int $index The index of the part within the input list, used for error messages.
     * @return string The embedding input text.
     * @throws InvalidArgumentException If the part is not a non-empty text part.
     */
    protected function preparePartInput(MessagePart $part, int $index): string
    {
        if (!$part->getType()->isText()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException(
                sprintf('The API only accepts text embedding inputs, but input at index %d is not text.', $index)
            );
        }

        $text = $part->getText();
        if ($text === null || trim($text) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException(
                sprintf('The embedding input at index %d must contain non-empty text.', $index)
            );
        }

        return $text;
    }

    /**
     * Parses an embeddings response into an embedding result.
     *
     * @since x.x.x
     *
     * @param Response $response The API response.
     * @param int $expectedCount The number of inputs sent, and therefore of vectors expected.
     * @return EmbeddingResult The parsed embedding result.
     * @throws ResponseException If the response contains no usable embeddings.
     */
    protected function parseResponseToEmbeddingResult(Response $response, int $expectedCount): EmbeddingResult
    {
        /** @var ResponseData|null $responseData */
        $responseData = $response->getData();
        $apiName = $this->providerMetadata()->getName();

        if (!isset($responseData['data']) || $responseData['data'] === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($apiName, 'data');
        }

        if (!is_array($responseData['data']) || !array_is_list($responseData['data'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromInvalidData(
                $apiName,
                'data',
                'The value must be an indexed array of embedding objects.'
            );
        }

        /*
         * Embeddings map positionally onto the inputs, so they are placed by their own
         * `index` field rather than by the order they arrive in.
         */
        $vectors = [];
        foreach ($responseData['data'] as $position => $embeddingData) {
            if (!is_array($embeddingData)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw ResponseException::fromInvalidData(
                    $apiName,
                    "data[{$position}]",
                    'The value must be an embedding object.'
                );
            }

            $index = $embeddingData['index'] ?? $position;
            if (!is_int($index) || $index < 0 || $index >= $expectedCount) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw ResponseException::fromInvalidData(
                    $apiName,
                    "data[{$position}].index",
                    'The value must be an integer within the range of the inputs sent.'
                );
            }

            if (isset($vectors[$index])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw ResponseException::fromInvalidData(
                    $apiName,
                    "data[{$position}].index",
                    'The value repeats an index already returned.'
                );
            }

            $vector = $embeddingData['embedding'] ?? null;
            if (!is_array($vector) || !array_is_list($vector) || $vector === []) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw ResponseException::fromInvalidData(
                    $apiName,
                    "data[{$position}].embedding",
                    'The value must be a non-empty embedding vector.'
                );
            }

            $vectors[$index] = $vector;
        }

        if (count($vectors) !== $expectedCount) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromInvalidData(
                $apiName,
                'data',
                sprintf('The response holds %d embeddings for %d inputs.', count($vectors), $expectedCount)
            );
        }

        ksort($vectors);

        $embeddings = [];
        $dimensions = count(reset($vectors));
        foreach ($vectors as $index => $vector) {
            if (count($vector) !== $dimensions) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw ResponseException::fromInvalidData(
                    $apiName,
                    "data[{$index}].embedding",
                    'The vectors in the response differ in length.'
                );
            }

            $embeddings[] = new Embedding($vector, $dimensions);
        }

        $additionalData = is_array($responseData) ? $responseData : [];
        unset($additionalData['data']);

        return new EmbeddingResult(
            isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '',
            $embeddings,
            $dimensions,
            $this->parseTokenUsage($responseData['usage'] ?? null),
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses the token usage reported alongside the embeddings.
     *
     * @since x.x.x
     *
     * @param mixed $usageData The `usage` value from the response, if any.
     * @return TokenUsage The token usage, zeroed where the response omits a count.
     */
    protected function parseTokenUsage($usageData): TokenUsage
    {
        if (!is_array($usageData)) {
            return new TokenUsage(0, 0, 0);
        }

        $promptTokens = isset($usageData['prompt_tokens']) && is_int($usageData['prompt_tokens'])
            ? $usageData['prompt_tokens']
            : 0;
        $completionTokens = isset($usageData['completion_tokens']) && is_int($usageData['completion_tokens'])
            ? $usageData['completion_tokens']
            : 0;
        $totalTokens = isset($usageData['total_tokens']) && is_int($usageData['total_tokens'])
            ? $usageData['total_tokens']
            : $promptTokens + $completionTokens;

        return new TokenUsage($promptTokens, $completionTokens, $totalTokens);
    }
}
