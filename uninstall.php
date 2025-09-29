<?php
/**
 * Uninstall script for Nexo Backup Lite
 * Se ejecuta cuando el plugin se elimina desde el admin de WordPress.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Elimina las opciones de configuración
delete_option('nexo_backup_lite_settings');

// En caso de multisite, limpiar en cada blog
if (is_multisite()) {
    global $wpdb;
    $blog_ids = $wpdb->get_col("SELECT blog_id FROM {$wpdb->blogs}");
    foreach ($blog_ids as $blog_id) {
        switch_to_blog($blog_id);
        delete_option('nexo_backup_lite_settings');
        restore_current_blog();
    }
}