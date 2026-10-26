<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Models;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Text\TextChunker;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\SpeechGeneration\Contracts\SpeechGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for text-to-speech models used by the provider for Mistral.
 *
 * Calls the Mistral `POST /audio/speech` endpoint, which returns the audio as a
 * base64-encoded `audio_data` field, and returns it as an inline {@see File}.
 *
 * The voice is a preset or saved voice ID given via the `outputSpeechVoice`
 * option, or a reference recording given as an audio part of the prompt, which
 * Mistral uses to clone the voice for that request only.
 *
 * Text longer than {@see self::DEFAULT_MAX_TEXT_LENGTH} characters is narrated
 * across several requests and joined into one audio file. The per-chunk seams
 * ({@see self::narrateChunk()}, {@see self::splitTextForRequests()}) are public
 * so that a separate plugin can drive narration chunk by chunk in background
 * jobs without this package depending on any job infrastructure.
 *
 * The model also implements {@see SpeechGenerationModelInterface}, as the AI
 * Client infers the speech generation capability for prompts that only ask for
 * audio output.
 *
 * @since 1.3.0
 *
 * @phpstan-type SpeechResponseData array{audio_data?: string}
 */
class ProviderForMistralTextToSpeechConversionModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface,
    SpeechGenerationModelInterface
{
    /**
     * Default voice ID used with Voxtral TTS 3 models when no voice is configured.
     *
     * This is one of the Voxtral 3 English preset voices, which are available
     * to all workspaces.
     *
     * @since 1.3.0
     *
     * @var string
     */
    public const DEFAULT_VOICE_ID = 'voice_478';

    /**
     * Default voice ID used with older models when no voice is configured.
     *
     * Voxtral 2 models reject the Voxtral 3 preset voices, but this Voxtral 2
     * English preset voice is accepted by both.
     *
     * @since 1.3.0
     *
     * @var string
     */
    public const LEGACY_DEFAULT_VOICE_ID = 'gb_jane_neutral';

    /**
     * Default maximum number of characters narrated in a single request.
     *
     * The API accepts longer input, but Mistral recommends keeping each request
     * under 300 words for the best speech quality, which this roughly matches.
     *
     * @since 1.3.0
     *
     * @var int
     */
    public const DEFAULT_MAX_TEXT_LENGTH = 2000;

    /**
     * Default response format.
     *
     * @since 1.3.0
     *
     * @var string
     */
    private const DEFAULT_RESPONSE_FORMAT = 'mp3';

    /**
     * Map of output MIME types to Mistral response formats.
     *
     * @since 1.3.0
     *
     * @var array<string, string>
     */
    private const MIME_TYPE_TO_RESPONSE_FORMAT = [
        'audio/mpeg' => 'mp3',
        'audio/mp3'  => 'mp3',
        'audio/wav'  => 'wav',
        'audio/flac' => 'flac',
        'audio/ogg'  => 'opus',
        'audio/opus' => 'opus',
        'audio/pcm'  => 'pcm',
    ];

    /**
     * Map of Mistral response formats to the MIME type of the returned audio.
     *
     * Opus audio is returned in an Ogg container. PCM audio is raw 32-bit float
     * little-endian samples, which have no registered MIME type.
     *
     * @since 1.3.0
     *
     * @var array<string, string>
     */
    private const RESPONSE_FORMAT_TO_MIME_TYPE = [
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'flac' => 'audio/flac',
        'opus' => 'audio/ogg',
        'pcm'  => 'audio/pcm',
    ];

    /**
     * Response formats whose audio can be concatenated into one file.
     *
     * MP3 is frame-based and PCM is headerless, so joining their bytes yields a
     * playable file. WAV and FLAC carry a header describing the whole stream,
     * and Opus is carried in an Ogg container, so they are excluded: emitting
     * audio that is subtly broken is worse than refusing.
     *
     * @since 1.3.0
     *
     * @var list<string>
     */
    private const JOINABLE_RESPONSE_FORMATS = [
        'mp3',
        'pcm',
    ];

    /**
     * Request parameters set by the provider, which custom options cannot override.
     *
     * @since 1.3.0
     *
     * @var list<string>
     */
    private const PROVIDER_MANAGED_KEYS = [
        'model',
        'input',
        'voice_id',
        'ref_audio',
        'stream',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 1.3.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $text = $this->extractTextFromPrompt($prompt);
        $voiceParams = $this->resolveVoiceParams($prompt);
        $responseFormat = $this->resolveResponseFormat();

        $audioData = '';
        foreach ($this->splitTextForRequests($text, $responseFormat) as $index => $chunk) {
            $chunkAudio = $this->narrateChunk($voiceParams, $chunk, $responseFormat);

            // Each MP3 chunk starts with its own ID3 tag, which only belongs at the start of the file.
            if ($index > 0 && $responseFormat === 'mp3') {
                $chunkAudio = $this->stripId3v2Tag($chunkAudio);
            }

            $audioData .= $chunkAudio;
        }

        $audioFile = new File(base64_encode($audioData), $this->resolveMimeTypeFromFormat($responseFormat));
        $message = new Message(MessageRoleEnum::model(), [new MessagePart($audioFile)]);

        return new GenerativeAiResult(
            '',
            [new Candidate($message, FinishReasonEnum::stop())],
            new TokenUsage(0, 0, 0),
            $this->providerMetadata(),
            $this->metadata(),
            []
        );
    }

    /**
     * {@inheritDoc}
     *
     * Speech is generated by narrating the prompt text.
     *
     * @since 1.3.0
     */
    public function generateSpeechResult(array $prompt): GenerativeAiResult
    {
        return $this->convertTextToSpeechResult($prompt);
    }

    /**
     * Narrates one piece of text and returns its audio bytes.
     *
     * The synchronous path walks the chunk list and calls this once per chunk;
     * a background-processing plugin can call it for a single chunk, having
     * persisted the voice and format when the work was queued.
     *
     * @since 1.3.0
     *
     * @param array<string, string> $voiceParams    The voice parameters, from {@see self::resolveVoiceParams()}.
     * @param string                $chunk          The text for this request.
     * @param string|null           $responseFormat Resolved response format, or null to resolve now.
     * @return string The raw audio bytes.
     * @throws ResponseException If the response carries no audio.
     */
    public function narrateChunk(array $voiceParams, string $chunk, ?string $responseFormat = null): string
    {
        $params = $this->applyCustomOptions(
            array_merge(
                [
                    'model'           => $this->metadata()->getId(),
                    'input'           => $chunk,
                    'response_format' => $responseFormat ?? $this->resolveResponseFormat(),
                    'stream'          => false,
                ],
                $voiceParams
            )
        );

        $request = new Request(
            HttpMethodEnum::POST(),
            ProviderForMistral::url('audio/speech'),
            ['Content-Type' => 'application/json'],
            $params,
            $this->getRequestOptions()
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        /** @var SpeechResponseData|null $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['audio_data']) || !is_string($responseData['audio_data'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'audio_data');
        }

        $audioData = base64_decode($responseData['audio_data'], true);
        if ($audioData === false || $audioData === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromInvalidData(
                $this->providerMetadata()->getName(),
                'audio_data',
                'The audio data was empty or not valid base64.'
            );
        }

        return $audioData;
    }

    /**
     * Splits the prompt text into the pieces each request will carry.
     *
     * Text that already fits produces a single piece, so the common case issues
     * exactly one request.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can chunk text up front and queue each piece as its own unit of work.
     *
     * @since 1.3.0
     *
     * @param string   $text           The full prompt text.
     * @param string   $responseFormat The resolved response format.
     * @param int|null $limit          Chunk size to use, or null for the model's own limit.
     * @return list<string> The text for each request, in order.
     * @throws InvalidArgumentException If splitting is required but the format cannot be joined.
     */
    public function splitTextForRequests(string $text, string $responseFormat, ?int $limit = null): array
    {
        $modelLimit = $this->maxTextLength();

        // A caller may ask for smaller pieces than the model allows, but never larger.
        $limit = $limit === null ? $modelLimit : min(max($limit, 1), $modelLimit);

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        // Checked before any request is sent, so no credits are spent on audio that cannot be joined.
        $this->assertFormatIsJoinable($responseFormat, $limit);

        $chunks = TextChunker::split($text, $limit);

        return $chunks === [] ? [$text] : $chunks;
    }

    /**
     * Resolves the voice parameters for the request.
     *
     * An audio part in the prompt is sent as `ref_audio`, which clones the voice
     * from that recording for this request only. Otherwise the `outputSpeechVoice`
     * option is sent as `voice_id`, falling back to {@see self::resolveDefaultVoiceId()}.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can resolve the voice once and persist it alongside queued work.
     *
     * @since 1.3.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, string> Either a `voice_id` or a `ref_audio` parameter.
     * @throws InvalidArgumentException If the reference audio is not inline.
     */
    public function resolveVoiceParams(array $prompt): array
    {
        $referenceAudio = $this->extractReferenceAudioFromPrompt($prompt);
        if ($referenceAudio !== null) {
            return ['ref_audio' => $referenceAudio];
        }

        $voiceId = $this->getConfig()->getOutputSpeechVoice();
        if ($voiceId !== null && trim($voiceId) !== '') {
            return ['voice_id' => trim($voiceId)];
        }

        return ['voice_id' => $this->resolveDefaultVoiceId()];
    }

    /**
     * Resolves the Mistral response format.
     *
     * Uses the `response_format` custom option if set, then the `outputMimeType`
     * option, and otherwise falls back to MP3.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can resolve the format once and persist it alongside queued work.
     *
     * @since 1.3.0
     *
     * @return string The Mistral response format.
     * @throws InvalidArgumentException If the requested format is not supported.
     */
    public function resolveResponseFormat(): string
    {
        $customOptions = $this->getConfig()->getCustomOptions();
        if (isset($customOptions['response_format'])) {
            $responseFormat = $customOptions['response_format'];
            if (!is_string($responseFormat) || !isset(self::RESPONSE_FORMAT_TO_MIME_TYPE[$responseFormat])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The response_format custom option must be one of: %s.',
                        implode(', ', array_keys(self::RESPONSE_FORMAT_TO_MIME_TYPE))
                    )
                );
            }

            return $responseFormat;
        }

        $mimeType = $this->getConfig()->getOutputMimeType();
        if ($mimeType === null) {
            return self::DEFAULT_RESPONSE_FORMAT;
        }

        $mimeType = strtolower($mimeType);
        if (!isset(self::MIME_TYPE_TO_RESPONSE_FORMAT[$mimeType])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new InvalidArgumentException(sprintf('Unsupported output MIME type for speech: %s.', $mimeType));
        }

        return self::MIME_TYPE_TO_RESPONSE_FORMAT[$mimeType];
    }

    /**
     * Determines the MIME type of the audio returned in a response format.
     *
     * Public so that external callers (such as a background-narration plugin)
     * can label persisted audio with the correct MIME type.
     *
     * @since 1.3.0
     *
     * @param string $responseFormat The Mistral response format.
     * @return string The MIME type.
     */
    public function resolveMimeTypeFromFormat(string $responseFormat): string
    {
        return self::RESPONSE_FORMAT_TO_MIME_TYPE[$responseFormat] ?? 'audio/mpeg';
    }

    /**
     * Extracts the text to narrate from the prompt messages.
     *
     * Concatenates all text parts from all messages.
     *
     * @since 1.3.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return string The extracted text.
     * @throws InvalidArgumentException If no text content is found.
     */
    protected function extractTextFromPrompt(array $messages): string
    {
        $textParts = [];
        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                $text = $part->getText();
                if ($text !== null) {
                    $textParts[] = $text;
                }
            }
        }

        $text = implode(' ', $textParts);
        if (trim($text) === '') {
            throw new InvalidArgumentException('The prompt must contain at least one text message part.');
        }

        return $text;
    }

    /**
     * Extracts the base64 reference audio from the prompt, if there is any.
     *
     * @since 1.3.0
     *
     * @param list<Message> $messages The prompt messages.
     * @return string|null The base64-encoded reference audio, or null if the prompt has none.
     * @throws InvalidArgumentException If the reference audio is not inline.
     */
    protected function extractReferenceAudioFromPrompt(array $messages): ?string
    {
        foreach ($messages as $message) {
            foreach ($message->getParts() as $part) {
                $file = $part->getFile();
                if ($file === null || !$file->isAudio()) {
                    continue;
                }

                $base64Data = $file->getBase64Data();
                if ($base64Data === null) {
                    throw new InvalidArgumentException(
                        'The reference audio for voice cloning must be an inline file, not a URL.'
                    );
                }

                return $base64Data;
            }
        }

        return null;
    }

    /**
     * Resolves the default voice ID when none is configured.
     *
     * @since 1.3.0
     *
     * @return string The default voice ID.
     */
    protected function resolveDefaultVoiceId(): string
    {
        $modelId = $this->metadata()->getId();
        $voiceId = preg_match('/-tts-3(-|$)/', $modelId) === 1
            ? self::DEFAULT_VOICE_ID
            : self::LEGACY_DEFAULT_VOICE_ID;

        if (function_exists('apply_filters')) {
            /**
             * Filters the default voice ID used when no voice is configured.
             *
             * @since 1.3.0
             *
             * @param string $voiceId The default voice ID.
             * @param string $modelId The model ID.
             */
            $filteredVoiceId = apply_filters('ai_provider_for_mistral_default_voice_id', $voiceId, $modelId);
            if (is_string($filteredVoiceId) && trim($filteredVoiceId) !== '') {
                $voiceId = trim($filteredVoiceId);
            }
        }

        return $voiceId;
    }

    /**
     * Returns the maximum number of characters narrated in a single request.
     *
     * @since 1.3.0
     *
     * @return int The maximum characters per request.
     */
    protected function maxTextLength(): int
    {
        $limit = self::DEFAULT_MAX_TEXT_LENGTH;

        if (function_exists('apply_filters')) {
            /**
             * Filters the maximum number of characters narrated in a single speech request.
             *
             * Longer text is split into several requests and the audio joined into one file.
             *
             * @since 1.3.0
             *
             * @param int    $limit   The maximum characters per request.
             * @param string $modelId The model ID.
             */
            $filteredLimit = apply_filters(
                'ai_provider_for_mistral_tts_max_text_length',
                $limit,
                $this->metadata()->getId()
            );
            if (is_int($filteredLimit) && $filteredLimit > 0) {
                $limit = $filteredLimit;
            }
        }

        return $limit;
    }

    /**
     * Fails when audio in the given format could not be reassembled.
     *
     * @since 1.3.0
     *
     * @param string $responseFormat The resolved response format.
     * @param int    $limit          The character limit that forced the split.
     * @return void
     * @throws InvalidArgumentException If the format cannot be safely joined.
     */
    protected function assertFormatIsJoinable(string $responseFormat, int $limit): void
    {
        if (in_array($responseFormat, self::JOINABLE_RESPONSE_FORMATS, true)) {
            return;
        }

        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message, never echoed.
        throw new InvalidArgumentException(
            sprintf(
                'Text longer than %d characters is narrated in several requests, but "%s" audio '
                . 'cannot be joined back into a single file. Choose the MP3 or PCM format, '
                . 'or shorten the text.',
                $limit,
                $responseFormat
            )
        );
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    /**
     * Merges caller-supplied custom options into the request body.
     *
     * Passes through options such as `expressiveness` and `phoneme_hints`. The
     * `response_format` option is consumed by {@see self::resolveResponseFormat()}.
     *
     * @since 1.3.0
     *
     * @param array<string, mixed> $params Request parameters the provider has already set.
     * @return array<string, mixed> The parameters with custom options merged in.
     * @throws InvalidArgumentException If a custom option collides with a provider-set parameter.
     */
    protected function applyCustomOptions(array $params): array
    {
        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if ($key === 'response_format') {
                continue;
            }

            if (in_array($key, self::PROVIDER_MANAGED_KEYS, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with a parameter set by the provider.', $key)
                );
            }

            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Removes a leading ID3v2 tag from MP3 audio.
     *
     * @since 1.3.0
     *
     * @param string $audioData The MP3 audio bytes.
     * @return string The audio bytes without the leading tag.
     */
    protected function stripId3v2Tag(string $audioData): string
    {
        if (strlen($audioData) < 10 || substr($audioData, 0, 3) !== 'ID3') {
            return $audioData;
        }

        // The tag size is a 28-bit "syncsafe" integer, excluding the 10-byte header and optional footer.
        $tagSize = ((ord($audioData[6]) & 0x7f) << 21)
            | ((ord($audioData[7]) & 0x7f) << 14)
            | ((ord($audioData[8]) & 0x7f) << 7)
            | (ord($audioData[9]) & 0x7f);
        $hasFooter = (ord($audioData[5]) & 0x10) !== 0;

        return substr($audioData, 10 + $tagSize + ($hasFooter ? 10 : 0));
    }
}
