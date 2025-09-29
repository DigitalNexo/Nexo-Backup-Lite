<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

/**
 * Clase que genera un volcado de la base de datos en formato .sql.gz
 * Usa `mysqldump` si está disponible; si no, hace un dump en PHP.
 */
class DatabaseDumper {

    /**
     * Genera un dump comprimido de la BD
     */
    public function dumpToGzip(string $destFile): void {
        global $wpdb;

        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;
        $name = DB_NAME;

        // === 1) Intentar con mysqldump ===
        $mysqldump = $this->findBinary('mysqldump');
        if ($mysqldump) {
            $cmd = sprintf(
                '%s --user=%s --password=%s --host=%s --default-character-set=utf8mb4 %s | gzip > %s',
                escapeshellcmd($mysqldump),
                escapeshellarg($user),
                escapeshellarg($pass),
                escapeshellarg($host),
                escapeshellarg($name),
                escapeshellarg($destFile)
            );
            $ret = null;
            @exec($cmd, $out, $ret);
            if ($ret === 0 && is_file($destFile)) {
                return; // éxito
            }
        }

        // === 2) Fallback en PHP ===
        $this->dumpWithPhp($destFile, $wpdb);
    }

    /**
     * Dump en PHP con compresión Gzip
     */
    protected function dumpWithPhp(string $destFile, \wpdb $wpdb): void {
        $fp = gzopen($destFile, 'w9');
        if (!$fp) throw new \RuntimeException("No se pudo abrir $destFile para escribir");

        $tables = $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $table) {
            // Estructura
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            if ($create && !empty($create[1])) {
                gzwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
                gzwrite($fp, $create[1] . ";\n\n");
            }

            // Datos
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
            if ($rows) {
                foreach ($rows as $row) {
                    $cols = array_map(function($v){
                        if (is_null($v)) return 'NULL';
                        return "'" . esc_sql($v) . "'";
                    }, array_values($row));
                    $vals = implode(',', $cols);
                    $sql  = "INSERT INTO `$table` VALUES ($vals);\n";
                    gzwrite($fp, $sql);
                }
            }
            gzwrite($fp, "\n\n");
        }

        gzclose($fp);
    }

    /**
     * Busca un binario en el PATH del sistema
     */
    protected function findBinary(string $bin): ?string {
        $path = null;
        @exec('which ' . escapeshellarg($bin), $out, $ret);
        if ($ret === 0 && !empty($out[0])) {
            $path = trim($out[0]);
        }
        return $path && is_executable($path) ? $path : null;
    }
}