<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Admin {
    const CAP  = 'manage_options';
    const SLUG = 'nexo-backup-lite'; // menú padre

    public function __construct() {
        add_action('admin_menu',        [$this, 'menu']);
        add_action('admin_init',        [$this, 'settings']);
        add_action('update_option_' . NEXO_BACKUP_LITE_OPTION, [$this, 'onSettingsUpdated'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function onSettingsUpdated($old, $new, $option) {
        Scheduler::reschedule(is_array($new) ? $new : []);
    }

    /**
     * Menú propio "Nexo Backup Lite" con subpáginas
     */
    public function menu() {
        add_menu_page(
            'Nexo Backup Lite',
            'Nexo Backup Lite',
            self::CAP,
            self::SLUG,
            [$this, 'render'],
            'dashicons-database', // icono WP
            65 // posición entre Ajustes y Herramientas
        );

        // Submenú Ajustes (apunta a la misma pantalla render)
        add_submenu_page(
            self::SLUG,
            'Ajustes',
            'Ajustes',
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );

        // Submenú Copias (lo crea Copies.php, pero por si carga después)
        if (!has_action('admin_menu', [Copies::class, 'menu'])) {
            add_submenu_page(
                self::SLUG,
                'Copias',
                'Copias',
                self::CAP,
                Copies::SLUG,
                function () { echo '<div class="wrap"><h1>Copias</h1><p>Cargando…</p></div>'; }
            );
        }
    }

    public function settings() {
        register_setting('nexo_backup_lite_group', NEXO_BACKUP_LITE_OPTION);

        add_settings_section(
            'nexo_backup_lite_section',
            'Ajustes',
            function () {
                echo '<p>Configura la ruta de destino (fuera del webroot), la retención, la planificación automática y el patrón de nombres.</p>';
            },
            self::SLUG
        );

        // Ruta destino
        add_settings_field('dest_path', 'Ruta de destino', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['dest_path'] ?? '');
            echo '<input type="text" class="regular-text code" name="' . NEXO_BACKUP_LITE_OPTION . '[dest_path]" value="' . $v . '" placeholder="/home/USUARIO/domains/tu-dominio/backups_wp" />';
            echo '<p class="description">Debe existir y ser escribible. Ej.: <code>/home/usuario/domains/midominio/backups_wp</code></p>';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Retención
        add_settings_field('retain_days', 'Retención (días)', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = intval($opts['retain_days'] ?? 7);
            echo '<input type="number" min="1" name="' . NEXO_BACKUP_LITE_OPTION . '[retain_days]" value="' . $v . '" />';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Patrón de nombres
        add_settings_field('name_pattern', 'Patrón de nombre', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['name_pattern'] ?? 'nexo-{YYYY}{MM}{DD}-{HH}{mm}{SS}');
            $preview = esc_html(\Nexo\Backup\pattern_render($v));
            echo '<input type="text" class="regular-text code" name="' . NEXO_BACKUP_LITE_OPTION . '[name_pattern]" value="' . $v . '" />';
            echo '<p class="description">Define el nombre base para carpeta y archivos.<br/>Variables disponibles:
            <code>{YYYY}</code>, <code>{YY}</code>, <code>{MM}</code>, <code>{DD}</code>, <code>{HH}</code>, <code>{mm}</code>, <code>{SS}</code>, <code>{site}</code>, <code>{ver}</code>, <code>{rand4}</code>, <code>{rand6}</code>.<br/>
            Ejemplo: <code>backup-{YY}{MM}{DD}-{HH}{mm}-{site}</code> → Vista previa: <code id="nexo-pattern-preview">'.$preview.'</code></p>';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Planificación: activar
        add_settings_field('schedule_enabled', 'Planificación automática', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $checked = !empty($opts['schedule_enabled']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_enabled]" value="1" ' . $checked . '> Activar</label>';
        }, self::SLUG, 'nexo_backup_lite_section');

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
        }, self::SLUG, 'nexo_backup_lite_section');

        // Planificación: hora
        add_settings_field('schedule_time', 'Hora (zona horaria del sitio)', function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['schedule_time'] ?? '03:00');
            echo '<input type="time" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_time]" value="' . $v . '" step="60" />';
        }, self::SLUG, 'nexo_backup_lite_section');
    }

    public function assets($hook) {
        // Nuestra página padre y subpágina comparten slug
        if ($hook !== 'toplevel_page_' . self::SLUG && $hook !== self::SLUG . '_page_' . self::SLUG) return;

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

    public function render() {
        if (!current_user_can(self::CAP)) wp_die('Permisos insuficientes');

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

        $nextHuman = Scheduler::nextRunHuman();
        if ($nextHuman) {
            echo '<div class="notice notice-info"><p>Próxima copia programada: <strong>' . esc_html($nextHuman) . '</strong></p></div>';
        }

        echo '<h2 class="nav-tab-wrapper">';
        $baseUrl = admin_url('admin.php?page=' . self::SLUG);
        echo '<a class="nav-tab nav-tab-active" href="'.$baseUrl.'">Ajustes & Backup</a>';
        echo '<a class="nav-tab" href="'.admin_url('admin.php?page='.Copies::SLUG).'">Copias</a>';
        echo '</h2>';

        echo '<form method="post" action="options.php">';
        settings_fields('nexo_backup_lite_group');
        do_settings_sections(self::SLUG);
        submit_button('Guardar ajustes');
        echo '</form>';

        // Botón manual clásico
        echo '<hr/><h2>Crear copia ahora (modo clásico)</h2>';
        $url = admin_url('admin-post.php?action=nexo_backup_lite_run');
        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('nexo_backup_lite_now');
        submit_button('Crear copia ahora', 'secondary', 'submit', false);
        echo '</form>';

        // Backup en segundo plano
        echo '<hr/><h2>Backup en segundo plano</h2>';
        echo '<div id="nexo-progress"><div id="nexo-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span id="nexo-progress-text"></span></div></div>';
        echo '<div id="nexo-progress-wrap">
                <button id="nexo-start" class="button button-primary">Iniciar backup</button>
                <button id="nexo-cancel" class="button" style="margin-left:8px" disabled>Cancelar</button>
                <div id="nexo-progress-detail"></div>
              </div>';

        echo '<p class="description">Puedes seguir usando el panel mientras se ejecuta la copia. Para máxima fiabilidad, añade un cron real que ejecute <code>wp cron event run --due-now</code>.</p>';

        echo '</div>';
    }
}

new Admin();