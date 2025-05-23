<?php

namespace RPurinton\Skeleton;

/**
 * Class Commands
 * Handles loading and parsing command JSON files.
 */
class CommandLoader
{
    /**
     * Default path to the commands directory.
     */
    const PATH = __DIR__ . '/../commands/';

    /**
     * Get all available commands as an array.
     *
     * @param string|null $path Optional custom path to commands directory.
     * @return array<int, array>|null Array of command data or null if not found.
     * @throws \RuntimeException if the directory or files are missing or invalid.
     */
    public static function get(?string $path = null): ?array
    {
        $dir = $path ?? self::PATH;
        if (!is_dir($dir)) {
            throw new \RuntimeException("Commands folder not found at: {$dir}");
        }
        $files = glob($dir . '*.json');
        if (!$files) {
            throw new \RuntimeException("No command files found in {$dir}");
        }
        $commands = [];
        foreach ($files as $file) {
            $command = self::getCommand($file);
            if ($command !== null) {
                $commands[] = $command;
            }
        }
        return empty($commands) ? null : $commands;
    }

    /**
     * Parse a single command JSON file.
     *
     * @param string $file Path to the JSON file.
     * @return array|null Parsed command data or null on failure.
     * @throws \RuntimeException if the file is unreadable or contains invalid JSON.
     */
    private static function getCommand(string $file): ?array
    {
        if (!is_readable($file)) {
            throw new \RuntimeException("Failed to read command file: {$file}");
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Failed to read command file: {$file}");
        }
        $command = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($command)) {
            throw new \RuntimeException("Invalid JSON in command file: {$file}. Error: " . json_last_error_msg());
        }
        return $command;
    }
}
