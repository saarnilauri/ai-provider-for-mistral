<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Models;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;

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
     * Overrides the base implementation to handle the finish reasons that are
     * specific to Mistral. Its schema defines five - `stop`, `length`,
     * `model_length`, `error` and `tool_calls` - while the OpenAI-compatible
     * base class knows `stop`, `length`, `content_filter` and `tool_calls`, so
     * the two Mistral-only reasons would otherwise reach the base class and be
     * reported as a malformed response.
     *
     * - `length` signals that the configured max_tokens limit was reached.
     * - `model_length` signals that the model's own context length was reached.
     *   Both become a {@see TokenLimitReachedException}.
     * - `error` signals that generation failed on Mistral's side. The HTTP
     *   status is still 200, so nothing else in the stack notices; it is
     *   reported as a {@see ServerException} because that is what it is - an
     *   upstream failure, and one the caller can sensibly retry, rather than a
     *   response this provider could not understand.
     *
     * @since 0.3.0
     *
     * @param ChoiceData $choiceData
     * @throws TokenLimitReachedException If generation stopped at a token limit.
     * @throws ServerException If generation failed on the provider side.
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index): Candidate
    {
        $finishReason = isset($choiceData['finish_reason']) && is_string($choiceData['finish_reason'])
            ? $choiceData['finish_reason']
            : '';

        if ('length' === $finishReason) {
            $maxTokens = $this->getConfig()->getMaxTokens();
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new TokenLimitReachedException(
                $maxTokens !== null
                    ? sprintf('Generation stopped due to token limit (%d) with finish reason "length".', $maxTokens)
                    : 'Generation stopped due to token limit with finish reason "length".',
                $maxTokens
            );
        }

        if ('model_length' === $finishReason) {
            // Not the configured limit but the model's own, so no max tokens
            // value is passed: there is none to report.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new TokenLimitReachedException(
                'Generation stopped because the model context length was reached '
                . 'with finish reason "model_length".'
            );
        }

        if ('error' === $finishReason) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new ServerException(
                sprintf(
                    'Mistral stopped generating with finish reason "error" for choice %d, '
                    . 'which indicates a failure on the provider side. The request can be retried.',
                    $index
                ),
                500
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
     * Adds `tool_choice` set to `"any"` on the opening turn of a conversation
     * that declares tools, to ensure that Mistral reliably invokes a tool call
     * rather than simulating one in a plain text response.
     *
     * Only the opening turn, because `"any"` obliges the model to call
     * something on every request it is sent with, and a model obliged to call
     * something can never answer in words. Once the conversation contains a
     * turn of the model's own - a tool response, or an assistant message - the
     * choice goes back to Mistral's default of `"auto"`, so the model can use
     * another tool or write the answer, whichever the conversation needs.
     *
     * Previously the guard looked for a `tool` role only. Callers that replay
     * the transcript as text rather than as native tool messages - which is
     * what an agent loop does when it has to store a conversation between
     * requests, or normalise it across providers - therefore never satisfied
     * it, so every turn was forced, and the model called tools until the caller
     * gave up rather than ever concluding.
     *
     * A `tool_choice` passed by the caller through the model config's custom
     * options is left alone: the caller has said what it wants.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        if (isset($params['tool_choice']) || empty($params['tools'])) {
            return $params;
        }

        $messages = is_array($params['messages'] ?? null) ? $params['messages'] : [];

        $hasModelTurn = false;
        foreach ($messages as $message) {
            $role = is_array($message) ? ($message['role'] ?? '') : '';
            if ('tool' === $role || 'assistant' === $role) {
                $hasModelTurn = true;
                break;
            }
        }

        if (!$hasModelTurn) {
            $params['tool_choice'] = 'any';
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Mistral requires the `parameters` field to always be present on function
     * declarations. When a {@see FunctionDeclaration} has null parameters, the
     * base `toArray()` omits the key entirely, which causes a 422 from the
     * Mistral API. This override ensures an empty object schema is used as a
     * fallback.
     *
     * @since 1.0.0
     *
     * @param list<FunctionDeclaration> $functionDeclarations The function declarations.
     * @return list<array<string, mixed>> The prepared tools parameter.
     */
    protected function prepareToolsParam(array $functionDeclarations): array
    {
        $tools = parent::prepareToolsParam($functionDeclarations);

        foreach ($tools as &$tool) {
            if (!isset($tool['function']['parameters'])) {
                $tool['function']['parameters'] = [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ];
            }
        }

        return $tools;
    }

    /**
     * {@inheritDoc}
     *
     * Extends the base implementation so that document files (PDFs and similar)
     * are serialized into Mistral's `{type: "document_url", document_url: "..."}`
     * content chunk. The Mistral chat endpoint accepts documents by URL only —
     * inline base64 document content is not supported, so callers must upload
     * via the Files API and pass the resulting signed URL.
     *
     * Images and audio continue to use the parent's OpenAI-compatible
     * serialization, which is accepted by Mistral's OpenAI-compatible layer.
     *
     * @since 1.2.0
     *
     * @param MessagePart $part The message part to get the data for.
     * @return array<string, mixed>|null The data for the message content part, or null if not applicable.
     * @throws InvalidArgumentException If a non-remote document is provided.
     */
    protected function getMessagePartContentData(MessagePart $part): ?array
    {
        if ($part->getType()->isFile()) {
            $file = $part->getFile();
            if ($file !== null && $file->isDocument()) {
                if (!$file->isRemote()) {
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                    throw new InvalidArgumentException(
                        'Mistral chat requires document files to be provided as a URL. '
                        . 'Upload via the Files API and use the returned signed URL.'
                    );
                }

                return [
                    'type'         => 'document_url',
                    'document_url' => $file->getUrl(),
                ];
            }
        }

        return parent::getMessagePartContentData($part);
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
