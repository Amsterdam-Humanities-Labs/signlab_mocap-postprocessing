<?php
namespace App\services;

/**
 * Resolves every file that belongs in one take's download bundle: the ELAN
 * annotation and its subtitle sidecars (delegated to EafLocator), the
 * post-processed animation, and a reference video.
 *
 * Filesystem-pure by design — the MKV filename is supplied by the caller from
 * MocapFile::getRightVideos(), so this class needs no database and can be
 * tested against fixture directories.
 *
 * The annotation is the gate: a take with no take-level .eaf yields an empty
 * bundle, and the caller excludes it. The other assets are optional — a take
 * whose GLB was never exported still ships, with the gap reported in 'missing'.
 */
class TakeBundleLocator {
    private EafLocator $eafLocator;
    private string $ppDir;
    private string $miniDir;
    private string $mkvDir;

    public function __construct(
        EafLocator $eafLocator,
        string $ppDir   = '/web/gebarenoverleg_media/fbx/post_processed/',
        // "blackamgic" (m/a transposed) is the real directory name on disk —
        // a symlink to /mnt/bigstorage/blackmagic_filesMini/. Do not "correct" it.
        string $miniDir = '/web/gebarenoverleg_media/blackamgic_filesMini/',
        string $mkvDir  = '/mnt/bigstorage/razerFiles/'
    ) {
        $this->eafLocator = $eafLocator;
        $this->ppDir   = rtrim($ppDir, '/') . '/';
        $this->miniDir = rtrim($miniDir, '/') . '/';
        $this->mkvDir  = rtrim($mkvDir, '/') . '/';
    }

    /**
     * $ctx keys: take, pp_filename, capture_date, mkv_name (any may be null).
     * Returns ['files' => [['path' => …, 'entry' => …], …], 'missing' => [...]].
     */
    public function bundleForTake(array $ctx): array {
        $take = $this->safeName((string)($ctx['take'] ?? ''));
        if ($take === '') {
            return ['files' => [], 'missing' => []];
        }

        $eafPaths = $this->eafLocator->filesForTake($take);
        if (empty($eafPaths)) {
            return ['files' => [], 'missing' => []];
        }

        $files = [];
        foreach ($eafPaths as $path) {
            $files[] = ['path' => $path, 'entry' => $take . '/' . basename($path)];
        }

        $missing = [];

        // Post-processed animation, keyed by filename_pp — which can differ
        // from the take name, so it must never be derived from $take.
        $pp = $this->safeName((string)($ctx['pp_filename'] ?? ''));
        if ($pp === '') {
            $missing[] = 'post-processed .fbx';
            $missing[] = 'post-processed .glb';
        } else {
            $fbxPath = $this->ppDir . $pp;
            if (is_file($fbxPath) && $this->isWithin($fbxPath, $this->ppDir)) {
                $files[] = ['path' => $fbxPath, 'entry' => $take . '/' . $pp];
            } else {
                $missing[] = 'post-processed .fbx';
            }

            $glb = preg_replace('/\.fbx$/i', '.glb', $pp);
            $glbPath = $this->ppDir . $glb;
            if ($glb !== $pp && is_file($glbPath) && $this->isWithin($glbPath, $this->ppDir)) {
                $files[] = ['path' => $glbPath, 'entry' => $take . '/' . $glb];
            } else {
                $missing[] = 'post-processed .glb';
            }
        }

        // Reference video: the small Mini MP4 first, the RIGHT MKV as fallback.
        $date = preg_replace('/[^0-9-]/', '', (string)($ctx['capture_date'] ?? ''));
        $miniPath = $date !== '' ? $this->miniDir . $date . '/' . $take . '.mp4' : '';
        $mkvName  = $this->safeName((string)($ctx['mkv_name'] ?? ''));
        $mkvPath  = $mkvName !== '' ? $this->mkvDir . $mkvName : '';

        if ($miniPath !== '' && is_file($miniPath) && $this->isWithin($miniPath, $this->miniDir)) {
            $files[] = ['path' => $miniPath, 'entry' => $take . '/' . basename($miniPath)];
        } elseif ($mkvPath !== '' && is_file($mkvPath) && $this->isWithin($mkvPath, $this->mkvDir)) {
            $files[] = ['path' => $mkvPath, 'entry' => $take . '/' . $mkvName];
        } else {
            $missing[] = 'reference video';
        }

        return ['files' => $files, 'missing' => $missing];
    }

    /**
     * Reduce a DB-sourced name to a safe bare basename. Anything containing a
     * path separator is rejected outright rather than normalised, and the strict
     * character class keeps traversal and shell/glob metacharacters out of every
     * path this class builds.
     */
    private function safeName(string $name): string {
        if ($name === '' || strpos($name, '/') !== false || strpos($name, '\\') !== false) {
            return '';
        }
        return preg_match('/^[A-Za-z0-9._-]+$/', $name) ? $name : '';
    }

    /** True only if $path resolves to a real file inside $baseDir. */
    private function isWithin(string $path, string $baseDir): bool {
        $real = realpath($path);
        $base = realpath($baseDir);
        return $real !== false && $base !== false
            && strncmp($real . DIRECTORY_SEPARATOR, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) === 0;
    }
}
