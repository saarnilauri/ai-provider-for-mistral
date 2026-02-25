<?php

declare(strict_types=1);

namespace AiProviderForMistral\Metadata;

use AiProviderForMistral\Provider\ProviderForMistral;
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
 * @since 1.0.0
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
     * {@inheritDoc}
     *
     * @since 1.0.0
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
     * @since 1.0.0
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
     * Callback function for sorting models by ID, to be used with `usort()`.
     *
     * @param ModelMetadata $a First model.
     * @param ModelMetadata $b Second model.
     * @return int Comparison result.
     */
    protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
    {
        $aId = $a->getId();
        $bId = $b->getId();

        // Prefer latest models over dated variants.
        if (str_contains($aId, '-latest') && !str_contains($bId, '-latest')) {
            return -1;
        }
        if (str_contains($bId, '-latest') && !str_contains($aId, '-latest')) {
            return 1;
        }

        // Fallback: Sort alphabetically.
        return strcmp($a->getId(), $b->getId());
    }
}
