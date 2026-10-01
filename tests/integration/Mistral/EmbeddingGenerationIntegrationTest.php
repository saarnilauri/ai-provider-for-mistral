<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Mistral;

use PHPUnit\Framework\TestCase;
use SaarniLauri\AiProviderForMistral\Provider\ProviderForMistral;
use SaarniLauri\AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\Embedding;
use WordPress\AiClient\Results\DTO\EmbeddingResult;

/**
 * Integration tests for Mistral embedding generation.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * @group integration
 * @group mistral
 *
 * @coversNothing
 */
class EmbeddingGenerationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * The vector length mistral-embed always returns.
     */
    private const MISTRAL_EMBED_DIMENSIONS = 1024;

    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        if (!ProviderForMistral::supportsEmbeddingGeneration()) {
            $this->markTestSkipped(sprintf(
                'Embedding generation needs AI client %s or newer, and %s is installed.',
                ProviderForMistral::EMBEDDING_GENERATION_MIN_CLIENT_VERSION,
                AiClient::VERSION
            ));
        }

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);
    }

    /**
     * Tests that a single text produces one vector of the model's fixed length.
     */
    public function testSingleEmbedding(): void
    {
        $embedding = AiClient::input('WordPress is a content management system.', $this->registry)
            ->usingModel(ProviderForMistral::model('mistral-embed'))
            ->generateEmbedding();

        $this->assertInstanceOf(Embedding::class, $embedding);
        $this->assertSame(self::MISTRAL_EMBED_DIMENSIONS, $embedding->getDimensions());
        $this->assertCount(self::MISTRAL_EMBED_DIMENSIONS, $embedding->getValues());
        $this->assertContainsOnly('float', $embedding->getValues());
    }

    /**
     * Tests that several texts are embedded in one request, one vector each.
     */
    public function testBatchEmbeddingReturnsOneVectorPerInput(): void
    {
        $result = AiClient::input(
            ['The cat sat on the mat.', 'A feline rested on the rug.', 'Kubernetes schedules containers.'],
            $this->registry
        )
            ->usingModel(ProviderForMistral::model('mistral-embed'))
            ->generateEmbeddingResult();

        $this->assertInstanceOf(EmbeddingResult::class, $result);
        $this->assertSame(self::MISTRAL_EMBED_DIMENSIONS, $result->getDimensions());

        $embeddings = $result->getEmbeddings();
        $this->assertCount(3, $embeddings);
        foreach ($embeddings as $embedding) {
            $this->assertCount(self::MISTRAL_EMBED_DIMENSIONS, $embedding->getValues());
        }

        $this->assertGreaterThan(0, $result->getTokenUsage()->getPromptTokens());
    }

    /**
     * Tests that more inputs than the endpoint takes in one request still come back whole.
     *
     * The endpoint rejects more than 256 inputs per request, so these are split into two
     * requests. The last input repeats the first, so matching vectors at both ends show
     * the second batch was put back in its place.
     */
    public function testInputsBeyondTheRequestLimitAreSplitAndRecombined(): void
    {
        $texts = array_map(static fn (int $index): string => "Sentence number {$index}.", range(0, 298));
        $texts[] = $texts[0];

        $result = AiClient::input($texts, $this->registry)
            ->usingModel(ProviderForMistral::model('mistral-embed'))
            ->generateEmbeddingResult();

        $embeddings = $result->getEmbeddings();
        $this->assertCount(300, $embeddings);
        $this->assertGreaterThan(
            0.999,
            $this->cosineSimilarity($embeddings[0]->getValues(), $embeddings[299]->getValues())
        );
        $this->assertGreaterThan(0, $result->getTokenUsage()->getPromptTokens());
    }

    /**
     * Tests that the vectors line up with the inputs that produced them.
     *
     * The two sentences about a cat should sit closer together than either does to the
     * sentence about container scheduling. That only holds if each vector is paired with
     * the text it was generated from, which is what makes this worth asserting against
     * the live API rather than a fixture.
     */
    public function testVectorsCorrespondToTheirInputs(): void
    {
        $embeddings = AiClient::input(
            ['The cat sat on the mat.', 'A feline rested on the rug.', 'Kubernetes schedules containers.'],
            $this->registry
        )
            ->usingModel(ProviderForMistral::model('mistral-embed'))
            ->generateEmbeddings();

        $this->assertCount(3, $embeddings);

        $catToFeline = $this->cosineSimilarity($embeddings[0]->getValues(), $embeddings[1]->getValues());
        $catToKubernetes = $this->cosineSimilarity($embeddings[0]->getValues(), $embeddings[2]->getValues());

        $this->assertGreaterThan(
            $catToKubernetes,
            $catToFeline,
            'The two sentences about a cat should be more alike than either is to the unrelated one.'
        );
    }

    /**
     * Tests that codestral-embed honours a requested vector length.
     */
    public function testRequestedDimensionsAreHonouredWhereSupported(): void
    {
        $embedding = AiClient::input('function add($a, $b) { return $a + $b; }', $this->registry)
            ->usingModel(ProviderForMistral::model('codestral-embed'))
            ->usingDimensions(256)
            ->generateEmbedding();

        $this->assertSame(256, $embedding->getDimensions());
        $this->assertCount(256, $embedding->getValues());
    }

    /**
     * Tests that the embedding models are discoverable with the embedding capability.
     */
    public function testEmbeddingModelsAreListedWithTheEmbeddingCapability(): void
    {
        $requirements = new ModelRequirements([CapabilityEnum::embeddingGeneration()], []);

        $models = $this->registry->findProviderModelsMetadataForSupport('mistral', $requirements);

        $this->assertNotEmpty($models, 'Expected Mistral to expose at least one embedding model.');

        $ids = array_map(static fn (ModelMetadata $model): string => $model->getId(), $models);

        $this->assertContains('mistral-embed', $ids);
        $this->assertContains('codestral-embed', $ids);
        $this->assertNotContains(
            'mistral-large-latest',
            $ids,
            'A chat model should not be listed as an embedding model.'
        );
    }

    /**
     * Returns the cosine similarity of two vectors.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     * @return float
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $dot = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        foreach ($a as $index => $value) {
            $dot += $value * $b[$index];
            $magnitudeA += $value * $value;
            $magnitudeB += $b[$index] * $b[$index];
        }

        return $dot / (sqrt($magnitudeA) * sqrt($magnitudeB));
    }
}
