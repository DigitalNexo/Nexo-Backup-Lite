<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Copies {
    const CAP = 'manage_options';
    const SLUG = 'nexo-backup-copies';

    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_post_nexo_backup_download', [$this, 'handleDownload']);
        add_action('admin_post_nexo_backup_delete',   [$this, 'handleDelete']);
        add_action('admin_post_nexo_backup_view',     [$this, 'handleViewManifest']);
    }

    public function menu() {
        add_submenu_page(
            'tools.php',
            'Copias de seguridad (Nexo Backup)',
            'Copias Nexo Backup',
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render() {
        if (!current_user_can(self::CAP)) wp_die('Permisos insuficientes');

        $settings = get_option(NEXO_BACKUP_LITE_OPTION, []);
        $destPath = rtrim($settings['dest_path'] ?? '', DIRECTORY_SEPARATOR);

        echo '<div class="wrap"><h1>Copias de seguridad</h1>';

        if (!$destPath || !is_dir($destPath)) {
            echo '<div class="notice notice-warning"><p>Configura primero la <strong>Ruta de destino</strong> en Ajustes → Nexo Backup Lite.</p></div></div>';
            return;
        }

        $backups = $this->discoverBackups($destPath);
        if (!$backups) {
            echo '<p>No se han encontrado copias en <code>'.esc_html($destPath).'</code>.</p></div>';
            return;
        }

        echo '<p>Ruta: <code>'.esc_html($destPath).'</code></p>';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead><tr>
                <th>Fecha</th>
                <th>Carpeta</th>
                <th>DB</th>
                <th>Archivos</th>
                <th>Tamaño</th>
                <th>SHA-256</th>
                <th>Acciones</th>
              </tr></thead><tbody>';

        foreach ($backups as $b) {
            $manifestUrl = wp_nonce_url(
                admin_url('admin-post.php?action=nexo_backup_view&dir=' . urlencode($b['dir'])),
                'nexo_backup_view_'.$b['dir']
            );

            $dbDl = $b['db_path'] ? wp_nonce_url(
                admin_url('admin-post.php?action=nexo_backup_download&type=db&path=' . urlencode($b['db_path'])),
                'nexo_backup_download_'.$b['db_path']
            ) : '';

            $filesDl = $b['zip_path'] ? wp_nonce_url(
                admin_url('admin-post.php?action=nexo_backup_download&type=zip&path=' . urlencode($b['zip_path'])),
                'nexo_backup_download_'.$b['zip_path']
            ) : '';

            $delUrl = wp_nonce_url(
                admin_url('admin-post.php?action=nexo_backup_delete&dir=' . urlencode($b['dir'])),
                'nexo_backup_delete_'.$b['dir']
            );

            echo '<tr>';
            echo '<td>'.esc_html($b['date']).'</td>';
            echo '<td><code>'.esc_html(basename($b['dir'])).'</code></td>';
            echo '<td>'.($dbDl ? '<a class="button button-small" href="'.esc_url($dbDl).'">Descargar DB</a>' : '-').'</td>';
            echo '<td>'.($filesDl ? '<a class="button button-small" href="'.esc_url($filesDl).'">Descargar ZIP</a>' : '-').'</td>';
            echo '<td>'.esc_html($b['size_h']).'</td>';
            echo '<td><code style="font-size:11px">'.esc_html($b['hash']).'</code></td>';
            echo '<td>
                    <a class="button button-small" href="'.esc_url($manifestUrl).'">Ver manifest</a>
                    <a class="button button-small" style="margin-left:6px" href="'.esc_url($delUrl).'"
                       onclick="return confirm(\'¿Seguro que quieres borrar esta copia?\')">Borrar</a>
                 </td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '<p class="description">El hash SHA-256 se calcula sobre <code>db.sql.gz</code> y <code>files.zip</code> concatenados si existen ambos.</p>';
        echo '</div>';
    }

    /**
     * Devuelve un array de copias con:
     * - dir, date, db_path, zip_path, size, size_h, hash
     */
    protected function discoverBackups(string $destPath): array {
        $list = [];
        foreach (glob($destPath . DIRECTORY_SEPARATOR . 'nexo-*') as $dir) {
            if (!is_dir($dir)) continue;

            $manifest = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
            $db = $this->firstMatch($dir, '/^db-\d{8}-\d{6}\.sql\.gz$/');
            $zip = $this->firstMatch($dir, '/^files-\d{8}-\d{6}\.zip$/');

            $date = is_file($manifest) ? $this->manifestDate($manifest) : date('Y-m-d H:i', filemtime($dir));
            $size = $this->dirSize($dir);
            $hash = $this->combinedHash($db, $zip);

            $list[] = [
                'dir'     => $dir,
                'date'    => $date,
                'db_path' => $db,
                'zip_path'=> $zip,
                'size'    => $size,
                'size_h'  => $this->humanBytes($size),
                'hash'    => $hash ?: '-',
            ];
        }

        // Orden por fecha desc
        usort($list, function($a, $b){
            return strcmp($b['date'], $a['date']);
        });

        return $list;
    }

    protected function firstMatch(string $dir, string $regex): ?string {
        $dh = @opendir($dir);
        if (!$dh) return null;
        $found = null;
        while (($f = readdir($dh)) !== false) {
            if (preg_match($regex, $f)) {
                $found = $dir . DIRECTORY_SEPARATOR . $f;
                break;
            }
        }
        closedir($dh);
        return $found;
    }

    protected function manifestDate(string $manifestPath): string {
        $json = @file_get_contents($manifestPath);
        if (!$json) return date('Y-m-d H:i', filemtime($manifestPath));
        $m = json_decode($json, true);
        if (!is_array($m)) return date('Y-m-d H:i', filemtime($manifestPath));
        // created_at en formato Ymd-His
        if (!empty($m['created_at']) && preg_match('/^(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $m['created_at'], $r)) {
            return sprintf('%s-%s-%s %s:%s:%s', $r[1], $r[2], $r[3], $r[4], $r[5], $r[6]);
        }
        return date('Y-m-d H:i', filemtime($manifestPath));
        }

    protected function dirSize(string $dir): int {
        $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) $size += $f->getSize(); }
        return $size;
    }

    protected function humanBytes(int $b): string {
        $units = ['B','KB','MB','GB','TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($units)-1) { $b /= 1024; $i++; }
        return sprintf('%.2f %s', $b, $units[$i]);
    }

    protected function combinedHash(?string $db, ?string $zip): ?string {
        $h = hash_init('sha256');
        $any = false;
        foreach ([$db, $zip] as $p) {
            if ($p && is_file($p)) {
                $any = true;
                $fp = fopen($p, 'rb');
                if ($fp) {
                    while (!feof($fp)) {
                        $buf = fread($fp, 1024 * 1024);
                        if ($buf !== false) hash_update($h, $buf);
                    }
                    fclose($fp);
                }
            }
        }
        return $any ? hash_final($h) : null;
    }

    // === Actions ===

    public function handleDownload() {
        if (!current_user_can(self::CAP)) wp_die('Permisos insuficientes');
        $path = isset($_GET['path']) ? wp_unslash($_GET['path']) : '';
        $type = sanitize_text_field($_GET['type'] ?? '');
        check_admin_referer('nexo_backup_download_' . $path);

        if (!$path || !is_file($path)) wp_die('Archivo no encontrado');

        $fname = basename($path);
        $mime  = ($type === 'db') ? 'application/gzip' : 'application/zip';

        if (ob_get_level()) ob_end_clean();
        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');

        $fp = fopen($path, 'rb');
        while (!feof($fp)) {
            echo fread($fp, 8192);
            flush();
        }
        fclose($fp);
        exit;
    }

    public function handleDelete() {
        if (!current_user_can(self::CAP)) wp_die('Permisos insuficientes');
        $dir = isset($_GET['dir']) ? wp_unslash($_GET['dir']) : '';
        check_admin_referer('nexo_backup_delete_' . $dir);
        if (!$dir || !is_dir($dir)) wp_die('Directorio no válido');

        $this->rrmdir($dir);
        wp_safe_redirect(admin_url('tools.php?page=' . self::SLUG));
        exit;
    }

    public function handleViewManifest() {
        if (!current_user_can(self::CAP)) wp_die('Permisos insuficientes');
        $dir = isset($_GET['dir']) ? wp_unslash($_GET['dir']) : '';
        check_admin_referer('nexo_backup_view_' . $dir);
        if (!$dir || !is_dir($dir)) wp_die('Directorio no válido');

        $manifest = $dir . DIRECTORY_SEPARATOR . 'manifest.json';
        if (!is_file($manifest)) wp_die('No hay manifest para esta copia.');

        $json = file_get_contents($manifest);
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo $json ?: '{}';
        exit;
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

new Copies();