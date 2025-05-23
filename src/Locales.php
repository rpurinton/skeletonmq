<?php

declare(strict_types=1);

namespace RPurinton\SkeletonMQ;

/**
 * Class Locales
 * Handles loading and parsing locale JSON files.
 */
class Locales
{
    /**
     * Default path to the locales directory.
     */
    const PATH = __DIR__ . '/../locales/';

    /**
     * Get all available locales as an associative array.
     *
     * @param string|null $path Optional custom path to locales directory.
     * @return array<string, array>|null Associative array of locale data or null if not found.
     */
    public static function get(?string $path = null): ?array
    {
        $dir = $path ?? self::PATH;
        if (!is_dir($dir)) {
            return null;
        }
        $files = glob($dir . '*.json');
        if (!$files) {
            return null;
        }
        $locales = [];
        foreach ($files as $file) {
            $locale = self::getLocale($file);
            if ($locale !== null) {
                $locales[basename($file, '.json')] = $locale;
            }
        }
        return empty($locales) ? null : $locales;
    }

    /**
     * Parse a single locale JSON file.
     *
     * @param string $file Path to the JSON file.
     * @return array|null Parsed locale data or null on failure.
     */
    private static function getLocale(string $file): ?array
    {
        if (!is_readable($file)) {
            return null;
        }
        $content = file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $locale = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($locale)) {
            return null;
        }
        return $locale;
    }
}
