<?php
/**
 * Plugin Name: Nexo Backup Lite (Local Only)
 * Description: Copias de seguridad locales (fuera del webroot) con planificación (diaria, cada 2 días, semanal, mensual). Incluye botón “Crear copia ahora”.
 * Version: 0.2.0
 * Author: Nexo
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) exit;

define('NEXO_BACKUP_LITE_VER', '0.2.0');
define('NEXO_BACKUP_LITE_DIR', plugin_dir_path(__FILE__));
define('NEXO_BACKUP_LITE_URL', plugin_dir_url(__FILE__));
define('NEXO_BACKUP_LITE_OPTION', 'nexo_backup_lite_settings');

// Includes
require_once NEXO_BACKUP_LITE_DIR . 'includes/BackupManager.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/DatabaseDumper.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/Admin.php';
require_once NEXO_BACKUP_LITE_DIR . 'includes/Scheduler.php';

// Boot del scheduler (hook y cron_schedules)
add_action('plugins_loaded', function () {
    \Nexo\Backup\Scheduler::boot();
});

// Activación: crea opciones por defecto y programa si estaba habilitado
register_activation_hook(__FILE__, function () {
    $defaults = [
        'dest_path'          => '',
        'exclude_dirs'       => ['cache','backups','backup','w3tc','node_modules'],
        'exclude_patterns'   => ['*.log','*.tmp','*.DS_Store'],
        'retain_days'        => 7,
        // Planificación
        'schedule_enabled'   => 0,
        'schedule_frequency' => 'daily',     // daily | every_2_days | weekly | monthly
        'schedule_time'      => '03:00',     // HH:MM (zona horaria del sitio)
    ];

    // Crea si no existe; respeta valores existentes
    if (get_option(NEXO_BACKUP_LITE_OPTION) === false) {
        add_option(NEXO_BACKUP_LITE_OPTION, $defaults, '', false);
    }

    // Si ya viene habilitado (migración), reprograma
    $settings = get_option(NEXO_BACKUP_LITE_OPTION, $defaults);
    if (!empty($settings['schedule_enabled'])) {
        \Nexo\Backup\Scheduler::reschedule($settings);
    }
});

// Desactivación: desprograma cron (no borra opciones)
register_deactivation_hook(__FILE__, function () {
    \Nexo\Backup\Scheduler::unscheduleAll();
});

// Desinstalación: borra opciones
register_uninstall_hook(__FILE__, 'nexo_backup_lite_uninstall');
function nexo_backup_lite_uninstall() {
    delete_option(NEXO_BACKUP_LITE_OPTION);
}

// Acción del botón “Crear copia ahora”
add_action('admin_post_nexo_backup_lite_run', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Permisos insuficientes');
    }

    check_admin_referer('nexo_backup_lite_now');

    $settings = get_option(NEXO_BACKUP_LITE_OPTION, []);
    $destPath = rtrim($settings['dest_path'] ?? '', DIRECTORY_SEPARATOR);

    if (!$destPath || !is_dir($destPath) || !is_writable($destPath)) {
        wp_redirect(add_query_arg(['page' => 'nexo-backup-lite', 'nexo_msg' => 'dest_invalid'], admin_url('options-general.php')));
        exit;
    }

    // Lock simple para evitar concurrencia
    $lock_key = 'nexo_backup_lite_lock';
    if (get_transient($lock_key)) {
        wp_redirect(add_query_arg(['page' => 'nexo-backup-lite', 'nexo_msg' => 'already_running'], admin_url('options-general.php')));
        exit;
    }
    set_transient($lock_key, 1, 30 * MINUTE_IN_SECONDS);

    $msg = 'done';
    try {
        $bm = new \Nexo\Backup\BackupManager($settings);
        $ok = $bm->run($destPath);
        if (!$ok) {
            $msg = 'failed';
        }
    } catch (\Throwable $e) {
        error_log('[Nexo Backup Lite] Run error: ' . $e->getMessage());
        $msg = 'error';
    } finally {
        delete_transient($lock_key);
    }

    wp_redirect(add_query_arg(['page' => 'nexo-backup-lite', 'nexo_msg' => $msg], admin_url('options-general.php')));
    exit;
});