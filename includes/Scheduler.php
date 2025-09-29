<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Scheduler {
    const HOOK = 'nexo_backup_lite_cron';

    public static function boot(): void {
        add_action(self::HOOK, [__CLASS__, 'run']);
        add_filter('cron_schedules', [__CLASS__, 'addIntervals']);
    }

    public static function addIntervals(array $schedules): array {
        $schedules['every_2_days'] = [
            'interval' => 2 * DAY_IN_SECONDS,
            'display'  => __('Cada 2 días', 'nexo-backup-lite'),
        ];
        return $schedules; // weekly y daily ya vienen en WP
    }

    /** Ejecuta la copia cuando “salta” el cron */
    public static function run(): void {
        $settings = get_option(NEXO_BACKUP_LITE_OPTION, []);

        // Evitar solapes
        $lock_key = 'nexo_backup_lite_lock';
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 30 * MINUTE_IN_SECONDS);

        try {
            $destPath = rtrim($settings['dest_path'] ?? '', DIRECTORY_SEPARATOR);
            if (!$destPath || !is_dir($destPath) || !is_writable($destPath)) {
                error_log('[Nexo Backup Lite] Cron: destino inválido');
                return;
            }
            $bm = new \Nexo\Backup\BackupManager($settings);
            $bm->run($destPath);
        } catch (\Throwable $e) {
            error_log('[Nexo Backup Lite] Cron error: '.$e->getMessage());
        } finally {
            delete_transient($lock_key);
        }

        // Si es mensual, reprogramamos una sola vez para el próximo mes
        $enabled = !empty($settings['schedule_enabled']);
        $freq = $settings['schedule_frequency'] ?? 'daily';
        if ($enabled && $freq === 'monthly') {
            self::unscheduleAll();
            $ts = self::nextTimestamp($settings);
            if ($ts) wp_schedule_single_event($ts, self::HOOK);
        }
    }

    /** (Re)programa según ajustes */
    public static function reschedule(array $settings): void {
        self::unscheduleAll();
        if (empty($settings['schedule_enabled'])) return;

        $freq = $settings['schedule_frequency'] ?? 'daily';
        $ts   = self::nextTimestamp($settings);
        if (!$ts) return;

        if ($freq === 'monthly') {
            wp_schedule_single_event($ts, self::HOOK);
        } else {
            $recurrence = match ($freq) {
                'every_2_days' => 'every_2_days',
                'weekly'       => 'weekly',
                default        => 'daily',
            };
            wp_schedule_event($ts, $recurrence, self::HOOK);
        }
    }

    /** Desprograma todas las ocurrencias de nuestro hook */
    public static function unscheduleAll(): void {
        $timestamp = wp_next_scheduled(self::HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
        }
    }

    /** Calcula el próximo timestamp respetando la TZ de WP */
    public static function nextTimestamp(array $settings): ?int {
        $time  = $settings['schedule_time'] ?? '03:00';
        [$hh, $mm] = array_map('intval', explode(':', $time . ':00'));

        $tz   = wp_timezone();
        $now  = new \DateTime('now', $tz);
        $next = new \DateTime('now', $tz);
        $next->setTime($hh, $mm, 0);

        $freq = $settings['schedule_frequency'] ?? 'daily';

        switch ($freq) {
            case 'weekly':
                // Próximo día de la semana igual al de hoy, pero a la hora indicada
                if ($next <= $now) $next->modify('+7 days');
                break;
            case 'every_2_days':
                if ($next <= $now) $next->modify('+2 days');
                break;
            case 'monthly':
                if ($next <= $now) $next->modify('+1 month');
                break;
            default: // daily
                if ($next <= $now) $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    /** Próxima ejecución legible para mostrar en ajustes */
    public static function nextRunHuman(): ?string {
        $ts = wp_next_scheduled(self::HOOK);
        if (!$ts) return null;
        return wp_date('Y-m-d H:i', $ts);
    }
}