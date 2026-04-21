<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
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
                ],
            ])
        );

        $directory = new MockProviderForMistralModelMetadataDirectory();
        $models = $directory->exposeParseResponseToModelMetadataList($response);

        $this->assertCount(2, $models);

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

        $nonChatModel = $models[1];
        $this->assertSame('mistral-embed', $nonChatModel->getId());
        $this->assertSame([], $nonChatModel->getSupportedCapabilities());
        $this->assertSame([], $nonChatModel->getSupportedOptions());
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
