<?php

declare(strict_types=1);

namespace AiProviderForMistral\Tests\Unit\Metadata;

use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * @covers \AiProviderForMistral\Metadata\ProviderForMistralModelMetadataDirectory
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
