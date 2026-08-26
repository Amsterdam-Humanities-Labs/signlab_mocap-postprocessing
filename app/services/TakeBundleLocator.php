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
    use PathSafety;

    private EafLocator $eafLocator;
    private string $ppDir;
    private string $miniDir;
    private string $mkvDir;

    public function __construct(
        EafLocator $eafLocator,
        ?string $ppDir   = null,   // Paths 'fbx_processed'
        ?string $miniDir = null,   // Paths 'blackmagic_mini' (note the "blackamgic" spelling there)
        ?string $mkvDir  = null    // Paths 'razer_mkv'
    ) {
        $this->eafLocator = $eafLocator;
        $this->ppDir   = rtrim($ppDir   ?? \App\config\Paths::get('fbx_processed'),   '/') . '/';
        $this->miniDir = rtrim($miniDir ?? \App\config\Paths::get('blackmagic_mini'), '/') . '/';
        $this->mkvDir  = rtrim($mkvDir  ?? \App\config\Paths::get('razer_mkv'),       '/') . '/';
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

}
