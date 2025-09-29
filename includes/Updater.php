<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

/**
 * Updater de plugins desde GitHub Releases.
 *
 * Requisitos:
 *  - Publicar cada versión como "Release" en GitHub con un tag estilo v0.5.0
 *  - El ZIP que ofrece GitHub (zipball_url) es válido para actualización.
 *
 * Integración en el plugin principal (ejemplo):
 *   require_once NEXO_BACKUP_LITE_DIR . 'includes/Updater.php';
 *   add_action('init', function () {
 *       new \Nexo\Backup\Updater(__FILE__, 'TU_USUARIO_GITHUB', 'TU_REPO_GITHUB');
 *   });
 */
class Updater {
    protected string $pluginFile;   // Ruta absoluta al archivo principal del plugin
    protected string $pluginSlug;   // nexo-backup-lite/nexo-backup-lite.php
    protected string $slugDir;      // nexo-backup-lite
    protected string $githubUser;
    protected string $githubRepo;

    public function __construct(string $pluginFile, string $githubUser, string $githubRepo) {
        $this->pluginFile = $pluginFile;
        $this->pluginSlug = plugin_basename($pluginFile);
        $this->slugDir    = dirname($this->pluginSlug);
        $this->githubUser = $githubUser;
        $this->githubRepo = $githubRepo;

        // Hook para inyectar actualización disponible
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);

        // Ficha del plugin al pulsar "Ver detalles de la versión"
        add_filter('plugins_api', [$this, 'pluginsApi'], 10, 3);

        // Mensaje bajo el plugin en la lista (opcional)
        add_action('in_plugin_update_message-' . $this->pluginSlug, [$this, 'inlineUpdateMessage'], 10, 2);
    }

    /**
     * Comprueba si hay una versión nueva en GitHub y se la comunica a WP.
     */
    public function checkForUpdate($transient) {
        if (empty($transient->checked)) return $transient;

        $currentVersion = $this->getCurrentVersion(); // lee constante o cabecera
        if (!$currentVersion) return $transient;

        $release = $this->getLatestRelease();
        if (!$release || empty($release['tag_name'])) return $transient;

        $newVersion = ltrim((string)$release['tag_name'], 'v');
        if (version_compare($newVersion, $currentVersion, '<=')) return $transient;

        $package = $release['zipball_url'] ?? '';
        $homepage = $release['html_url'] ?? '';

        $pluginData = (object)[
            'slug'        => $this->slugDir,
            'plugin'      => $this->pluginSlug,
            'new_version' => $newVersion,
            'url'         => $homepage,
            'package'     => $package,
            // 'tested'    => '6.6', // opcional
            // 'requires'  => '6.0',
        ];

        $transient->response[$this->pluginSlug] = $pluginData;
        return $transient;
    }

    /**
     * Proporciona la "ficha" del plugin al pulsar "Ver detalles de la versión".
     */
    public function pluginsApi($res, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slugDir) {
            return $res;
        }

        $release = $this->getLatestRelease();
        if (!$release || empty($release['tag_name'])) return $res;

        $version   = ltrim((string)$release['tag_name'], 'v');
        $changelog = (string)($release['body'] ?? '');
        $homepage  = (string)($release['html_url'] ?? '');
        $download  = (string)($release['zipball_url'] ?? '');

        // Estructura esperada por WP
        $info = (object)[
            'name'          => $this->getPluginName(),
            'slug'          => $this->slugDir,
            'version'       => $version,
            'author'        => '<a href="https://github.com/' . esc_attr($this->githubUser) . '">' . esc_html($this->githubUser) . '</a>',
            'homepage'      => $homepage,
            'download_link' => $download,
            'sections'      => [
                'description' => $this->getPluginDescription() ?: 'Plugin de copias de seguridad locales.',
                'changelog'   => nl2br(esc_html($changelog)),
            ],
        ];
        return $info;
    }

    /**
     * Muestra un mensaje inline bajo el plugin cuando hay actualización.
     */
    public function inlineUpdateMessage($pluginData, $response) {
        $release = $this->getLatestRelease();
        if (!$release) return;
        $txt = '';
        if (!empty($release['name'])) {
            $txt .= '<strong>' . esc_html($release['name']) . '</strong> ';
        }
        if (!empty($release['published_at'])) {
            $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($release['published_at']));
            $txt .= '(' . esc_html($date) . ')';
        }
        if (!empty($release['body'])) {
            $body = esc_html($this->truncate($release['body'], 220));
            $txt .= ' — ' . $body;
        }
        if (!empty($release['html_url'])) {
            $url = esc_url($release['html_url']);
            $txt .= ' <a href="' . $url . '" target="_blank" rel="noopener noreferrer">Ver release</a>';
        }

        if ($txt) {
            echo '<p style="margin:.5em 0 0;">' . $txt . '</p>';
        }
    }

    /**
     * Obtiene la última release de GitHub con caché (6h).
     */
    protected function getLatestRelease(): ?array {
        $key = 'nexo_backup_latest_release_' . md5($this->githubUser . '/' . $this->githubRepo);
        $cached = get_site_transient($key);
        if (is_array($cached)) return $cached;

        $url = sprintf('https://api.github.com/repos/%s/%s/releases/latest', rawurlencode($this->githubUser), rawurlencode($this->githubRepo));
        $res = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'WordPress; ' . home_url('/'),
                'Accept'     => 'application/vnd.github+json',
            ],
        ]);

        if (is_wp_error($res)) return null;

        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return null;

        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (!is_array($json)) return null;

        set_site_transient($key, $json, 6 * HOUR_IN_SECONDS);
        return $json;
    }

    /**
     * Intenta obtener la versión actual del plugin.
     * Prioriza la constante NEXO_BACKUP_LITE_VER; si no, lee cabecera del plugin.
     */
    protected function getCurrentVersion(): ?string {
        if (defined('NEXO_BACKUP_LITE_VER')) {
            return (string) NEXO_BACKUP_LITE_VER;
        }
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->pluginFile, false, false);
        $ver  = $data['Version'] ?? '';
        return $ver ?: null;
    }

    protected function getPluginName(): string {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->pluginFile, false, false);
        return $data['Name'] ?? 'Nexo Backup Lite';
    }

    protected function getPluginDescription(): string {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data($this->pluginFile, false, false);
        return $data['Description'] ?? '';
    }

    protected function truncate(string $txt, int $max): string {
        $txt = trim($txt);
        if (mb_strlen($txt) <= $max) return $txt;
        return mb_substr($txt, 0, $max - 1) . '…';
    }
}