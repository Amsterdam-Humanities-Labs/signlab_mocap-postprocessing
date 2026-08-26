<?php
namespace App\services;

/**
 * Locates the ELAN annotation file and its sidecar subtitle files for one mocap
 * take inside the shared annotation directory (/web/zin/eaf/zin/).
 *
 * Take-level only: take "M20240925_1858_260319_0" resolves to
 * M20240925_1858_260319_0.eaf plus every M20240925_1858_260319_0_*.srt.
 * The broadcast-level M20240925_1858.eaf is deliberately NOT a fallback — it
 * covers the whole broadcast and is not time-aligned to an individual take, so
 * shipping it would be silently wrong rather than merely missing.
 */
class EafLocator {
    use PathSafety;

    private string $eafDir;

    /** @param string|null $eafDir defaults to Paths 'eaf_dir' */
    public function __construct(?string $eafDir = null) {
        $this->eafDir = rtrim($eafDir ?? \App\config\Paths::get('eaf_dir'), '/') . '/';
    }

    /**
     * Absolute paths of the take's .eaf plus its non-backup .srt sidecars.
     * Returns [] when the take-level .eaf is missing: the SRTs alone are not
     * useful without the annotation they belong to.
     */
    public function filesForTake(string $takeBasename): array {
        $safe = $this->safeName($takeBasename);
        if ($safe === '') {
            return [];
        }

        $eafPath = $this->eafDir . $safe . '.eaf';
        if (!is_file($eafPath) || !$this->isWithin($eafPath, $this->eafDir)) {
            return [];
        }

        $found = [$eafPath];
        foreach (glob($this->eafDir . $safe . '_*.srt') ?: [] as $srt) {
            // The directory keeps timestamped history next to the live files.
            if (strpos(basename($srt), '_backup_') !== false) {
                continue;
            }
            if (is_file($srt) && $this->isWithin($srt, $this->eafDir)) {
                $found[] = $srt;
            }
        }
        return $found;
    }

    /**
     * Cheap existence check for a take's annotation, skipping the SRT glob that
     * filesForTake() performs. Used by the file-list filter, which asks this
     * question once per candidate take.
     */
    public function hasEaf(string $takeBasename): bool {
        $safe = $this->safeName($takeBasename);
        if ($safe === '') {
            return false;
        }
        $path = $this->eafDir . $safe . '.eaf';
        return is_file($path) && $this->isWithin($path, $this->eafDir);
    }

}
