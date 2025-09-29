<?php
/**
 * Plugin Name: Nexo Backup Lite (Local Only)
 * Description: Copias locales fuera del webroot con planificación, ejecución en segundo plano con progreso, listado/gestión de copias y actualizaciones desde GitHub. Menú propio y patrón de nombres personalizable.
 * Version: 0.6.1
 * Author: Nexo
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('NEXO_BACKUP_LITE_VER', '0.6.1');
define('NEXO_BACKUP_LITE_DIR', plugin_dir_path(__FILE__));
define('NEXO_BACKUP_LITE_URL', plugin_dir_url(__FILE__));
define('NEXO_BACKUP_LITE_OPTION', 'nexo_backup_lite_settings');

// === Includes (todas las clases/funciones en sus propios namespaces) ===
require_once NEXO_BACKUP_LITE_DIR . 'includes/Patterns.php';       // <<— NUEVO
require_once NEXO_BACKUP_LITE_DIR . 'includes/BackupManager.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/DatabaseDumper.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/Admin.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/Scheduler.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/Copies.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/Updater.php';

// === Auto-update desde GitHub ===
// Sustituye 'TU_USUARIO_GITHUB' y 'TU_REPO_GITHUB' por lo real
add_action('init', function () {
    new \Nexo\Backup\Updater(__FILE__, 'DigitalNexo', 'Nexo-Backup-Lite');
});

// Boot del scheduler
add_action('plugins_loaded', function () {
    \Nexo\Backup\Scheduler::boot();
});

// === Activación ===
register_activation_hook(__FILE__, function () {
    $defaults = [
        'dest_path'          => '',
        'exclude_dirs'       => ['cache','backups','backup','w3tc','node_modules'],
        'exclude_patterns'   => ['*.log','*.tmp','*.DS_Store'],
        'retain_days'        => 7,
        'name_pattern'       => 'nexo-{YYYY}{MM}{DD}-{HH}{mm}{SS}', // patrón por defecto
        // Planificación
        'schedule_enabled'   => 0,
        'schedule_frequency' => 'daily',
        'schedule_time'      => '03:00',
    ];

    if (get_option(NEXO_BACKUP_LITE_OPTION) === false) {
        add_option(NEXO_BACKUP_LITE_OPTION, $defaults, '', false);
    }

    $settings = get_option(NEXO_BACKUP_LITE_OPTION, $defaults);
    if (!empty($settings['schedule_enabled'])) {
        \Nexo\Backup\Scheduler::reschedule($settings);
    }
});

// === Desactivación ===
register_deactivation_hook(__FILE__, function () {
    \Nexo\Backup\Scheduler::unscheduleAll();
});

// === Desinstalación ===
register_uninstall_hook(__FILE__, 'nexo_backup_lite_uninstall');
function nexo_backup_lite_uninstall() { delete_option(NEXO_BACKUP_LITE_OPTION); }

// ======================================================
//  BACKUP MANUAL (clásico, síncrono)
// ======================================================
add_action('admin_post_nexo_backup_lite_run', function () {
    if (!current_user_can('manage_options')) wp_die('Permisos insuficientes');
    check_admin_referer('nexo_backup_lite_now');

    $settings = get_option(NEXO_BACKUP_LITE_OPTION, []);
    $destPath = rtrim($settings['dest_path'] ?? '', DIRECTORY_SEPARATOR);

    if (!$destPath || !is_dir($destPath) || !is_writable($destPath)) {
        wp_redirect(add_query_arg(['page' => \Nexo\Backup\Admin::SLUG, 'nexo_msg' => 'dest_invalid'], admin_url('admin.php')));
        exit;
    }

    $lock_key = 'nexo_backup_lite_lock';
    if (get_transient($lock_key)) {
        wp_redirect(add_query_arg(['page' => \Nexo\Backup\Admin::SLUG, 'nexo_msg' => 'already_running'], admin_url('admin.php'))); exit;
    }
    set_transient($lock_key, 1, 30 * MINUTE_IN_SECONDS);

    $msg = 'done';
    try {
        $bm = new \Nexo\Backup\BackupManager($settings);
        $ok = $bm->run($destPath); // BackupManager usa el patrón internamente
        if (!$ok) $msg = 'failed';
    } catch (\Throwable $e) {
        error_log('[Nexo Backup Lite] Run error: ' . $e->getMessage());
        $msg = 'error';
    } finally {
        delete_transient($lock_key);
    }

    wp_redirect(add_query_arg(['page' => \Nexo\Backup\Admin::SLUG, 'nexo_msg' => $msg], admin_url('admin.php'))); exit;
});

// ======================================================
//  BACKUP EN SEGUNDO PLANO (AJAX + progreso)
// ======================================================
function nexo_backup_job_key($id){ return 'nexo_backup_job_' . sanitize_key($id); }
function nexo_backup_new_job_state($settings){
    return [
        'id'        => wp_generate_uuid4(),
        'status'    => 'running',
        'progress'  => 0,
        'message'   => 'Preparando…',
        'created'   => time(),
        'work_dir'  => null,
        'db_file'   => null,
        'zip_file'  => null,
        'total'     => 0,
        'index'     => 0,
        'files'     => [],
        'settings'  => $settings,
        'error'     => null,
        'base'      => null, // nombre base renderizado
    ];
}
function nexo_backup_get_job($id){ return get_transient(nexo_backup_job_key($id)) ?: null; }
function nexo_backup_put_job($state){ set_transient(nexo_backup_job_key($state['id']), $state, 2 * HOUR_IN_SECONDS); }

// === AJAX: iniciar backup ===
add_action('wp_ajax_nexo_backup_start', function(){
    check_ajax_referer('nexo_backup_ajax');
    if (!current_user_can('manage_options')) wp_send_json_error();

    $settings = get_option(NEXO_BACKUP_LITE_OPTION, []);
    $destPath = rtrim($settings['dest_path'] ?? '', DIRECTORY_SEPARATOR);
    if (!$destPath || !is_dir($destPath) || !is_writable($destPath)) {
        wp_send_json_error(['message'=>'Destino inválido']);
    }

    $st = nexo_backup_new_job_state($settings);

    // Render patrón de nombre base
    $base = \Nexo\Backup\pattern_render($settings['name_pattern'] ?? 'nexo-{YYYY}{MM}{DD}-{HH}{mm}{SS}');
    $st['base'] = $base;

    // Directorio de trabajo = carpeta con el base renderizado
    $workDir = trailingslashit($destPath) . $base;
    if (!@mkdir($workDir, 0755, true)) {
        wp_send_json_error(['message'=>'No se pudo crear directorio de trabajo']);
    }
    $st['work_dir'] = $workDir;

    // 1) Dump DB
    try{
        $st['message']  = 'Volcando base de datos…';
        $st['progress'] = 15;
        nexo_dump_db_to_gzip($workDir, $st, $base);
    } catch (\Throwable $e){
        $st['status'] = 'error'; $st['error'] = $e->getMessage();
        nexo_backup_put_job($st);
        wp_send_json_success($st);
    }

    // 2) Listado de archivos
    $list = nexo_list_site_files($settings, $st);
    $st['files'] = $list;
    $st['total'] = count($list);

    // 3) Crear ZIP vacío y VALIDAR
    $zipPath = $workDir . DIRECTORY_SEPARATOR . "files-$base.zip";
    $zip = new ZipArchive();
    $openRes = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($openRes !== true){
        $st['status']='error'; $st['error']='No se pudo crear el ZIP (código ' . (int)$openRes . ')';
        nexo_backup_put_job($st);
        wp_send_json_success($st);
    }
    $zip->addFromString('.__nexo_init', 'init');
    $zip->close();

    if (!is_file($zipPath) || filesize($zipPath) <= 0) {
        $st['status']='error'; $st['error']='ZIP no se creó correctamente';
        nexo_backup_put_job($st);
        wp_send_json_success($st);
    }
    $st['zip_file'] = $zipPath;

    $st['message']  = 'Listo para empaquetar archivos…';
    $st['progress'] = 20;
    nexo_backup_put_job($st);

    wp_send_json_success(['job_id'=>$st['id'], 'status'=>$st['status'], 'progress'=>$st['progress'], 'message'=>$st['message']]);
});

// === AJAX: estado ===
add_action('wp_ajax_nexo_backup_status', function(){
    check_ajax_referer('nexo_backup_ajax');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $id = sanitize_text_field($_POST['job_id'] ?? '');
    $st = nexo_backup_get_job($id);
    if (!$st) wp_send_json_error();
    wp_send_json_success($st);
});

// === AJAX: cancelar ===
add_action('wp_ajax_nexo_backup_cancel', function(){
    check_ajax_referer('nexo_backup_ajax');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $id = sanitize_text_field($_POST['job_id'] ?? '');
    $st = nexo_backup_get_job($id);
    if (!$st) wp_send_json_error();
    $st['status']  = 'canceled';
    $st['message'] = 'Cancelado por el usuario.';
    nexo_backup_put_job($st);
    wp_send_json_success($st);
});

// === AJAX: procesar lote ===
add_action('wp_ajax_nexo_backup_tick', function(){
    check_ajax_referer('nexo_backup_ajax');
    if (!current_user_can('manage_options')) wp_send_json_error();

    $id = sanitize_text_field($_POST['job_id'] ?? '');
    $st = nexo_backup_get_job($id);
    if (!$st || $st['status']!=='running') wp_send_json_error();

    try{
        $batchSize = 300;
        $processed = 0;

        // Abrir (o crear) el ZIP de forma robusta entre peticiones
        $zipPath = $st['zip_file'] ?? '';
        if (!$zipPath) throw new RuntimeException('Ruta ZIP vacía.');
        if (!is_file($zipPath)) {
            $d = dirname($zipPath);
            if (!is_dir($d) && !@mkdir($d, 0755, true)) {
                throw new RuntimeException('No se pudo recrear el directorio.');
            }
        }

        $zip = new ZipArchive();
        $openRes = $zip->open($zipPath, ZipArchive::CREATE);
        if ($openRes !== true) throw new RuntimeException('No se pudo abrir/crear ZIP (código '.(int)$openRes.')');

        while($processed < $batchSize && $st['index'] < $st['total']){
            $rel = $st['files'][$st['index']];
            $path = ABSPATH . $rel;
            if (is_readable($path) && is_file($path)){
                $zip->addFile($path, $rel);
            }
            $st['index']++; $processed++;
        }
        $zip->close();

        if ($st['total'] > 0){
            $filesPct = ($st['index'] / $st['total']) * 80; // 20–100%
            $st['progress'] = max(20, min(100, 20 + $filesPct));
            $st['message']  = 'Empaquetando archivos… (' . $st['index'] . ' / ' . $st['total'] . ')';
        }

        if ($st['index'] >= $st['total']){
            nexo_finalize_backup($st);
            $st['status']   = 'done';
            $st['progress'] = 100;
            $st['message']  = 'Copia completada.';
        }

        nexo_backup_put_job($st);
        wp_send_json_success($st);

    } catch (\Throwable $e){
        $st['status']  = 'error';
        $st['message'] = 'Error: ' . $e->getMessage();
        $st['error']   = $e->getMessage();
        nexo_backup_put_job($st);
        wp_send_json_success($st);
    }
});

// ======================================================
//  HELPERS
// ======================================================
function nexo_dump_db_to_gzip(string $workDir, array &$st, ?string $base = null): void {
    $dumper = new \Nexo\Backup\DatabaseDumper();
    $base   = $base ?: 'backup';
    $dbFile = $workDir . DIRECTORY_SEPARATOR . "db-$base.sql.gz";
    $dumper->dumpToGzip($dbFile);
    $st['db_file'] = $dbFile;
}

function nexo_list_site_files(array $settings, array $st): array {
    $exDirs     = array_map('strtolower', $settings['exclude_dirs'] ?? []);
    $exPatterns = $settings['exclude_patterns'] ?? [];

    $list = [];
    $root = ABSPATH;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file){
        if (!$file->isFile()) continue;
        $path = $file->getPathname();
        $rel  = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);

        $parts = explode(DIRECTORY_SEPARATOR, strtolower($rel));
        if (!empty($parts) && in_array($parts[0], $exDirs, true)) continue;

        foreach ($exPatterns as $pat){
            if (fnmatch($pat, basename($rel))) continue 2;
        }

        $dest = $settings['dest_path'] ?? '';
        if ($dest && str_starts_with(realpath($path) ?: '', realpath($dest) ?: '')) continue;

        $list[] = $rel;
    }
    return $list;
}

function nexo_finalize_backup(array $st): void {
    $manifest = [
        'version'     => NEXO_BACKUP_LITE_VER,
        'created_at'  => wp_date('Ymd-His'),
        'site_url'    => site_url(),
        'wp_version'  => get_bloginfo('version'),
        'db'          => basename($st['db_file']),
        'files'       => basename($st['zip_file']),
        'retain_days' => intval($st['settings']['retain_days'] ?? 7),
        'base_name'   => $st['base'] ?? '',
        'pattern'     => $st['settings']['name_pattern'] ?? '',
    ];
    file_put_contents(trailingslashit($st['work_dir']).'manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT));

    // Retención
    $base = dirname($st['work_dir']);
    $retainDays = $manifest['retain_days'];
    if ($retainDays > 0){
        $cut = time() - ($retainDays * DAY_IN_SECONDS);
        foreach (glob($base . DIRECTORY_SEPARATOR . '*') as $dir){
            if (is_dir($dir) && filemtime($dir) < $cut){
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($it as $f){ $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
                @rmdir($dir);
            }
        }
    }
}