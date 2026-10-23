<?php

declare(strict_types=1);

namespace SaarniLauri\AiProviderForMistral\Text;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;

/**
 * Splits text into chunks that fit within a character limit.
 *
 * Speech quality degrades on long requests, so the Mistral text-to-speech
 * model narrates long text in several requests. Where a chunk begins and ends
 * is audible: a split mid-sentence produces a seam that stands out, all the
 * more so as Mistral carries no context across requests. Boundaries are therefore preferred in
 * order -- paragraph, sentence, whitespace -- and a word is only broken when it
 * alone exceeds the limit.
 *
 * Splitting is lossless. {@see self::join()} on the result of
 * {@see self::split()} reproduces the input exactly, because audio is generated
 * per chunk and any text the splitter drops is text the listener never hears.
 *
 * Adapted from the AI Provider for ElevenLabs, where it was contributed by
 * Jake Spurlock (https://github.com/whyisjake/ai-provider-for-elevenlabs).
 *
 * @since n.e.x.t
 */
final class TextChunker
{
    /**
     * Splits text into chunks no longer than the limit.
     *
     * @since n.e.x.t
     *
     * @param string $text  The text to split.
     * @param int    $limit Maximum characters per chunk.
     * @return list<string> The chunks, in order. Empty when the text has no content.
     * @throws InvalidArgumentException If the limit is not positive.
     */
    public static function split(string $text, int $limit): array
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Developer-facing exception message, never echoed.
        if ($limit < 1) {
            throw new InvalidArgumentException(
                sprintf('The chunk limit must be a positive number of characters, got %d.', $limit)
            );
        }
        // phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

        if (trim($text) === '') {
            return [];
        }

        if (mb_strlen($text) <= $limit) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while (mb_strlen($remaining) > $limit) {
            $splitAt = self::findSplitPosition(mb_substr($remaining, 0, $limit));

            $chunks[] = mb_substr($remaining, 0, $splitAt);
            $remaining = mb_substr($remaining, $splitAt);
        }

        if ($remaining !== '') {
            $chunks[] = $remaining;
        }

        return $chunks;
    }

    /**
     * Rejoins chunks produced by {@see self::split()}.
     *
     * Splitting preserves every character, including the whitespace at a
     * boundary, so joining is a plain concatenation. It exists as a named
     * operation so the round trip can be asserted directly.
     *
     * @since n.e.x.t
     *
     * @param list<string> $chunks The chunks to join.
     * @return string The rejoined text.
     */
    public static function join(array $chunks): string
    {
        return implode('', $chunks);
    }

    /**
     * Finds how many characters of a candidate chunk to keep.
     *
     * Works on a window already truncated to the limit, and returns the offset
     * just past the best boundary within it.
     *
     * @since n.e.x.t
     *
     * @param string $window The candidate chunk, no longer than the limit.
     * @return int The number of characters to take, always at least 1.
     */
    private static function findSplitPosition(string $window): int
    {
        $length = mb_strlen($window);

        // A paragraph break is the least audible place to split.
        $paragraph = mb_strrpos($window, "\n\n");
        if ($paragraph !== false && $paragraph > 0) {
            return $paragraph + 2;
        }

        // Then the end of a sentence, keeping the terminator with its sentence.
        $sentence = self::lastSentenceEnd($window);
        if ($sentence !== null) {
            return $sentence;
        }

        // Then any whitespace, keeping it so the join stays lossless.
        for ($i = $length - 1; $i > 0; $i--) {
            if (preg_match('/\s/u', mb_substr($window, $i, 1)) === 1) {
                return $i + 1;
            }
        }

        // A single token longer than the limit; break it rather than stall.
        return $length;
    }

    /**
     * Finds the offset just past the last sentence terminator in a window.
     *
     * @since n.e.x.t
     *
     * @param string $window The candidate chunk.
     * @return int|null The offset just past the terminator, or null if there is none.
     */
    private static function lastSentenceEnd(string $window): ?int
    {
        $length = mb_strlen($window);

        for ($i = $length - 1; $i > 0; $i--) {
            $char = mb_substr($window, $i, 1);

            if (!in_array($char, ['.', '!', '?', '。', '！', '？'], true)) {
                continue;
            }

            // Take any whitespace that follows, so the next chunk starts on the
            // sentence rather than on a stray space.
            $end = $i + 1;
            while ($end < $length && preg_match('/\s/u', mb_substr($window, $end, 1)) === 1) {
                $end++;
            }

            return $end;
        }

        return null;
    }
}
