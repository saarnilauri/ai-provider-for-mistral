<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Integration\Traits;

/**
 * Trait providing shared functionality for integration tests.
 *
 * This trait provides utility methods for integration tests that make
 * real API calls to AI providers.
 */
trait IntegrationTestTrait
{
    /**
     * Default delay in seconds between tests that hit the live API.
     *
     * Tuned for Mistral's free tier (roughly one request per second per endpoint).
     * Override via the MISTRAL_TEST_DELAY environment variable; set to 0 to disable.
     */
    private const DEFAULT_TEST_DELAY_SECONDS = 3;

    /**
     * Skips the test if the specified environment variable is not set.
     *
     * When the key is present, also sleeps for MISTRAL_TEST_DELAY seconds
     * (default {@see self::DEFAULT_TEST_DELAY_SECONDS}) to pace test runs under
     * the provider's rate limits. The delay is per-test and applied in `setUp()`,
     * so it effectively spaces tests apart by at least that duration.
     *
     * @param string $envVar The name of the environment variable to check.
     */
    protected function requireApiKey(string $envVar): void
    {
        // Check both $_ENV (populated by symfony/dotenv) and getenv() (shell environment)
        $value = $_ENV[$envVar] ?? getenv($envVar);
        if ($value === false || $value === '' || $value === null) {
            $this->markTestSkipped("Skipping: {$envVar} environment variable is not set.");
        }

        $this->throttle();
    }

    /**
     * Sleeps for MISTRAL_TEST_DELAY seconds to pace API calls.
     *
     * Used automatically in `setUp()` via {@see self::requireApiKey()}, and should
     * also be called manually between multiple API calls inside a single test
     * method to avoid exceeding the provider's per-second rate limit.
     */
    protected function throttle(): void
    {
        $delaySeconds = $this->resolveTestDelaySeconds();
        if ($delaySeconds > 0) {
            sleep($delaySeconds);
        }
    }

    /**
     * Resolves the between-test delay from the MISTRAL_TEST_DELAY env var or falls back to the default.
     */
    private function resolveTestDelaySeconds(): int
    {
        $raw = $_ENV['MISTRAL_TEST_DELAY'] ?? getenv('MISTRAL_TEST_DELAY');
        if ($raw === false || $raw === '' || $raw === null) {
            return self::DEFAULT_TEST_DELAY_SECONDS;
        }
        return max(0, (int) $raw);
    }
}
