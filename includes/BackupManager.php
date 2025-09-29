<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class BackupManager {
    protected $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function run(string $destPath): bool {
        $ts = wp_date('Ymd-His');
        $workDir = rtrim($destPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nexo-' . $ts;

        if (!@mkdir($workDir, 0755, true)) {
            throw new \RuntimeException('No se pudo crear el directorio de trabajo: '.$workDir);
        }

        // 1) Dump de base de datos (.sql.gz)
        $dbFile = $workDir . DIRECTORY_SEPARATOR . "db-{$ts}.sql.gz";
        $dumper = new DatabaseDumper();
        $dumper->dumpToGzip($dbFile);

        // 2) Archivos del sitio (zip)
        $zipFile = $workDir . DIRECTORY_SEPARATOR . "files-{$ts}.zip";
        $this->zipSite($zipFile);

        // 3) Manifiesto
        $manifest = [
            'version' => NEXO_BACKUP_LITE_VER,
            'created_at' => $ts,
            'site_url' => site_url(),
            'wp_version' => get_bloginfo('version'),
            'db' => basename($dbFile),
            'files' => basename($zipFile),
            'retain_days' => intval($this->settings['retain_days'] ?? 7),
        ];
        file_put_contents($workDir . DIRECTORY_SEPARATOR . 'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT));

        // 4) Retención simple (borrar carpetas antiguas)
        $this->applyRetention(dirname($workDir), $manifest['retain_days']);

        return true;
    }

    protected function zipSite(string $zipPath): void {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive no disponible en este PHP.');
        }

        $exDirs = array_map('strtolower', $this->settings['exclude_dirs'] ?? []);
        $exPatterns = $this->settings['exclude_patterns'] ?? [];

        $root = ABSPATH; // incluye wp-admin/wp-includes/wp-content
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('No se pudo crear el ZIP.');
        }

        // añadir wp-config.php
        $cfg = ABSPATH . 'wp-config.php';
        if (is_readable($cfg)) $zip->addFile($cfg, 'wp-config.php');

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($rii as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $rel  = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);

            // excluir directorios
            $parts = explode(DIRECTORY_SEPARATOR, strtolower($rel));
            if (!empty($parts) && in_array($parts[0], $exDirs, true)) continue;

            // excluir patrones simples
            foreach ($exPatterns as $pat) {
                if (fnmatch($pat, basename($rel))) {
                    continue 2;
                }
            }

            // evitar incluir la carpeta de destino si cae bajo webroot (no debería)
            if (str_starts_with(realpath($path) ?: '', realpath($this->settings['dest_path'] ?? '') ?: '___')) {
                continue;
            }

            if ($file->isFile() && is_readable($path)) {
                $zip->addFile($path, $rel);
            }
        }

        $zip->close();
    }

    protected function applyRetention(string $destBase, int $retainDays): void {
        if ($retainDays < 1) return;
        $cut = time() - ($retainDays * DAY_IN_SECONDS);

        foreach (glob($destBase . DIRECTORY_SEPARATOR . 'nexo-*') as $dir) {
            if (!is_dir($dir)) continue;
            if (filemtime($dir) < $cut) {
                $this->rrmdir($dir);
            }
        }
    }

    protected function rrmdir(string $dir): void {
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $ri = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($ri as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}