<?php

declare(strict_types=1);

namespace AiProviderForMistral\Tests\Integration\Mistral;

use AiProviderForMistral\Provider\ProviderForMistral;
use AiProviderForMistral\Tests\Integration\Traits\IntegrationTestTrait;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Common\Exception\TokenLimitReachedException;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\ProviderRegistry;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Integration tests for Mistral text generation.
 *
 * These tests make real API calls to Mistral and require the MISTRAL_API_KEY
 * environment variable to be set.
 *
 * @group integration
 * @group mistral
 *
 * @coversNothing
 */
class TextGenerationIntegrationTest extends TestCase
{
    use IntegrationTestTrait;

    private ProviderRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->requireApiKey('MISTRAL_API_KEY');

        $this->registry = new ProviderRegistry();
        $this->registry->registerProvider(ProviderForMistral::class);
    }

    /**
     * Tests basic text generation with a simple prompt.
     */
    public function testSimpleTextGeneration(): void
    {
        $result = AiClient::prompt('Say "hello" and nothing else.', $this->registry)
            ->usingProvider('mistral')
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertStringContainsStringIgnoringCase('hello', $result->toText());
    }

    /**
     * Tests text generation with a simple multi-turn chat.
     */
    public function testMultiTurnTextGeneration(): void
    {
        $result = AiClient::prompt([
            Message::fromArray([
                'role' => 'user',
                'parts' => [['text' => 'When was WordPress first released?']],
            ]),
            Message::fromArray([
                'role' => 'model',
                'parts' => [['text' => 'In 2003.']],
            ]),
            Message::fromArray([
                'role' => 'user',
                'parts' => [['text' => 'Who created it?']],
            ]),
        ], $this->registry)
            ->usingProvider('mistral')
            ->generateTextResult();

        $this->assertInstanceOf(GenerativeAiResult::class, $result);
        $this->assertStringContainsStringIgnoringCase('Matt Mullenweg', $result->toText());
    }

    /**
     * Tests that generateTextResult() throws TokenLimitReachedException when max_tokens is exceeded.
     */
    public function testTextGenerationThrowsOnTokenLimitReached(): void
    {
        $this->expectException(TokenLimitReachedException::class);

        AiClient::prompt('Count from 1 to 1000, writing each number on its own line.', $this->registry)
            ->usingProvider('mistral')
            ->usingMaxTokens(5)
            ->generateTextResult();
    }

    /**
     * Tests that text generation returns token usage information.
     */
    public function testTextGenerationReturnsTokenUsage(): void
    {
        $result = AiClient::prompt('Say "hello" and nothing else.', $this->registry)
            ->usingProvider('mistral')
            ->generateTextResult();

        $tokenUsage = $result->getTokenUsage();
        $this->assertGreaterThan(0, $tokenUsage->getPromptTokens());
        $this->assertGreaterThan(0, $tokenUsage->getCompletionTokens());
        $this->assertGreaterThan(0, $tokenUsage->getTotalTokens());
    }
}
