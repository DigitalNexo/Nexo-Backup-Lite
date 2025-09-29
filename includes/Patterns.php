<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

/**
 * Renderiza el patrón para nombres de carpeta/archivos de backup.
 * Tokens: {YYYY}{YY}{MM}{DD}{HH}{mm}{SS}{site}{ver}{rand4}{rand6}
 */
function pattern_render(string $pattern): string {
    $tz  = wp_timezone();
    $now = new \DateTime('now', $tz);

    $rep = [
        '{YYYY}' => $now->format('Y'),
        '{YY}'   => $now->format('y'),
        '{MM}'   => $now->format('m'),
        '{DD}'   => $now->format('d'),
        '{HH}'   => $now->format('H'),
        '{mm}'   => $now->format('i'),
        '{SS}'   => $now->format('s'),
        '{site}' => sanitize_title(get_bloginfo('name') ?: 'site'),
        '{ver}'  => defined('NEXO_BACKUP_LITE_VER') ? NEXO_BACKUP_LITE_VER : '0',
        '{rand4}'=> substr(wp_generate_password(4, false, false), 0, 4),
        '{rand6}'=> substr(wp_generate_password(6, false, false), 0, 6),
    ];

    $out = strtr($pattern, $rep);
    // normalizar a nombre de archivo/carpeta seguro
    $out = preg_replace('~[^A-Za-z0-9._-]+~', '-', $out);
    $out = trim($out, '-._');

    return $out ?: 'backup';
}