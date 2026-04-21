<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Integration tests for the 1.2.0 multimodal and model-sorting changes.
 *
 * Covers, against the real Mistral API:
 *  - Default sort order surfaces flagship chat models first.
 *  - Legacy open-weights and non-chat models sink to the bottom.
 *  - Document input modality is declared on chat models.
 *  - Audio input modality is declared only on voxtral-* models.
 *  - Chat completions accept a `document_url` content chunk end-to-end.
 *
 * Requires the MISTRAL_API_KEY environment variable.
 *
 * @group integration
 * @group mistral
 *
 * @coversNothing
 */
class MultimodalIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Publicly accessible PDF used in Mistral's own document QnA examples.
     */
    private const DOCUMENT_URL = 'https://arxiv.org/pdf/1805.04770';

    private ProviderRegistry $registry;

    /**
     * @var list<ModelMetadata>
     */
    private array $models;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);

        $this->models = ProviderForMistral::modelMetadataDirectory()->listModelMetadata();
    }

    public function testFirstSortedChatModelIsMistralLargeFlagship(): void
    {
        $this->assertNotEmpty($this->models, 'Expected /v1/models to return at least one entry.');

        $firstChatModelId = null;
        foreach ($this->models as $model) {
            if ($model->getSupportedCapabilities() !== []) {
                $firstChatModelId = $model->getId();
                break;
            }
        }

        $this->assertNotNull($firstChatModelId, 'Expected at least one chat-capable model.');
        $this->assertStringStartsWith(
            'mistral-large',
            $firstChatModelId,
            sprintf('Expected first chat model to be a Mistral Large flagship, got "%s".', $firstChatModelId)
        );
    }

    public function testNonChatModelsSinkBelowAllChatModels(): void
    {
        $lastChatIndex = null;
        $firstNonChatIndex = null;

        foreach ($this->models as $index => $model) {
            if ($model->getSupportedCapabilities() !== []) {
                $lastChatIndex = $index;
            } elseif ($firstNonChatIndex === null) {
                $firstNonChatIndex = $index;
            }
        }

        if ($firstNonChatIndex === null) {
            $this->markTestSkipped('No non-chat models returned by the API; nothing to verify.');
        }

        $this->assertNotNull($lastChatIndex);
        $this->assertGreaterThan(
            $lastChatIndex,
            $firstNonChatIndex,
            'Non-chat models (embeddings, moderation) should sort after every chat-capable model.'
        );
    }

    public function testOpenWeightsModelsSinkBelowPrimaryChatModels(): void
    {
        $firstOpenWeightIndex = null;
        $primaryChatModelSeen = false;

        foreach ($this->models as $index => $model) {
            $id = $model->getId();
            $isLegacy = str_starts_with($id, 'open-') || str_starts_with($id, 'mathstral');
            $isChat = $model->getSupportedCapabilities() !== [];

            if ($isLegacy) {
                if ($firstOpenWeightIndex === null) {
                    $firstOpenWeightIndex = $index;
                }
                continue;
            }

            if ($isChat) {
                $primaryChatModelSeen = true;
                if ($firstOpenWeightIndex !== null) {
                    $this->fail(sprintf(
                        'Non-legacy chat model "%s" (index %d) appeared after legacy open-weights '
                        . 'model at index %d — primary models should sort above legacy ones.',
                        $id,
                        $index,
                        $firstOpenWeightIndex
                    ));
                }
            }
        }

        if ($firstOpenWeightIndex === null) {
            $this->markTestSkipped('No legacy open-weights models returned; nothing to verify.');
        }

        $this->assertTrue(
            $primaryChatModelSeen,
            'Expected at least one non-legacy chat model before the first legacy open-weights entry.'
        );
    }

    public function testDocumentInputModalityDeclaredOnChatModels(): void
    {
        $target = $this->findModelById('mistral-small-latest')
            ?? $this->findModelById('mistral-large-latest');

        if ($target === null) {
            $this->markTestSkipped('Neither mistral-small-latest nor mistral-large-latest was returned.');
        }

        $this->assertTrue(
            $this->supportsInputModalityCombination(
                $target,
                [ModalityEnum::text(), ModalityEnum::document()]
            ),
            sprintf(
                'Expected %s to advertise a [text, document] input modality combination.',
                $target->getId()
            )
        );
    }

    public function testAudioInputModalityOnVoxtralOnly(): void
    {
        $voxtralChatModel = null;
        $nonVoxtralChatModel = null;

        foreach ($this->models as $model) {
            if ($model->getSupportedCapabilities() === []) {
                continue;
            }
            if (str_starts_with($model->getId(), 'voxtral-')) {
                $voxtralChatModel = $voxtralChatModel ?? $model;
            } elseif (str_starts_with($model->getId(), 'mistral-')) {
                $nonVoxtralChatModel = $nonVoxtralChatModel ?? $model;
            }
        }

        if ($voxtralChatModel === null) {
            $this->markTestSkipped('No voxtral-* chat model returned by the API.');
        }

        $this->assertTrue(
            $this->supportsInputModalityCombination(
                $voxtralChatModel,
                [ModalityEnum::text(), ModalityEnum::audio()]
            ),
            sprintf(
                'Expected %s to advertise a [text, audio] input modality combination.',
                $voxtralChatModel->getId()
            )
        );

        $this->assertNotNull(
            $nonVoxtralChatModel,
            'Expected at least one non-Voxtral Mistral chat model for the comparison.'
        );
        $this->assertFalse(
            $this->supportsInputModalityCombination(
                $nonVoxtralChatModel,
                [ModalityEnum::text(), ModalityEnum::audio()]
            ),
            sprintf(
                'Expected %s NOT to advertise an [text, audio] input modality combination.',
                $nonVoxtralChatModel->getId()
            )
        );
    }

    public function testChatCompletionAcceptsDocumentUrlPart(): void
    {
        $document = new File(self::DOCUMENT_URL, 'application/pdf');

        $prompt = [
            new Message(MessageRoleEnum::user(), [
                new MessagePart('Summarize this document in a single short sentence.'),
                new MessagePart($document),
            ]),
        ];

        $result = AiClient::prompt($prompt, $this->registry)
            ->usingProvider('mistral')
            ->usingModelPreference('mistral-small-latest')
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);

        $text = trim($result->toText());
        $this->assertNotSame('', $text, 'Expected a non-empty response for document summarization.');
        $this->assertGreaterThan(
            20,
            strlen($text),
            sprintf('Expected a substantive summary, got "%s".', $text)
        );
    }

    private function findModelById(string $id): ?ModelMetadata
    {
        foreach ($this->models as $model) {
            if ($model->getId() === $id) {
                return $model;
            }
        }
        return null;
    }

    /**
     * @param list<ModalityEnum> $expected
     */
    private function supportsInputModalityCombination(ModelMetadata $model, array $expected): bool
    {
        $expectedValues = array_map(static fn (ModalityEnum $m): string => $m->value, $expected);
        sort($expectedValues);

        foreach ($model->getSupportedOptions() as $option) {
            if (!$option->getName()->is(OptionEnum::inputModalities())) {
                continue;
            }

            foreach ($option->getSupportedValues() ?? [] as $combination) {
                if (!is_array($combination)) {
                    continue;
                }
                $values = array_values(array_filter(array_map(
                    static fn ($m): ?string => $m instanceof ModalityEnum ? $m->value : null,
                    $combination
                )));
                sort($values);

                if ($values === $expectedValues) {
                    return true;
                }
            }
        }

        return false;
    }
}
