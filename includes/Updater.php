<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

/**
 * Updater de plugins desde GitHub Releases.
 *
 * Cómo usar:
 *   require_once NEXO_BACKUP_LITE_DIR . 'includes/Updater.php';
 *   add_action('init', function () {
 *       new \Nexo\Backup\Updater(__FILE__, 'TU_USUARIO_GITHUB', 'TU_REPO_GITHUB');
 *   });
 *
 * Requisitos:
 *  - El repositorio debe ser PÚBLICO.
 *  - Crear cada versión como "Release" en GitHub con un tag tipo v0.6.0 o 0.6.0.
 *  - El ZIP usado será el zipball_url del release.
 */
class Updater {
    protected string $pluginFile;   // ruta absoluta al archivo principal del plugin
    protected string $pluginSlug;   // ej: nexo-backup-lite/nexo-backup-lite.php
    protected string $slugDir;      // ej: nexo-backup-lite
    protected string $githubUser;
    protected string $githubRepo;

    public function __construct(string $pluginFile, string $githubUser, string $githubRepo) {
        $this->pluginFile = $pluginFile;
        $this->pluginSlug = plugin_basename($pluginFile);
        $this->slugDir    = dirname($this->pluginSlug);
        $this->githubUser = $githubUser;
        $this->githubRepo = $githubRepo;

        // Inyecta actualización disponible (si procede)
        add_filter('pre_set_site_transient_update_plugins', [$this, 'checkForUpdate']);

        // Ficha al pulsar "Ver detalles de la versión"
        add_filter('plugins_api', [$this, 'pluginsApi'], 10, 3);

        // Mensaje inline bajo el plugin (muestra comparación y link al release)
        add_action('in_plugin_update_message-' . $this->pluginSlug, [$this, 'inlineUpdateMessage'], 10, 2);
    }

    /**
     * Compara la versión instalada con la última release en GitHub.
     */
    public function checkForUpdate($transient) {
        if (empty($transient->checked)) return $transient;

        $currentVersion = $this->getCurrentVersion();
        if (!$currentVersion) return $transient;

        $release = $this->getLatestRelease();
        if (!$release || empty($release['tag_name'])) return $transient;

        $newVersion = $this->normalizeTag($release['tag_name']);

        if (!is_string($newVersion) || $newVersion === '' || version_compare($newVersion, $currentVersion, '<=')) {
            return $transient;
        }

        $package  = $release['zipball_url'] ?? '';
        $homepage = $release['html_url']    ?? '';

        $pluginData = (object)[
            'slug'        => $this->slugDir,
            'plugin'      => $this->pluginSlug,
            'new_version' => $newVersion,
            'url'         => $homepage,
            'package'     => $package,
        ];

        $transient->response[$this->pluginSlug] = $pluginData;
        return $transient;
    }

    /**
     * Proporciona los datos de la tarjeta "Ver detalles de la versión".
     */
    public function pluginsApi($res, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slugDir) {
            return $res;
        }

        $release = $this->getLatestRelease();
        if (!$release || empty($release['tag_name'])) return $res;

        $version   = $this->normalizeTag($release['tag_name']);
        $changelog = (string)($release['body'] ?? '');
        $homepage  = (string)($release['html_url'] ?? '');
        $download  = (string)($release['zipball_url'] ?? '');

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
     * Muestra un mensaje bajo el plugin cuando hay actualización disponible.
     */
    public function inlineUpdateMessage($pluginData, $response) {
        $installed = $this->getCurrentVersion();
        $release   = $this->getLatestRelease();
        if (!$release) return;

        $newVersion = isset($release['tag_name']) ? $this->normalizeTag($release['tag_name']) : null;
        $txt = 'Instalada: <code>' . esc_html($installed ?: '?') . '</code> · Última en GitHub: <code>' . esc_html($newVersion ?: '?') . '</code>';

        if (!empty($release['name'])) {
            $txt .= ' — <strong>' . esc_html($release['name']) . '</strong>';
        }
        if (!empty($release['published_at'])) {
            $date = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($release['published_at']));
            $txt .= ' (' . esc_html($date) . ')';
        }
        if (!empty($release['body'])) {
            $body = esc_html($this->truncate((string)$release['body'], 220));
            $txt .= ' — ' . $body;
        }
        if (!empty($release['html_url'])) {
            $url = esc_url($release['html_url']);
            $txt .= ' <a href="' . $url . '" target="_blank" rel="noopener noreferrer">Ver release</a>';
        }

        echo '<p style="margin:.5em 0 0;">' . $txt . '</p>';
    }

    /**
     * Obtiene la última release de GitHub con caché (2 horas) y logs de error.
     */
    protected function getLatestRelease(): ?array {
        $key = 'nexo_backup_latest_release_' . md5($this->githubUser . '/' . $this->githubRepo);
        $cached = get_site_transient($key);
        if (is_array($cached)) return $cached;

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            rawurlencode($this->githubUser),
            rawurlencode($this->githubRepo)
        );

        $res = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'User-Agent' => 'WordPress; ' . home_url('/'),
                'Accept'     => 'application/vnd.github+json',
            ],
        ]);

        if (is_wp_error($res)) {
            error_log('[Nexo Backup Lite] Updater: wp_remote_get error: ' . $res->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            error_log('[Nexo Backup Lite] Updater: HTTP ' . $code . ' al pedir ' . $url);
            return null;
        }

        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);
        if (!is_array($json)) {
            error_log('[Nexo Backup Lite] Updater: JSON inválido desde GitHub');
            return null;
        }

        // cache 2 horas
        set_site_transient($key, $json, 2 * HOUR_IN_SECONDS);
        return $json;
    }

    /**
     * Lee versión instalada (constante o cabecera del plugin).
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

    /**
     * Normaliza un tag de release a versión comparable por PHP.
     * Acepta "v0.6.0" o "0.6.0" y devuelve "0.6.0".
     */
    protected function normalizeTag(string $tag): string {
        $tag = trim($tag);
        if ($tag === '') return '';
        // quita prefijo v/V
        if ($tag[0] === 'v' || $tag[0] === 'V') {
            $tag = substr($tag, 1);
        }
        return $tag;
    }

    protected function truncate(string $txt, int $max): string {
        $txt = trim($txt);
        if (mb_strlen($txt) <= $max) return $txt;
        return mb_substr($txt, 0, $max - 1) . '…';
    }
}