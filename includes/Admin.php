<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        add_action('update_option_' . NEXO_BACKUP_LITE_OPTION, [$this, 'onSettingsUpdated'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    /**
     * Reprograma el cron cuando cambian los ajustes
     */
    public function onSettingsUpdated($old, $new, $option) {
        Scheduler::reschedule(is_array($new) ? $new : []);
    }

    /**
     * Añade el menú en Ajustes
     */
    public function menu() {
        add_options_page(
            'Nexo Backup Lite',
            'Nexo Backup Lite',
            'manage_options',
            'nexo-backup-lite',
            [$this, 'render']
        );
    }

    /**
     * Registra los campos de configuración
     */
    public function settings() {
        register_setting('nexo_backup_lite_group', NEXO_BACKUP_LITE_OPTION);

        add_settings_section(
            'nexo_backup_lite_section',
            'Ajustes',
            function () {
                echo '<p>Configura la ruta de destino (fuera del webroot), la retención y la planificación automática.</p>';
            },
            'nexo-backup-lite'
        );

        // Ruta de destino
        add_settings_field('dest_path', 'Ruta de destino', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['dest_path'] ?? '');
            echo '<input type="text" class="regular-text code" name="' . NEXO_BACKUP_LITE_OPTION . '[dest_path]" value="' . $v . '" placeholder="/home/USUARIO/domains/tu-dominio/backups_wp" />';
            echo '<p class="description">Debe existir y ser escribible por el usuario de PHP. Ej.: <code>/home/usuario/domains/midominio/backups_wp</code></p>';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Retención (días)
        add_settings_field('retain_days', 'Retención (días)', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = intval($opts['retain_days'] ?? 7);
            echo '<input type="number" min="1" name="' . NEXO_BACKUP_LITE_OPTION . '[retain_days]" value="' . $v . '" />';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Planificación: activar
        add_settings_field('schedule_enabled', 'Planificación automática', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $checked = !empty($opts['schedule_enabled']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_enabled]" value="1" ' . $checked . '> Activar</label>';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Planificación: frecuencia
        add_settings_field('schedule_frequency', 'Frecuencia', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = $opts['schedule_frequency'] ?? 'daily';
            $options = [
                'daily'        => 'Diaria',
                'every_2_days' => 'Cada 2 días',
                'weekly'       => 'Semanal',
                'monthly'      => 'Mensual',
            ];
            echo '<select name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_frequency]">';
            foreach ($options as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($v, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Planificación: hora
        add_settings_field('schedule_time', 'Hora (zona horaria del sitio)', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['schedule_time'] ?? '03:00');
            echo '<input type="time" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_time]" value="' . $v . '" step="60" />';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');
    }

    /**
     * Carga de assets en la página del plugin (CSS inline + JS AJAX)
     */
    public function assets($hook) {
        // Hook esperado: settings_page_nexo-backup-lite
        if ($hook !== 'settings_page_nexo-backup-lite') return;

        // CSS inline sencillo para la barra
        wp_register_style('nexo-backup-lite-inline', false);
        wp_enqueue_style('nexo-backup-lite-inline');
        $css = '
#nexo-progress{margin:12px 0;border:1px solid #ccd0d4;height:24px;border-radius:4px;overflow:hidden;background:#f6f7f7}
#nexo-progress-bar{height:100%;width:0;background:#2271b1;color:#fff;display:flex;align-items:center;justify-content:center;transition:width .2s}
#nexo-progress-text{font-size:12px;line-height:1}
#nexo-progress-wrap{margin-top:10px}
#nexo-progress-detail{margin-top:6px;color:#555}
        ';
        wp_add_inline_style('nexo-backup-lite-inline', $css);

        // JS admin para iniciar/cancelar y mostrar progreso
        wp_enqueue_script(
            'nexo-backup-lite-admin',
            plugins_url('../assets/admin.js', __FILE__),
            ['jquery'],
            NEXO_BACKUP_LITE_VER,
            true
        );
        wp_localize_script('nexo-backup-lite-admin', 'NexoBackupLite', [
            'nonce' => wp_create_nonce('nexo_backup_ajax'),
        ]);
    }

    /**
     * Renderiza la página de ajustes
     */
    public function render() {
        if (!current_user_can('manage_options')) {
            wp_die('Permisos insuficientes');
        }

        $msg = $_GET['nexo_msg'] ?? '';
        if ($msg) {
            $notices = [
                'dest_invalid'    => '<div class="notice notice-error"><p>La ruta de destino no es válida o no es escribible.</p></div>',
                'already_running' => '<div class="notice notice-warning"><p>Ya hay una copia en curso.</p></div>',
                'done'            => '<div class="notice notice-success"><p>Copia creada correctamente.</p></div>',
                'failed'          => '<div class="notice notice-error"><p>La copia ha fallado.</p></div>',
                'error'           => '<div class="notice notice-error"><p>Se produjo un error inesperado. Revisa los logs.</p></div>',
            ];
            echo $notices[$msg] ?? '';
        }

        echo '<div class="wrap"><h1>Nexo Backup Lite</h1>';

        // Próxima ejecución del cron (si existe)
        $nextHuman = Scheduler::nextRunHuman();
        if ($nextHuman) {
            echo '<div class="notice notice-info"><p>Próxima copia programada: <strong>' . esc_html($nextHuman) . '</strong></p></div>';
        }

        // Formulario de ajustes
        echo '<form method="post" action="options.php">';
        settings_fields('nexo_backup_lite_group');
        do_settings_sections('nexo-backup-lite');
        submit_button('Guardar ajustes');
        echo '</form>';

        // Botón manual clásico (síncrono) — opcional mantener
        echo '<hr/><h2>Crear copia ahora (modo clásico)</h2>';
        $url = admin_url('admin-post.php?action=nexo_backup_lite_run');
        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('nexo_backup_lite_now');
        submit_button('Crear copia ahora', 'secondary', 'submit', false);
        echo '</form>';

        // Bloque de backup en segundo plano (AJAX + progreso)
        echo '<hr/><h2>Backup en segundo plano</h2>';
        echo '<div id="nexo-progress"><div id="nexo-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span id="nexo-progress-text"></span></div></div>';
        echo '<div id="nexo-progress-wrap">
                <button id="nexo-start" class="button button-primary">Iniciar backup</button>
                <button id="nexo-cancel" class="button" style="margin-left:8px" disabled>Cancelar</button>
                <div id="nexo-progress-detail"></div>
              </div>';

        echo '<p class="description">Este modo no bloquea la interfaz. Puedes seguir usando el panel mientras se ejecuta la copia. Para máxima fiabilidad general, considera añadir un cron real del sistema que ejecute <code>wp cron event run --due-now</code>.</p>';

        echo '</div>';
    }
}

new Admin();