<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Models;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for image generation models used by the provider for Mistral.
 *
 * Mistral image generation is powered by the built-in `image_generation` agent tool.
 * The generation process follows three steps:
 * 1. Resolve or create a Mistral agent with the `image_generation` tool enabled.
 * 2. Send a conversation request to the agent with the user's prompt.
 * 3. Download the generated image files from the Mistral Files API.
 *
 * By default a new agent is created per request using the metadata model ID.
 * To reuse a pre-existing agent, set the {@see CUSTOM_OPTION_AGENT_ID} custom option.
 *
 * @since 0.4.0
 *
 * @phpstan-type ToolFileChunkData array{type: string, file_id?: string, file_name?: string, file_type?: string}
 * @phpstan-type ContentChunkData array{
 *     type?: string, text?: string, file_id?: string, file_name?: string, file_type?: string, ...
 * }
 * @phpstan-type OutputData array{type?: string, content?: list<ContentChunkData>, ...}
 * @phpstan-type ConversationResponseData array{conversation_id?: string, outputs?: list<OutputData>}
 * @phpstan-type AgentResponseData array{id?: string}
 */
class ProviderForMistralImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    /**
     * Custom option key for providing a pre-existing Mistral agent ID.
     *
     * When this option is set the model skips agent creation and uses the
     * provided agent ID directly for the conversation request.
     *
     * @since 0.4.0
     *
     * @var string
     */
    public const CUSTOM_OPTION_AGENT_ID = 'agent_id';

    /**
     * {@inheritDoc}
     *
     * @since 0.4.0
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();

        // Step 1: Resolve or create an agent with the image_generation tool.
        $agentId = $this->resolveAgentId();

        // Step 2: Send the conversation request to generate the image.
        $promptText = $this->preparePromptParam($prompt);
        $conversationRequest = new Request(
            HttpMethodEnum::POST(),
            ProviderForMistral::url('conversations'),
            ['Content-Type' => 'application/json'],
            [
                'agent_id' => $agentId,
                'inputs'   => $promptText,
                'stream'   => false,
            ],
            $this->getRequestOptions()
        );

        $conversationRequest = $this->getRequestAuthentication()->authenticateRequest($conversationRequest);
        $conversationResponse = $httpTransporter->send($conversationRequest);
        ResponseUtil::throwIfNotSuccessful($conversationResponse);

        // Step 3: Extract file IDs from the conversation response and download each image.
        $fileIds = $this->parseFileIds($conversationResponse);

        $candidates = [];
        foreach ($fileIds as $fileId) {
            $imageRequest = new Request(
                HttpMethodEnum::GET(),
                ProviderForMistral::url('files/' . $fileId . '/content'),
                ['Accept' => 'application/octet-stream'],
                null,
                $this->getRequestOptions()
            );

            $imageRequest = $this->getRequestAuthentication()->authenticateRequest($imageRequest);
            $imageResponse = $httpTransporter->send($imageRequest);
            ResponseUtil::throwIfNotSuccessful($imageResponse);

            $candidates[] = $this->parseImageResponseToCandidate($imageResponse, $fileId);
        }

        /** @var ConversationResponseData|null $conversationData */
        $conversationData = $conversationResponse->getData();
        $resultId = isset($conversationData['conversation_id']) && is_string($conversationData['conversation_id'])
            ? $conversationData['conversation_id']
            : '';

        return new GenerativeAiResult(
            $resultId,
            $candidates,
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            []
        );
    }

    /**
     * Resolves the Mistral agent ID to use for image generation.
     *
     * Returns the value of the {@see CUSTOM_OPTION_AGENT_ID} custom option when set.
     * Otherwise creates a new temporary agent via the Mistral Agents API.
     *
     * @since 0.4.0
     *
     * @return string The agent ID.
     */
    protected function resolveAgentId(): string
    {
        $customOptions = $this->getConfig()->getCustomOptions();
        if (
            isset($customOptions[self::CUSTOM_OPTION_AGENT_ID])
            && is_string($customOptions[self::CUSTOM_OPTION_AGENT_ID])
            && $customOptions[self::CUSTOM_OPTION_AGENT_ID] !== ''
        ) {
            return $customOptions[self::CUSTOM_OPTION_AGENT_ID];
        }

        return $this->createAgent();
    }

    /**
     * Creates a new Mistral agent with the `image_generation` tool.
     *
     * The agent uses the model ID from this model's metadata as its underlying
     * language model.
     *
     * @since 0.4.0
     *
     * @return string The ID of the newly created agent.
     * @throws ResponseException If the agent could not be created or the response is malformed.
     */
    protected function createAgent(): string
    {
        $agentRequest = new Request(
            HttpMethodEnum::POST(),
            ProviderForMistral::url('agents'),
            ['Content-Type' => 'application/json'],
            [
                'name'  => 'image-generation-agent',
                'model' => $this->metadata()->getId(),
                'tools' => [['type' => 'image_generation']],
            ],
            $this->getRequestOptions()
        );

        $agentRequest = $this->getRequestAuthentication()->authenticateRequest($agentRequest);
        $agentResponse = $this->getHttpTransporter()->send($agentRequest);
        ResponseUtil::throwIfNotSuccessful($agentResponse);

        /** @var AgentResponseData|null $agentData */
        $agentData = $agentResponse->getData();
        if (!isset($agentData['id']) || !is_string($agentData['id']) || $agentData['id'] === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'agent id');
        }

        return $agentData['id'];
    }

    /**
     * Prepares the text prompt from the given messages.
     *
     * Only a single user message with a text part is accepted, matching the
     * constraints of the Mistral Conversations API.
     *
     * @since 0.4.0
     *
     * @param list<Message> $messages The messages to extract the prompt from.
     * @return string The extracted prompt text.
     * @throws InvalidArgumentException If the messages are not a single user message with text.
     */
    protected function preparePromptParam(array $messages): string
    {
        if (count($messages) !== 1) {
            throw new InvalidArgumentException(
                'The API requires a single user message as prompt.'
            );
        }

        $message = $messages[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException(
                'The API requires a user message as prompt.'
            );
        }

        $text = null;
        foreach ($message->getParts() as $part) {
            $text = $part->getText();
            if ($text !== null) {
                break;
            }
        }

        if ($text === null) {
            throw new InvalidArgumentException(
                'The API requires a single text message part as prompt.'
            );
        }

        return $text;
    }

    /**
     * Extracts generated image file IDs from the conversation response.
     *
     * Scans all output entries for `tool_file` content chunks and collects
     * their `file_id` values.
     *
     * @since 0.4.0
     *
     * @param Response $response The Conversations API response.
     * @return list<string> The file IDs of the generated images.
     * @throws ResponseException If no outputs or file IDs are found.
     */
    protected function parseFileIds(Response $response): array
    {
        /** @var ConversationResponseData|null $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['outputs']) || !is_array($responseData['outputs'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'outputs');
        }

        $fileIds = [];
        foreach ($responseData['outputs'] as $output) {
            if (!is_array($output) || !isset($output['content']) || !is_array($output['content'])) {
                continue;
            }
            foreach ($output['content'] as $chunk) {
                if (
                    is_array($chunk)
                    && isset($chunk['type'])
                    && $chunk['type'] === 'tool_file'
                    && isset($chunk['file_id'])
                    && is_string($chunk['file_id'])
                    && $chunk['file_id'] !== ''
                ) {
                    $fileIds[] = $chunk['file_id'];
                }
            }
        }

        if ($fileIds === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'file_id');
        }

        return $fileIds;
    }

    /**
     * Converts a downloaded image response into a result Candidate.
     *
     * The raw binary image data is base64-encoded and wrapped in an inline
     * {@see File} object. Mistral always returns PNG images.
     *
     * @since 0.4.0
     *
     * @param Response $imageResponse The response from the Files API download endpoint.
     * @param string   $fileId        The file ID (used in error messages).
     * @return Candidate The parsed candidate containing the inline image file.
     * @throws ResponseException If the image response body is empty.
     */
    protected function parseImageResponseToCandidate(Response $imageResponse, string $fileId): Candidate
    {
        $binaryData = $imageResponse->getBody();
        if ($binaryData === null || $binaryData === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'files/' . $fileId . '/content',
                'The image response body was empty.'
            );
        }

        $base64Data = base64_encode($binaryData);
        $imageFile = new File($base64Data, 'image/png');
        $parts = [new MessagePart($imageFile)];
        $message = new Message(MessageRoleEnum::model(), $parts);

        return new Candidate($message, FinishReasonEnum::stop());
    }
}
