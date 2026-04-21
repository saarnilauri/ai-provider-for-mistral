<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Metadata;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the model metadata directory used by the provider for Mistral.
 *
 * @since 0.1.0
 *
 * @phpstan-type ModelCapabilities array{
 *     completion_chat?: bool,
 *     function_calling?: bool,
 *     vision?: bool
 * }
 * @phpstan-type ModelData array{
 *     id: string,
 *     name?: string|null,
 *     description?: string|null,
 *     capabilities?: ModelCapabilities
 * }
 * @phpstan-type ModelsResponseData array{
 *     data?: list<ModelData>
 * }|list<ModelData>
 */
class ProviderForMistralModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Model IDs known to support image generation via Mistral's agent tool.
     *
     * These are registered as image-generation-only entries in the metadata
     * directory and are available via the standard provider model factory.
     * Entries in this list always take precedence over any chat model entry
     * for the same model ID returned by the Mistral models API.
     *
     * @since 0.4.0
     *
     * @var array<string, string> Map of model ID to display name.
     */
    private const KNOWN_IMAGE_GENERATION_MODELS = [
        'mistral-medium-2505' => 'Mistral Medium 2505 (Image Generation)',
    ];

    /**
     * {@inheritDoc}
     *
     * Extends the base implementation to add hardcoded image generation model
     * entries for models that are not returned as image generation models by
     * the Mistral models API. Re-sorts the resulting map after the merge so
     * overridden entries (whose capability signature has changed from chat to
     * image-gen) land in the correct position relative to their peers.
     *
     * @since 0.4.0
     */
    protected function sendListModelsRequest(): array
    {
        $modelsMap = parent::sendListModelsRequest();

        $imageOptions = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::outputFileType()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        foreach (self::KNOWN_IMAGE_GENERATION_MODELS as $modelId => $modelName) {
            $modelsMap[$modelId] = new ModelMetadata(
                $modelId,
                $modelName,
                [CapabilityEnum::imageGeneration()],
                $imageOptions
            );
        }

        // Re-sort after the override: replacing an API entry with an image-generation
        // entry changes its capability signature, which usually changes where it should
        // sit in the sorted list.
        $modelsList = array_values($modelsMap);
        usort($modelsList, [$this, 'modelSortCallback']);

        $resortedMap = [];
        foreach ($modelsList as $model) {
            $resortedMap[$model->getId()] = $model;
        }

        return $resortedMap;
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        return new Request(
            $method,
            ProviderForMistral::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 0.1.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();

        $modelsData = null;
        if (is_array($responseData) && isset($responseData['data']) && is_array($responseData['data'])) {
            $modelsData = $responseData['data'];
        } elseif (is_array($responseData) && array_is_list($responseData)) {
            $modelsData = $responseData;
        }

        if ($modelsData === null || $modelsData === []) {
            throw ResponseException::fromMissingData('Mistral', 'data');
        }

        $baseOptions = [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::candidateCount()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']),
            new SupportedOption(OptionEnum::outputSchema()),
            new SupportedOption(OptionEnum::customOptions()),
        ];

        $textOnlyOptions = array_merge($baseOptions, [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ]);

        $visionOptions = array_merge($baseOptions, [
            new SupportedOption(
                OptionEnum::inputModalities(),
                [
                    [ModalityEnum::text()],
                    [ModalityEnum::text(), ModalityEnum::image()],
                ]
            ),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]),
        ]);

        /** @var list<ModelData> $modelsData */
        $models = array_values(
            array_map(
                static function (array $modelData) use ($textOnlyOptions, $visionOptions): ModelMetadata {
                    $modelId = $modelData['id'];
                    $modelName = $modelData['name'] ?? $modelId;

                    $capabilityData = $modelData['capabilities'] ?? [];
                    $supportsChat = $capabilityData['completion_chat'] ?? false;
                    $supportsFunctionCalling = $capabilityData['function_calling'] ?? false;
                    $supportsVision = $capabilityData['vision'] ?? false;

                    if (!$supportsChat) {
                        return new ModelMetadata($modelId, $modelName, [], []);
                    }

                    $capabilities = [
                        CapabilityEnum::textGeneration(),
                        CapabilityEnum::chatHistory(),
                    ];

                    $options = $supportsVision ? $visionOptions : $textOnlyOptions;

                    if ($supportsFunctionCalling) {
                        $options = array_merge($options, [
                            new SupportedOption(OptionEnum::functionDeclarations()),
                        ]);
                    }

                    return new ModelMetadata(
                        $modelId,
                        $modelName,
                        $capabilities,
                        $options
                    );
                },
                $modelsData
            )
        );

        usort($models, [$this, 'modelSortCallback']);

        return $models;
    }

    /**
     * Callback function for sorting models, to be used with `usort()`.
     *
     * The objective is not to be opinionated about which models are better, but to surface commonly used, more recent,
     * and flagship models first. Models without chat support (embeddings, moderation) and legacy open-weights models
     * are pushed to the bottom.
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     * @since 0.1.0
     *
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // 1. Chat-capable models before non-chat (embeddings, moderation).
        $aHasChat = !empty($a->getSupportedCapabilities());
        $bHasChat = !empty($b->getSupportedCapabilities());
        if ($aHasChat && !$bHasChat) {
            return -1;
        }
        if ($bHasChat && !$aHasChat) {
            return 1;
        }

        // 2. Legacy open-weights models sink.
        $aIsLegacy = $this->isLegacyOpenWeight($aId);
        $bIsLegacy = $this->isLegacyOpenWeight($bId);
        if ($aIsLegacy && !$bIsLegacy) {
            return 1;
        }
        if ($bIsLegacy && !$aIsLegacy) {
            return -1;
        }

        // 3. Family rank (lower = surfaced earlier).
        $aRank = $this->modelFamilyRank($aId);
        $bRank = $this->modelFamilyRank($bId);
        if ($aRank !== $bRank) {
            return $aRank <=> $bRank;
        }

        // 4. Within the same family, prefer the `-latest` alias.
        $aIsLatest = str_contains($aId, '-latest');
        $bIsLatest = str_contains($bId, '-latest');
        if ($aIsLatest && !$bIsLatest) {
            return -1;
        }
        if ($bIsLatest && !$aIsLatest) {
            return 1;
        }

        // 5. Newer dated variants before older ones.
        $aDate = $this->modelDateStamp($aId);
        $bDate = $this->modelDateStamp($bId);
        if ($aDate !== $bDate) {
            return $bDate <=> $aDate;
        }

        // 6. Fallback: deterministic alphabetical.
        return strcmp($aId, $bId);
    }

    /**
     * Returns a family rank for a model id; lower ranks surface earlier.
     *
     * @since 1.2.0
     *
     * @param string $id Model id.
     * @return int Rank integer; 200 for unknown families.
     */
    private function modelFamilyRank(string $id): int
    {
        if (str_starts_with($id, 'mistral-large')) {
            return 10;
        }
        if (str_starts_with($id, 'mistral-medium')) {
            return 20;
        }
        if (str_starts_with($id, 'magistral-')) {
            return 30;
        }
        if (str_starts_with($id, 'codestral-') || str_starts_with($id, 'devstral-')) {
            // Embedding variants of Codestral are handled by the embed rank below.
            if (str_contains($id, 'embed')) {
                return 110;
            }
            return 40;
        }
        if (str_starts_with($id, 'pixtral-')) {
            return 50;
        }
        if (str_starts_with($id, 'mistral-small')) {
            return 60;
        }
        if (str_starts_with($id, 'ministral-')) {
            return 70;
        }
        if (str_starts_with($id, 'voxtral-')) {
            return 80;
        }
        if (str_starts_with($id, 'mistral-ocr') || str_starts_with($id, 'mistral-saba')) {
            return 90;
        }
        if (str_starts_with($id, 'mistral-moderation')) {
            return 100;
        }
        if ($id === 'mistral-embed' || str_ends_with($id, '-embed')) {
            return 110;
        }

        return 200;
    }

    /**
     * Extracts a YYMM date stamp from a model id suffix, if present.
     *
     * @since 1.2.0
     *
     * @param string $id Model id.
     * @return int YYMM integer, or 0 if no trailing 4-digit stamp is found.
     */
    private function modelDateStamp(string $id): int
    {
        if (preg_match('/-(\d{4})$/', $id, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    /**
     * Returns true for ids that represent legacy open-weights models.
     *
     * @since 1.2.0
     *
     * @param string $id Model id.
     * @return bool Whether the model is a legacy open-weights release.
     */
    private function isLegacyOpenWeight(string $id): bool
    {
        return str_starts_with($id, 'open-') || str_starts_with($id, 'mathstral');
    }
}
