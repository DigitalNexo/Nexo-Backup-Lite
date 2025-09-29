<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'settings']);
        // Reprograma cron cuando se guardan ajustes
        add_action('update_option_' . NEXO_BACKUP_LITE_OPTION, [$this, 'onSettingsUpdated'], 10, 3);
    }

    /**
     * Reprograma el cron cuando cambian los ajustes
     */
    public function onSettingsUpdated($old, $new, $option) {
        Scheduler::reschedule(is_array($new) ? $new : []);
    }

    /**
     * Añade menú en Ajustes
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
            function() {
                echo '<p>Configura la ruta de destino (fuera del webroot) y la planificación automática.</p>';
            },
            'nexo-backup-lite'
        );

        // Ruta destino
        add_settings_field('dest_path', 'Ruta de destino', function() {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['dest_path'] ?? '');
            echo '<input type="text" class="regular-text code" name="' . NEXO_BACKUP_LITE_OPTION . '[dest_path]" value="' . $v . '" placeholder="/var/backups/wp/miweb" />';
            echo '<p class="description">Debe existir y ser escribible por el servidor web.</p>';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Retención
        add_settings_field('retain_days', 'Retención (días)', function() {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = intval($opts['retain_days'] ?? 7);
            echo '<input type="number" min="1" name="' . NEXO_BACKUP_LITE_OPTION . '[retain_days]" value="' . $v . '" />';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Planificación automática (checkbox)
        add_settings_field('schedule_enabled', 'Planificación automática', function() {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $checked = !empty($opts['schedule_enabled']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_enabled]" value="1" ' . $checked . '> Activar</label>';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');

        // Frecuencia
        add_settings_field('schedule_frequency', 'Frecuencia', function() {
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

        // Hora
        add_settings_field('schedule_time', 'Hora (zona horaria del sitio)', function() {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['schedule_time'] ?? '03:00');
            echo '<input type="time" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_time]" value="' . $v . '" step="60" />';
        }, 'nexo-backup-lite', 'nexo_backup_lite_section');
    }

    /**
     * Renderiza la página de ajustes en el admin
     */
    public function render() {
        $msg = $_GET['nexo_msg'] ?? '';
        if ($msg) {
            $notices = [
                'dest_invalid'   => '<div class="notice notice-error"><p>La ruta de destino no es válida o no es escribible.</p></div>',
                'already_running'=> '<div class="notice notice-warning"><p>Ya hay una copia en curso.</p></div>',
                'done'           => '<div class="notice notice-success"><p>Copia creada correctamente.</p></div>',
                'failed'         => '<div class="notice notice-error"><p>La copia ha fallado.</p></div>',
                'error'          => '<div class="notice notice-error"><p>Se produjo un error inesperado. Revisa los logs.</p></div>',
            ];
            echo $notices[$msg] ?? '';
        }

        echo '<div class="wrap"><h1>Nexo Backup Lite</h1>';

        // Mostrar próxima ejecución programada
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

        // Botón manual
        echo '<hr/><h2>Crear copia ahora</h2>';
        $url = admin_url('admin-post.php?action=nexo_backup_lite_run');
        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('nexo_backup_lite_now');
        submit_button('Crear copia ahora', 'primary', 'submit', false);
        echo '</form>';

        echo '<p class="description">Para máxima fiabilidad, añade un cron real en tu servidor que ejecute <code>wp cron event run --due-now</code>.</p>';

        echo '</div>';
    }
}

new Admin();