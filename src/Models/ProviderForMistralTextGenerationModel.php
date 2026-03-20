<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Models;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
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
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
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
     * Adds `tool_choice` set to `"any"` when tools are present to ensure that
     * Mistral reliably invokes a tool call rather than simulating one in a
     * plain text response.
     *
     * @since 1.0.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        if (isset($params['tools']) && !empty($params['tools'])) {
            // Do not force tool use when the conversation already contains a
            // tool response — in that follow-up turn the model should
            // summarise the function result as text.
            $hasFunctionResponse = false;
            foreach ($params['messages'] as $message) {
                if (isset($message['role']) && 'tool' === $message['role']) {
                    $hasFunctionResponse = true;
                    break;
                }
            }

            if (!$hasFunctionResponse) {
                $params['tool_choice'] = 'any';
            }
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
