<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Admin {
    /** Capacidad mínima para ver el panel */
    public const CAP  = 'manage_options';
    /** Slug del menú padre */
    public const SLUG = 'nexo-backup-lite';

    public function __construct() {
        add_action('admin_menu',            [$this, 'registerMenu']);
        add_action('admin_init',            [$this, 'registerSettings']);
        add_action('update_option_' . NEXO_BACKUP_LITE_OPTION, [$this, 'onSettingsUpdated'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function onSettingsUpdated($old, $new) {
        Scheduler::reschedule(is_array($new) ? $new : []);
    }

    /**
     * Menú propio "Nexo Backup Lite" + submenú Ajustes.
     * El submenú "Copias" lo añade Copies.php para evitar duplicados.
     */
    public function registerMenu() {
        add_menu_page(
            __('Nexo Backup Lite', 'nexo-backup-lite'),
            __('Nexo Backup Lite', 'nexo-backup-lite'),
            self::CAP,
            self::SLUG,
            [$this, 'render'],
            'dashicons-database',
            65
        );

        // Submenú Ajustes (apunta a la misma vista render)
        add_submenu_page(
            self::SLUG,
            __('Ajustes', 'nexo-backup-lite'),
            __('Ajustes', 'nexo-backup-lite'),
            self::CAP,
            self::SLUG,
            [$this, 'render']
        );

        // Nota: NO añadimos aquí "Copias" para no duplicarlo.
        // Copies.php añade:
        // add_submenu_page( Admin::SLUG, 'Copias', 'Copias', CAP, Copies::SLUG, ... );
    }

    public function registerSettings() {
        register_setting('nexo_backup_lite_group', NEXO_BACKUP_LITE_OPTION);

        add_settings_section(
            'nexo_backup_lite_section',
            __('Ajustes', 'nexo-backup-lite'),
            function () {
                echo '<p>' . esc_html__('Configura la ruta de destino fuera del webroot, retención, planificación y el patrón de nombre.', 'nexo-backup-lite') . '</p>';
            },
            self::SLUG
        );

        // Ruta destino
        add_settings_field('dest_path', __('Ruta de destino', 'nexo-backup-lite'), function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['dest_path'] ?? '');
            echo '<input type="text" class="regular-text code" name="' . NEXO_BACKUP_LITE_OPTION . '[dest_path]" value="' . $v . '" placeholder="/home/USUARIO/domains/tu-dominio/backups_wp" />';
            echo '<p class="description">'.esc_html__('Debe existir y ser escribible (fuera del public_html).', 'nexo-backup-lite').'</p>';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Retención
        add_settings_field('retain_days', __('Retención (días)', 'nexo-backup-lite'), function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = intval($opts['retain_days'] ?? 7);
            echo '<input type="number" min="1" name="' . NEXO_BACKUP_LITE_OPTION . '[retain_days]" value="' . $v . '" />';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Patrón de nombres
        add_settings_field('name_pattern', __('Patrón de nombre', 'nexo-backup-lite'), function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $pattern = $opts['name_pattern'] ?? 'nexo-{YYYY}{MM}{DD}-{HH}{mm}{SS}';
            $v = esc_attr($pattern);
            $preview = esc_html(\Nexo\Backup\pattern_render($pattern));
            echo '<input type="text" class="regular-text code" name="' . NEXO_BACKUP_LITE_OPTION . '[name_pattern]" value="' . $v . '" />';
            echo '<p class="description">'
                . __('Variables: ', 'nexo-backup-lite')
                . '<code>{YYYY}</code> <code>{YY}</code> <code>{MM}</code> <code>{DD}</code> '
                . '<code>{HH}</code> <code>{mm}</code> <code>{SS}</code> <code>{site}</code> <code>{ver}</code> <code>{rand4}</code> <code>{rand6}</code>'
                . '<br/>' . sprintf(__('Vista previa: %s', 'nexo-backup-lite'), '<code id="nexo-pattern-preview">'.$preview.'</code>')
                . '</p>';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Planificación: activar
        add_settings_field('schedule_enabled', __('Planificación automática', 'nexo-backup-lite'), function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $checked = !empty($opts['schedule_enabled']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_enabled]" value="1" ' . $checked . '> ' . esc_html__('Activar', 'nexo-backup-lite') . '</label>';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Planificación: frecuencia
        add_settings_field('schedule_frequency', __('Frecuencia', 'nexo-backup-lite'), function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = $opts['schedule_frequency'] ?? 'daily';
            $options = [
                'daily'        => __('Diaria', 'nexo-backup-lite'),
                'every_2_days' => __('Cada 2 días', 'nexo-backup-lite'),
                'weekly'       => __('Semanal', 'nexo-backup-lite'),
                'monthly'      => __('Mensual', 'nexo-backup-lite'),
            ];
            echo '<select name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_frequency]">';
            foreach ($options as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($v, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        }, self::SLUG, 'nexo_backup_lite_section');

        // Planificación: hora
        add_settings_field('schedule_time', __('Hora (zona del sitio)', 'nexo-backup-lite'), function () {
            $opts = get_option(NEXO_BACKUP_LITE_OPTION, []);
            $v = esc_attr($opts['schedule_time'] ?? '03:00');
            echo '<input type="time" name="' . NEXO_BACKUP_LITE_OPTION . '[schedule_time]" value="' . $v . '" step="60" />';
        }, self::SLUG, 'nexo_backup_lite_section');
    }

    public function enqueueAssets($hook) {
        // Hooks válidos: toplevel_page_nexo-backup-lite
        if ($hook !== 'toplevel_page_' . self::SLUG && $hook !== self::SLUG . '_page_' . self::SLUG) return;

        // CSS mínimo inline
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

        // JS de control (usa ajax)
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
     * Pantalla principal: Ajustes + Acciones de backup
     */
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
        echo '<a class="nav-tab nav-tab-active" href="'.$baseUrl.'">'.esc_html__('Ajustes & Backup', 'nexo-backup-lite').'</a>';
        echo '<a class="nav-tab" href="'.admin_url('admin.php?page='.Copies::SLUG).'">'.esc_html__('Copias', 'nexo-backup-lite').'</a>';
        echo '</h2>';

        echo '<form method="post" action="options.php">';
        settings_fields('nexo_backup_lite_group');
        do_settings_sections(self::SLUG);
        submit_button(__('Guardar ajustes', 'nexo-backup-lite'));
        echo '</form>';

        // Botón manual clásico
        echo '<hr/><h2>'.esc_html__('Crear copia ahora (modo clásico)', 'nexo-backup-lite').'</h2>';
        $url = admin_url('admin-post.php?action=nexo_backup_lite_run');
        echo '<form method="post" action="' . esc_url($url) . '">';
        wp_nonce_field('nexo_backup_lite_now');
        submit_button(__('Crear copia ahora', 'nexo-backup-lite'), 'secondary', 'submit', false);
        echo '</form>';

        // Backup en segundo plano
        echo '<hr/><h2>'.esc_html__('Backup en segundo plano', 'nexo-backup-lite').'</h2>';
        echo '<div id="nexo-progress"><div id="nexo-progress-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><span id="nexo-progress-text"></span></div></div>';
        echo '<div id="nexo-progress-wrap">
                <button id="nexo-start" class="button button-primary">'.esc_html__('Iniciar backup', 'nexo-backup-lite').'</button>
                <button id="nexo-cancel" class="button" style="margin-left:8px" disabled>'.esc_html__('Cancelar', 'nexo-backup-lite').'</button>
                <div id="nexo-progress-detail"></div>
              </div>';

        echo '<p class="description">'.esc_html__('Puedes seguir usando el panel mientras se ejecuta la copia. Para máxima fiabilidad, añade un cron real que ejecute: wp cron event run --due-now', 'nexo-backup-lite').'</p>';

        echo '</div>';
    }
}

new Admin();