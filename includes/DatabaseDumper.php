<?php
namespace Nexo\Backup;

if (!defined('ABSPATH')) exit;

class DatabaseDumper {
    public function dumpToGzip(string $outGzPath): void {
        global $wpdb;
        $db = $wpdb->dbname;
        $host = DB_HOST;
        $user = DB_USER;
        $pass = DB_PASSWORD;

        // 1) Intentar mysqldump (más rápido/fiable)
        $mysqldump = $this->findBinary('mysqldump');
        if ($mysqldump) {
            $cmd = sprintf(
                '%s --single-transaction --quick --lock-tables=false -h %s -u %s %s %s 2>/dev/null | gzip > %s',
                escapeshellcmd($mysqldump),
                escapeshellarg($host),
                escapeshellarg($user),
                $pass !== '' ? '-p'.escapeshellarg($pass) : '',
                escapeshellarg($db),
                escapeshellarg($outGzPath)
            );
            $ret = null;
            @exec($cmd, $o, $ret);
            if ($ret === 0 && file_exists($outGzPath)) return;
        }

        // 2) Fallback PHP (más lento, pero sin binarios)
        $this->fallbackPhpDump($outGzPath);
    }

    protected function findBinary(string $bin): ?string {
        $paths = ['/usr/bin','/usr/local/bin','/bin','/opt/local/bin','/usr/sbin'];
        foreach ($paths as $p) {
            $full = $p . DIRECTORY_SEPARATOR . $bin;
            if (is_executable($full)) return $full;
        }
        return null;
    }

    protected function fallbackPhpDump(string $outGzPath): void {
        global $wpdb;

        $gz = gzopen($outGzPath, 'wb9');
        if (!$gz) throw new \RuntimeException('No se pudo crear el gzip de la BD.');

        gzwrite($gz, "-- Nexo Backup Lite SQL dump\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

        $tables = $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $table) {
            // CREATE
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
            gzwrite($gz, "\n-- Structure for table `$table`\n");
            gzwrite($gz, "DROP TABLE IF EXISTS `$table`;\n");
            gzwrite($gz, $create[1] . ";\n");

            // DATA
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            if ($count === 0) continue;

            gzwrite($gz, "\n-- Data for table `$table`\n");
            $offset = 0; $chunk = 1000;
            while ($offset < $count) {
                $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $offset, $chunk", ARRAY_A);
                if (!$rows) break;
                $vals = [];
                foreach ($rows as $r) {
                    $escaped = array_map(function($v) use ($wpdb) {
                        if ($v === null) return 'NULL';
                        return "'" . esc_sql(str_replace(["\n","\r"], ['\n','\r'], $v)) . "'";
                    }, array_values($r));
                    $vals[] = '(' . implode(',', $escaped) . ')';
                }
                $cols = array_map(fn($c)=>"`$c`", array_keys($rows[0]));
                $sql = "INSERT INTO `$table` (". implode(',', $cols).") VALUES\n".implode(",\n", $vals).";\n";
                gzwrite($gz, $sql);
                $offset += $chunk;
            }
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($gz);
    }
}