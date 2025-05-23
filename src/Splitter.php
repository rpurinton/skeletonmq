<?php

declare(strict_types=1);

namespace RPurinton\SkeletonMQ;

/**
 * Class Splitter
 * Splits long messages into chunks suitable for Discord (max 2000 chars).
 */
class Splitter
{
    /**
     * Maximum message length for Discord.
     */
    private const MAX_LENGTH = 2000;

    /**
     * Split a message into chunks, attempting to break at newlines or periods.
     *
     * @param string $message The message to split.
     * @return array<int, string> Array of message chunks.
     */
    public static function split(string $message): array
    {
        $message = trim($message);
        if ($message === '') return [];
        if (mb_strlen($message) <= self::MAX_LENGTH) return [$message];
        $chunks = [];
        while (mb_strlen($message) > self::MAX_LENGTH) {
            $chunk = mb_substr($message, 0, self::MAX_LENGTH);
            $splitIndex = mb_strrpos($chunk, "\n");
            if ($splitIndex === false) $splitIndex = mb_strrpos($chunk, ".");
            if ($splitIndex === false || $splitIndex < 1) $splitIndex = self::MAX_LENGTH;
            else $splitIndex++;
            $part = trim(mb_substr($message, 0, $splitIndex));
            $chunks[] = $part;
            $message = trim(mb_substr($message, $splitIndex));
        }
        if ($message !== '') $chunks[] = $message;
        return $chunks;
    }
}
