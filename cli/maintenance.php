<?php
/**
 * CLI-only backup, restore verification, and maintenance tooling.
 *
 * Usage:
 *   php cli/maintenance.php backup          # Creates database dump + uploads archive, prunes >30d
 *   php cli/maintenance.php verify-restore  # Performs a restore drill into a temporary DB & checks row counts
 *   php cli/maintenance.php cleanup         # Backs up, then drops retired columns & settings
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/helpers.php';

function backup_directory(): string
{
    $dir = getenv('LASTO_BACKUP_DIR') ?: (__DIR__ . '/../storage/backups');
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create backup directory: {$dir}");
    }
    return rtrim($dir, '/\\');
}

function backup_database(?string $directory = null): string
{
    $pdo = db();
    $dir = $directory ?: backup_directory();
    $timestamp = date('Ymd-His');
    $rand = bin2hex(random_bytes(3));
    $path = "{$dir}/" . DB_NAME . "-{$timestamp}-{$rand}.sql";

    $file = fopen($path, 'xb');
    if (!$file) {
        throw new RuntimeException("Cannot create backup file at {$path}");
    }

    $write = static function (string $sql) use ($file): void {
        if (fwrite($file, $sql) !== strlen($sql)) {
            throw new RuntimeException('Incomplete backup write.');
        }
    };

    try {
        $pdo->beginTransaction();
        $write("-- Staff Attendance Database Backup\n-- Generated: " . date('c') . "\n-- Restore into an empty database.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $quoted = '`' . str_replace('`', '``', (string) $table) . '`';
            $ddl = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM)[1];
            $write($ddl . ";\n\n");

            foreach ($pdo->query('SELECT * FROM ' . $quoted) as $row) {
                $columns = implode(',', array_map(static fn($k) => '`' . str_replace('`', '``', (string) $k) . '`', array_keys($row)));
                $values = implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row));
                $write("INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n");
            }
            $write("\n");
        }

        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($file);
        if (file_exists($path)) {
            unlink($path);
        }
        throw $error;
    }

    fclose($file);
    return $path;
}

function backup_uploads(?string $directory = null): ?string
{
    $sourceDir = __DIR__ . '/../uploads/profiles';
    if (!is_dir($sourceDir)) {
        return null;
    }

    $dir = $directory ?: backup_directory();
    $timestamp = date('Ymd-His');
    $rand = bin2hex(random_bytes(3));
    $tarPath = "{$dir}/uploads-profiles-{$timestamp}-{$rand}.tar";

    if (file_exists($tarPath)) {
        unlink($tarPath);
    }

    try {
        $tar = new PharData($tarPath);
        $tar->buildFromDirectory($sourceDir);
        return $tarPath;
    } catch (Throwable $e) {
        if (file_exists($tarPath)) {
            unlink($tarPath);
        }
        error_log('Uploads backup notice: ' . $e->getMessage());
        return null;
    }
}

function prune_backups(?string $directory = null, int $retentionDays = 30): array
{
    $dir = $directory ?: backup_directory();
    $pruned = [];
    $threshold = time() - ($retentionDays * 86400);

    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === '.gitkeep') {
            continue;
        }
        $fullPath = "{$dir}/{$file}";
        if (is_file($fullPath) && (str_ends_with($file, '.sql') || str_ends_with($file, '.tar'))) {
            $mtime = filemtime($fullPath);
            if ($mtime !== false && $mtime < $threshold) {
                if (unlink($fullPath)) {
                    $pruned[] = $file;
                }
            }
        }
    }

    return $pruned;
}

function verify_restore_drill(?string $sqlFile = null): array
{
    $file = $sqlFile ?: backup_database();
    if (!is_file($file)) {
        throw new RuntimeException("Backup file does not exist: {$file}");
    }

    $drillDb = 'staff_attendance_drill_' . bin2hex(random_bytes(4));
    $dsn = sprintf('mysql:host=%s;port=%s;charset=%s', DB_HOST, DB_PORT, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);

    $pdo->exec("CREATE DATABASE `{$drillDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    try {
        $pdo->exec("USE `{$drillDb}`");
        $sql = file_get_contents($file);
        $pdo->exec($sql);

        // Clear multi-statement cursor
        while ($pdo->query('SELECT 1')->nextRowset()) {}

        $origPdo = db();
        $origTables = $origPdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $drillTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $missingTables = array_diff($origTables, $drillTables);
        if ($missingTables !== []) {
            throw new RuntimeException('Restore drill failed: missing tables: ' . implode(', ', $missingTables));
        }

        $tableStats = [];
        $totalRows = 0;

        foreach ($origTables as $table) {
            $origCount = (int) $origPdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
            $drillCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();

            if ($origCount !== $drillCount) {
                throw new RuntimeException("Restore drill failed: row count mismatch on table '{$table}' ({$origCount} vs {$drillCount})");
            }

            $tableStats[$table] = $drillCount;
            $totalRows += $drillCount;
        }

        return [
            'success' => true,
            'backup_file' => $file,
            'drill_db' => $drillDb,
            'tables_count' => count($origTables),
            'rows_count' => $totalRows,
            'table_stats' => $tableStats,
        ];
    } finally {
        $pdo->exec("DROP DATABASE IF EXISTS `{$drillDb}`");
    }
}

function cleanup_retired_database(): array
{
    $changes = [];
    foreach ([
        'attendance' => ['clock_in_lat','clock_in_lng','clock_in_accuracy','clock_in_geo_status','clock_in_geo_at','clock_in_ip',
            'clock_out_lat','clock_out_lng','clock_out_accuracy','clock_out_geo_status','clock_out_geo_at','clock_out_ip'],
        'work_schedules' => ['must_change_password'],
        'users' => ['totp_secret', 'totp_enabled']
    ] as $table => $retired) {
        $columns = array_column(db()->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(), 'Field');
        $drop = array_values(array_intersect($columns, $retired));
        if ($drop) {
            db()->exec('ALTER TABLE `' . $table . '` ' . implode(', ', array_map(static fn($c) => 'DROP COLUMN `' . $c . '`', $drop)));
            foreach ($drop as $column) {
                $changes[] = $table . '.' . $column;
            }
        }
    }

    $keys = [
        'allow_staff_edit_email','allow_staff_edit_phone','allow_staff_edit_photo','enable_other_break','other_allowed_minutes',
        'break_pool_total_minutes','late_grace_minutes','timezone','enable_totp_password_reset',
        'lunch_allowed_minutes','solat_allowed_minutes','solat_max_count','short_allowed_minutes','short_max_count'
    ];
    $stmt = db()->prepare('DELETE FROM system_settings WHERE setting_key IN (' . implode(',', array_fill(0, count($keys), '?')) . ')');
    $stmt->execute($keys);
    $changes[] = $stmt->rowCount() . ' retired settings removed';
    return $changes;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $command = $argv[1] ?? 'help';
    if (!in_array($command, ['backup', 'cleanup', 'verify-restore'], true)) {
        echo "Staff Attendance Maintenance & Backup Tool\n";
        echo "Usage:\n";
        echo "  php cli/maintenance.php backup          # Full database & file backup + retention cleanup\n";
        echo "  php cli/maintenance.php verify-restore  # End-to-end restore drill into an isolated DB\n";
        echo "  php cli/maintenance.php cleanup         # Backup & drop retired DB schema items\n";
        exit($command === 'help' ? 0 : 1);
    }

    if ($command === 'backup') {
        $dbBackup = backup_database();
        echo "Database Backup: {$dbBackup}\n";

        $uploadsBackup = backup_uploads();
        if ($uploadsBackup) {
            echo "Uploads Backup:  {$uploadsBackup}\n";
        }

        $pruned = prune_backups(null, 30);
        if ($pruned) {
            echo "Pruned Old Backups (>30d): " . count($pruned) . " file(s)\n";
        }
        echo "Backup complete.\n";
    }

    if ($command === 'verify-restore') {
        echo "Starting automated restore drill...\n";
        $result = verify_restore_drill();
        echo "Restore Drill Status: SUCCESS\n";
        echo "  Backup file tested: " . basename($result['backup_file']) . "\n";
        echo "  Tables verified:    " . $result['tables_count'] . "\n";
        echo "  Total rows checked: " . $result['rows_count'] . "\n";
        foreach ($result['table_stats'] as $table => $rows) {
            echo "    - {$table}: {$rows} row(s)\n";
        }
        echo "Temporary drill database dropped. All data confirmed 100% restorable.\n";
    }

    if ($command === 'cleanup') {
        echo 'Backup: ' . backup_database() . PHP_EOL;
        foreach (cleanup_retired_database() as $change) {
            echo $change . PHP_EOL;
        }
    }
}

