<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class Scheduler {
    const HOOK = 'nexo_backup_lite_cron';

    public static function boot(): void {
        // Acción que ejecuta la copia cuando “salta” el cron
        add_action(self::HOOK, [__CLASS__, 'run']);

        // Añadimos intervalos extra por si quieres usar wp_schedule_event por intervalo
        add_filter('cron_schedules', [__CLASS__, 'addIntervals']);
    }

    public static function addIntervals(array $schedules): array {
        $schedules['every_2_days'] = [
            'interval' => 2 * DAY_IN_SECONDS,
            'display'  => __('Cada 2 días', 'nexo-backup-lite'),
        ];
        // Nota: “mensual” lo calculamos manualmente para respetar misma hora/día, no por intervalo fijo.
        return $schedules;
    }

    /** Ejecuta la copia cuando la llama WP-Cron */
    public static function run(): void {
        $settings = get_option(NEXO_BACKUP_LITE_OPTION, []);

        // Lock: evita solapes
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

        // Reprograma la siguiente ejecución si la frecuencia es “mensual”
        $freq = $settings['schedule_frequency'] ?? 'daily';
        $enabled = !empty($settings['schedule_enabled']);
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
            // mensual: programamos la PRÓXIMA sola y, al terminar, nos volvemos a programar
            wp_schedule_single_event($ts, self::HOOK);
        } else {
            // diaria / cada 2 días / semanal: evento recurrente
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

    /** Calcula el próximo timestamp a la hora seleccionada, en la zona horaria de WP */
    public static function nextTimestamp(array $settings): ?int {
        $time  = $settings['schedule_time'] ?? '03:00'; // por defecto 03:00
        [$hh, $mm] = array_map('intval', explode(':', $time . ':00'));

        // Hora local de WP (no UTC)
        $tz   = wp_timezone();
        $now  = new \DateTime('now', $tz);
        $next = new \DateTime('now', $tz);
        $next->setTime($hh, $mm, 0);

        $freq = $settings['schedule_frequency'] ?? 'daily';

        if ($freq === 'weekly') {
            // Día de la semana configurable en futuro; por ahora “mañana si ya pasó la hora, o hoy si no, y luego cada 7 días”
            if ($next <= $now) $next->modify('+1 day');
            // Llevar a la próxima ocurrencia del mismo día de la semana actual + 7 múltiplos:
            while ($next->format('H:i') !== sprintf('%02d:%02d', $hh, $mm) || $next <= $now) {
                $next->modify('+1 day');
                if ($next->format('w') == $now->format('w')) break;
            }
            // Ajuste final: si aún no pasó, que sea hoy a la hora; si pasó, +7 días
            if ($next <= $now) $next->modify('+7 days');
        } elseif ($freq === 'every_2_days') {
            if ($next <= $now) $next->modify('+2 days'); // próxima “ventana” a 48h vista
            // si aún no ha pasado hoy a esa hora, respétalo:
            if ($now < (clone $next)->modify('-2 days')) $next = (clone $next)->modify('-2 days');
        } elseif ($freq === 'monthly') {
            // misma hora y día del mes que hoy (o el siguiente si ya pasó)
            if ($next <= $now) $next->modify('+1 month');
        } else { // daily
            if ($next <= $now) $next->modify('+1 day');
        }

        return $next->getTimestamp();
    }

    /** Devuelve próximo timestamp legible (para mostrar en ajustes) */
    public static function nextRunHuman(): ?string {
        $ts = wp_next_scheduled(self::HOOK);
        if (!$ts) return null;
        return wp_date('Y-m-d H:i', $ts);
    }
}