<?php
namespace App\config;

/**
 * Central access to storage paths. Defaults live in /paths.php (documented
 * there); per-machine overrides go in /paths.local.php (gitignored).
 *
 *   Paths::dir('fbx_processed')   // absolute dir, guaranteed trailing slash
 *   Paths::get('remote_fbx_base_url')
 *   Paths::toUrl('/web/gebarenoverleg_media/fbx/CC/x.glb') // '/gebarenoverleg_media/fbx/CC/x.glb'
 */
class Paths {
    private static ?array $config = null;

    /** Override everything (tests). Pass null to reload from disk. */
    public static function set(?array $config): void {
        self::$config = $config;
    }

    public static function all(): array {
        if (self::$config === null) {
            $root = dirname(__DIR__, 2);
            $config = require $root . '/paths.php';
            if (is_file($root . '/paths.local.php')) {
                $local = require $root . '/paths.local.php';
                if (is_array($local)) {
                    $config = array_merge($config, $local);
                }
            }
            self::$config = $config;
        }
        return self::$config;
    }

    public static function get(string $key): string {
        $all = self::all();
        if (!array_key_exists($key, $all)) {
            throw new \RuntimeException("Unknown path config key '$key' (see paths.php)");
        }
        return (string)$all[$key];
    }

    /** Directory value normalised to exactly one trailing slash. */
    public static function dir(string $key): string {
        return rtrim(self::get($key), '/') . '/';
    }

    /**
     * Convert an absolute disk path below `web_root` to a browser URL.
     * Returns '' if the path is not below web_root (it cannot be served).
     */
    public static function toUrl(string $diskPath): string {
        $root = rtrim(self::get('web_root'), '/');
        if ($root !== '' && strpos($diskPath, $root . '/') === 0) {
            return substr($diskPath, strlen($root));
        }
        return '';
    }

    /** True if $path is inside the web root (i.e. a local, served file). */
    public static function isUnderWebRoot(string $path): bool {
        return self::toUrl($path) !== '';
    }
}
