<?php
namespace Nexo\Backup;

use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use FilesystemIterator;

if (!defined('ABSPATH')) exit;

class BackupManager {
    protected array $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function run(string $destPath): bool {
        try {
            if (!is_dir($destPath) || !is_writable($destPath)) {
                throw new \RuntimeException("Ruta de destino inválida o no escribible: $destPath");
            }

            // Nombre base desde el patrón
            $base = pattern_render($this->settings['name_pattern'] ?? 'nexo-{YYYY}{MM}{DD}-{HH}{mm}{SS}');
            $workDir = trailingslashit($destPath) . $base;
            if (!@mkdir($workDir, 0755, true)) {
                throw new \RuntimeException("No se pudo crear el directorio de trabajo: $workDir");
            }

            // 1) Dump DB
            $dumper = new DatabaseDumper();
            $dbFile = $workDir . DIRECTORY_SEPARATOR . "db-$base.sql.gz";
            $dumper->dumpToGzip($dbFile);

            // 2) ZIP de archivos
            $zipFile = $workDir . DIRECTORY_SEPARATOR . "files-$base.zip";
            $this->zipSiteFiles($zipFile);

            // 3) Manifest con hashes
            $manifest = [
                'version'     => NEXO_BACKUP_LITE_VER,
                'created_at'  => wp_date('Ymd-His'),
                'site_url'    => site_url(),
                'wp_version'  => get_bloginfo('version'),
                'db'          => basename($dbFile),
                'db_sha256'   => $this->hashFile($dbFile),
                'files'       => basename($zipFile),
                'files_sha256'=> $this->hashFile($zipFile),
                'retain_days' => intval($this->settings['retain_days'] ?? 7),
                'base_name'   => $base,
                'pattern'     => $this->settings['name_pattern'] ?? '',
            ];
            file_put_contents($workDir . DIRECTORY_SEPARATOR . 'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT));

            // 4) Retención
            $this->applyRetention($destPath, $manifest['retain_days']);

            return true;
        } catch (\Throwable $e) {
            error_log('[Nexo Backup Lite] BackupManager error: '.$e->getMessage());
            return false;
        }
    }

    protected function zipSiteFiles(string $zipPath): void {
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)!==true) {
            throw new \RuntimeException("No se pudo crear el archivo ZIP: $zipPath");
        }

        $root = ABSPATH;
        $exDirs = array_map('strtolower', $this->settings['exclude_dirs'] ?? []);
        $exPatterns = $this->settings['exclude_patterns'] ?? [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile()) continue;
            $path = $file->getPathname();
            $rel  = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);

            $parts = explode(DIRECTORY_SEPARATOR, strtolower($rel));
            if (!empty($parts) && in_array($parts[0], $exDirs, true)) continue;

            foreach ($exPatterns as $pat) {
                if (fnmatch($pat, basename($rel))) continue 2;
            }

            $zip->addFile($path, $rel);
        }

        $zip->close();
    }

    protected function applyRetention(string $destPath, int $days): void {
        if ($days <= 0) return;
        $cut = time() - ($days * DAY_IN_SECONDS);
        foreach (glob($destPath . DIRECTORY_SEPARATOR . '*') as $dir) {
            if (is_dir($dir) && filemtime($dir) < $cut) {
                $this->rrmdir($dir);
            }
        }
    }

    protected function hashFile(string $path): ?string {
        if (!is_file($path)) return null;
        return hash_file('sha256', $path);
    }

    protected function rrmdir(string $dir): void {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}