<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * @covers \SaarniLauri\AiProviderForMistral\Metadata\ProviderForMistralModelMetadataDirectory
 */
class ProviderForMistralModelMetadataDirectoryTest extends TestCase
{
    /**
     * Tests parsing model metadata with capabilities.
     */
    public function testParseResponseToModelMetadataList(): void
    {
        $response = new Response(
            200,
            [],
            json_encode([
                'data' => [
                    [
                        'id' => 'mistral-large-latest',
                        'name' => 'Mistral Large',
                        'capabilities' => [
                            'completion_chat' => true,
                            'function_calling' => true,
                            'vision' => true,
                        ],
                    ],
                    [
                        'id' => 'mistral-embed',
                        'capabilities' => [
                            'completion_chat' => false,
                        ],
                    ],
                    [
                        'id' => 'mistral-moderation-latest',
                        'capabilities' => [
                            'completion_chat' => false,
                        ],
                    ],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertCount(3, $models);

        $chatModel = $models[0];
        $this->assertInstanceOf(ModelMetadata::class, $chatModel);
        $this->assertSame('mistral-large-latest', $chatModel->getId());
        $this->assertSame('Mistral Large', $chatModel->getName());
        $this->assertContains(CapabilityEnum::textGeneration(), $chatModel->getSupportedCapabilities());
        $this->assertContains(CapabilityEnum::chatHistory(), $chatModel->getSupportedCapabilities());

        $optionNames = array_map(
            static fn (SupportedOption $option): string => $option->getName()->value,
            $chatModel->getSupportedOptions()
        );
        $this->assertContains(OptionEnum::functionDeclarations()->value, $optionNames);
        $this->assertContains(OptionEnum::inputModalities()->value, $optionNames);

        $inputModalitiesOption = $this->findOption($chatModel, OptionEnum::inputModalities());
        $this->assertNotNull($inputModalitiesOption);
        $this->assertTrue(
            $this->supportedModalitiesInclude(
                $inputModalitiesOption->getSupportedValues() ?? [],
                ['text', 'image']
            )
        );

        // A non-chat model this provider has no model class for gets nothing. The tail of
        // the list is ordered by family rank, which puts moderation ahead of embeddings.
        $unusableModel = $models[1];
        $this->assertSame('mistral-moderation-latest', $unusableModel->getId());
        $this->assertSame([], $unusableModel->getSupportedCapabilities());
        $this->assertSame([], $unusableModel->getSupportedOptions());

        $this->assertSame('mistral-embed', $models[2]->getId());
    }

    /**
     * Tests that an embedding model is recognised by ID and given the embedding capability.
     *
     * Mistral reports every capability as false for these models, so there is nothing in
     * the API response to key on.
     */
    public function testEmbeddingModelGetsTheEmbeddingCapability(): void
    {
        $this->requireEmbeddingSupport();

        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false]],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $embeddingModel = $models[0];
        $this->assertSame('mistral-embed', $embeddingModel->getId());
        $this->assertSame(
            [CapabilityEnum::embeddingGeneration()],
            $embeddingModel->getSupportedCapabilities()
        );

        $embeddingOptionNames = array_map(
            static fn (SupportedOption $option): string => $option->getName()->value,
            $embeddingModel->getSupportedOptions()
        );
        $this->assertContains(OptionEnum::inputModalities()->value, $embeddingOptionNames);
        // mistral-embed returns a fixed vector length and rejects output_dimension.
        $this->assertNotContains(OptionEnum::dimensions()->value, $embeddingOptionNames);
    }

    /**
     * Skips the calling test where the AI client in use has no embedding support.
     */
    private function requireEmbeddingSupport(): void
    {
        if (!ProviderForMistral::supportsEmbeddingGeneration()) {
            $this->markTestSkipped(sprintf(
                'Embedding generation needs AI client %s or newer, and %s is installed.',
                ProviderForMistral::EMBEDDING_GENERATION_MIN_CLIENT_VERSION,
                AiClient::VERSION
            ));
        }
    }

    /**
     * Tests that embedding models sort into the tail, general-purpose ones ahead of code ones.
     *
     * The first embedding model in the list is the one a request that names no model gets,
     * so a code embedding model must not sit ahead of a general-purpose one.
     */
    public function testEmbeddingModelsSortIntoTheTailGeneralPurposeFirst(): void
    {
        $this->requireEmbeddingSupport();

        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    ['id' => 'codestral-embed', 'capabilities' => ['completion_chat' => false]],
                    ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false]],
                    ['id' => 'mistral-large-latest', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'mistral-moderation-latest', 'capabilities' => ['completion_chat' => false]],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $ids = array_map(static fn (ModelMetadata $m): string => $m->getId(), $models);

        $this->assertSame(
            [
                'mistral-large-latest',
                'mistral-moderation-latest',
                'mistral-embed',
                'codestral-embed',
            ],
            $ids
        );
    }

    /**
     * Tests that only the embedding models that accept output_dimension advertise the option.
     */
    public function testDimensionsOptionOnlyOnTruncatableEmbeddingModels(): void
    {
        $this->requireEmbeddingSupport();

        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false]],
                    ['id' => 'codestral-embed', 'capabilities' => ['completion_chat' => false]],
                    ['id' => 'codestral-embed-2505', 'capabilities' => ['completion_chat' => false]],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $dimensionsById = [];
        foreach ($models as $model) {
            $dimensionsById[$model->getId()] = $this->findOption($model, OptionEnum::dimensions()) !== null;
        }
        ksort($dimensionsById);

        $this->assertSame(
            [
                'codestral-embed' => true,
                'codestral-embed-2505' => true,
                'mistral-embed' => false,
            ],
            $dimensionsById
        );
    }

    /**
     * Tests that the default sort surfaces flagships first and sinks non-chat / legacy models.
     */
    public function testDefaultSortOrdersByFamilyAndRecency(): void
    {
        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    ['id' => 'codestral-2501', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'codestral-latest', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'magistral-medium-latest', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'mistral-embed', 'capabilities' => ['completion_chat' => false]],
                    ['id' => 'mistral-large-2411', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'mistral-large-latest', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'mistral-medium-latest', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'mistral-moderation-latest', 'capabilities' => ['completion_chat' => false]],
                    ['id' => 'mistral-small-latest', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'open-mistral-nemo', 'capabilities' => ['completion_chat' => true]],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $ids = array_map(static fn (ModelMetadata $m): string => $m->getId(), $models);

        $this->assertSame(
            [
                'mistral-large-latest',
                'mistral-large-2411',
                'mistral-medium-latest',
                'magistral-medium-latest',
                'codestral-latest',
                'codestral-2501',
                'mistral-small-latest',
                'open-mistral-nemo',
                'mistral-moderation-latest',
                'mistral-embed',
            ],
            $ids
        );
    }

    /**
     * Tests that every chat model advertises a text+document input modality.
     */
    public function testDocumentInputModalityOnChatModels(): void
    {
        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    [
                        'id' => 'mistral-small-latest',
                        'capabilities' => ['completion_chat' => true],
                    ],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $inputModalities = $this->findOption($models[0], OptionEnum::inputModalities());
        $this->assertNotNull($inputModalities);
        $this->assertTrue(
            $this->supportedModalitiesInclude(
                $inputModalities->getSupportedValues() ?? [],
                ['text', 'document']
            ),
            'Expected chat model to advertise a [text, document] input modality combination.'
        );
    }

    /**
     * Tests that vision-capable models advertise text+image+document in their input modalities.
     */
    public function testVisionPlusDocumentModalityOnVisionModels(): void
    {
        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    [
                        'id' => 'pixtral-large-latest',
                        'capabilities' => ['completion_chat' => true, 'vision' => true],
                    ],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $inputModalities = $this->findOption($models[0], OptionEnum::inputModalities());
        $this->assertNotNull($inputModalities);
        $this->assertTrue(
            $this->supportedModalitiesInclude(
                $inputModalities->getSupportedValues() ?? [],
                ['text', 'image', 'document']
            ),
            'Expected vision model to advertise a [text, image, document] input modality combination.'
        );
    }

    /**
     * Tests that the audio input modality is only attached to voxtral-* models.
     */
    public function testAudioInputModalityOnVoxtralOnly(): void
    {
        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    [
                        'id' => 'voxtral-small-latest',
                        'capabilities' => ['completion_chat' => true],
                    ],
                    [
                        'id' => 'mistral-large-latest',
                        'capabilities' => ['completion_chat' => true, 'vision' => true],
                    ],
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $byId = [];
        foreach ($models as $model) {
            $byId[$model->getId()] = $model;
        }

        $voxtralInputs = $this->findOption($byId['voxtral-small-latest'], OptionEnum::inputModalities());
        $this->assertNotNull($voxtralInputs);
        $this->assertTrue(
            $this->supportedModalitiesInclude(
                $voxtralInputs->getSupportedValues() ?? [],
                ['text', 'audio']
            ),
            'Expected Voxtral model to advertise a [text, audio] input modality combination.'
        );

        $mistralInputs = $this->findOption($byId['mistral-large-latest'], OptionEnum::inputModalities());
        $this->assertNotNull($mistralInputs);
        $this->assertFalse(
            $this->supportedModalitiesInclude(
                $mistralInputs->getSupportedValues() ?? [],
                ['text', 'audio']
            ),
            'Non-Voxtral chat models should not advertise an audio input modality combination.'
        );
    }

    /**
     * Tests that subclasses can override the sort callback, proving the comparator is dispatched rather than inlined.
     *
     * The public WordPress filter `ai_provider_for_mistral_model_sort_callback` is only available when WordPress is
     * loaded, but the dispatch path is the same — so exercising it via subclass override gives equivalent coverage in
     * the unit test harness.
     */
    public function testSortCallbackIsDispatchedSoItCanBeOverridden(): void
    {
        $response = new Response(
            200,
            [],
            (string) json_encode([
                'data' => [
                    ['id' => 'aaa', 'capabilities' => ['completion_chat' => true]],
                    ['id' => 'zzz', 'capabilities' => ['completion_chat' => true]],
                ],
            ])
        );

        $directory = new class extends MockProviderForMistralModelMetadataDirectory {
            protected function modelSortCallback(ModelMetadata $a, ModelMetadata $b): int
            {
                return strcmp($b->getId(), $a->getId());
            }
        };

        $models = $directory->exposeParseResponseToModelMetadataList($response);
        $ids = array_map(static fn (ModelMetadata $m): string => $m->getId(), $models);

        $this->assertSame(['zzz', 'aaa'], $ids);
    }

    /**
     * Finds a supported option by name.
     *
     * @param ModelMetadata $model
     * @param OptionEnum $option
     * @return SupportedOption|null
     */
    private function findOption(ModelMetadata $model, OptionEnum $option): ?SupportedOption
    {
        foreach ($model->getSupportedOptions() as $supportedOption) {
            if ($supportedOption->getName()->is($option)) {
                return $supportedOption;
            }
        }

        return null;
    }

    /**
     * Checks if the supported modality values include the expected set.
     *
     * @param list<mixed> $supportedValues
     * @param list<string> $expected
     * @return bool
     */
    private function supportedModalitiesInclude(array $supportedValues, array $expected): bool
    {
        foreach ($supportedValues as $value) {
            if (!is_array($value)) {
                continue;
            }

            $modalities = array_map(
                static function ($modality): ?string {
                    return $modality instanceof ModalityEnum ? $modality->value : null;
                },
                $value
            );

            $modalities = array_values(array_filter($modalities));
            sort($modalities);

            $expectedSorted = $expected;
            sort($expectedSorted);

            if ($modalities === $expectedSorted) {
                return true;
            }
        }

        return false;
    }
}
