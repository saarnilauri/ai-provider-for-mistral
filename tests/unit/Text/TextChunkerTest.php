<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Tests\Unit\Text;

use SaarniLauri\AiProviderForMistral\Text\TextChunker;
use PHPUnit\Framework\TestCase;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Unit tests for TextChunker.
 *
 * The property that matters most is losslessness: rejoining the chunks must
 * reproduce the input exactly. Audio is generated per chunk, so a splitter that
 * drops or duplicates text produces narration that does not match the article,
 * which is far worse than a request failing outright.
 *
 * @covers \SaarniLauri\AiProviderForMistral\Text\TextChunker
 */
class TextChunkerTest extends TestCase
{
    // ------------------------------------------------------------------
    // Text that fits
    // ------------------------------------------------------------------

    public function testTextWithinTheLimitIsReturnedAsASingleChunk(): void
    {
        $text = 'A short sentence.';

        $this->assertSame([$text], TextChunker::split($text, 100));
    }

    public function testTextExactlyAtTheLimitIsNotSplit(): void
    {
        $text = str_repeat('a', 50);

        $this->assertSame([$text], TextChunker::split($text, 50));
    }

    // ------------------------------------------------------------------
    // Boundary preference
    // ------------------------------------------------------------------

    public function testSplitsAtParagraphBreakWhenAvailable(): void
    {
        $first = 'First paragraph here.';
        $second = 'Second paragraph here.';
        $chunks = TextChunker::split($first . "\n\n" . $second, 30);

        $this->assertCount(2, $chunks);
        $this->assertStringContainsString('First paragraph', $chunks[0]);
        $this->assertStringNotContainsString('Second paragraph', $chunks[0]);
    }

    public function testSplitsAtSentenceEndWhenThereIsNoParagraphBreak(): void
    {
        $chunks = TextChunker::split('One. Two. Three. Four. Five. Six.', 14);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(14, mb_strlen($chunk));
        }

        // A sentence-aware split should not leave a chunk starting mid-sentence.
        $this->assertStringStartsWith('One.', $chunks[0]);
    }

    public function testSplitsAtWhitespaceWhenThereIsNoSentenceEnd(): void
    {
        $text = 'alpha bravo charlie delta echo foxtrot';
        $chunks = TextChunker::split($text, 12);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(12, mb_strlen($chunk));
        }

        // Boundary whitespace is kept at the end of a chunk so the join stays
        // lossless, which means no chunk should begin with it.
        foreach ($chunks as $chunk) {
            $this->assertSame(ltrim($chunk), $chunk, 'A chunk began with whitespace.');
        }

        $this->assertSame($text, TextChunker::join($chunks));
    }

    // ------------------------------------------------------------------
    // Losslessness and the limit
    // ------------------------------------------------------------------

    /**
     * @dataProvider losslessTextProvider
     */
    public function testRejoiningChunksReproducesTheInput(string $text, int $limit): void
    {
        $chunks = TextChunker::split($text, $limit);

        $this->assertSame($text, TextChunker::join($chunks));
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function losslessTextProvider(): array
    {
        return [
            'short'                => ['Hello world.', 100],
            'sentences'            => ['One. Two. Three. Four. Five.', 10],
            'paragraphs'           => ["Para one.\n\nPara two.\n\nPara three.", 12],
            'no punctuation'       => [str_repeat('word ', 40), 25],
            'single long word'     => [str_repeat('x', 200), 30],
            'trailing whitespace'  => ["Sentence one.  \n\n  Sentence two.", 15],
            'multibyte'            => ['Zwölf Boxkämpfer jagen Viktor quer über den großen Sylter Deich.', 20],
            'emoji'                => ['Hello 👋 world 🌍 again 🎉 and once more 🚀 here.', 15],
            'newlines only'        => ["a\nb\nc\nd\ne\nf\ng\nh", 4],
        ];
    }

    /**
     * @dataProvider losslessTextProvider
     */
    public function testNoChunkExceedsTheLimit(string $text, int $limit): void
    {
        foreach (TextChunker::split($text, $limit) as $chunk) {
            $this->assertLessThanOrEqual($limit, mb_strlen($chunk));
        }
    }

    /**
     * @dataProvider losslessTextProvider
     */
    public function testNoChunkIsEmpty(string $text, int $limit): void
    {
        foreach (TextChunker::split($text, $limit) as $chunk) {
            $this->assertNotSame('', $chunk);
        }
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function testAWordLongerThanTheLimitIsHardSplit(): void
    {
        $chunks = TextChunker::split(str_repeat('x', 25), 10);

        $this->assertSame(['xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxx'], $chunks);
    }

    public function testMultibyteTextNeverSplitsMidCharacter(): void
    {
        // Each character is multiple bytes; a byte-based split would corrupt them.
        $text = str_repeat('日本語のテキスト', 20);

        $chunks = TextChunker::split($text, 7);

        foreach ($chunks as $chunk) {
            $this->assertSame($chunk, mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'));
        }

        $this->assertSame($text, TextChunker::join($chunks));
    }

    public function testEmptyStringProducesNoChunks(): void
    {
        $this->assertSame([], TextChunker::split('', 100));
    }

    public function testWhitespaceOnlyStringProducesNoChunks(): void
    {
        $this->assertSame([], TextChunker::split("   \n\n  \t ", 100));
    }

    public function testJoinOfNoChunksIsAnEmptyString(): void
    {
        $this->assertSame('', TextChunker::join([]));
    }

    // ------------------------------------------------------------------
    // Error paths
    // ------------------------------------------------------------------

    /**
     * @dataProvider invalidLimitProvider
     */
    public function testANonPositiveLimitIsRejected(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        TextChunker::split('Some text.', $limit);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidLimitProvider(): array
    {
        return [
            'zero'     => [0],
            'negative' => [-10],
        ];
    }
}
