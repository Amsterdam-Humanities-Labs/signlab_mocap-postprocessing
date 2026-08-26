<?php
namespace App\services;

/**
 * Shared path sanitisation and containment helpers for filesystem operations.
 * Used by multiple services to ensure consistent, defense-in-depth protection
 * against traversal and injection attacks.
 */
trait PathSafety {
    /**
     * Reduce a (DB-sourced, but still untrusted) name to a safe bare basename of
     * safe characters. Rejects path separators outright (defense-in-depth), then
     * applies basename() to remove any directory prefix, then validates with a
     * strict character class that blocks traversal and glob metacharacters.
     */
    private function safeName(string $name): string {
        // Reject if input contains path separators (blocks traversal attempts like ../)
        if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return '';
        }
        $base = basename($name);
        if ($base === '.' || $base === '..') {
            return '';
        }
        return preg_match('/^[A-Za-z0-9._-]+$/', $base) ? $base : '';
    }

    /** True only if $path resolves to a real file inside $baseDir. */
    private function isWithin(string $path, string $baseDir): bool {
        $real = realpath($path);
        $base = realpath($baseDir);
        return $real !== false && $base !== false
            && strncmp($real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0;
    }
}
