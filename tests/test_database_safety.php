<?php
/**
 * Test Environment Database Safety & Isolation Guard
 * AGY Staff Attendance & Workforce Management System
 *
 * Enforces strict boundaries to prevent test suites from modifying the schema
 * or polluting data in protected environments ('staff_attendance', 'staff_attendance_uat').
 */

declare(strict_types=1);

final class TestDatabaseSafety
{
    private const PROTECTED_DATABASES = [
        'staff_attendance',
        'staff_attendance_uat',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
    ];

    /**
     * Verify whether a given database name is protected against schema modification / teardown.
     */
    public static function isProtected(string $dbName): bool
    {
        $normalized = strtolower(trim($dbName));
        if (in_array($normalized, self::PROTECTED_DATABASES, true)) {
            return true;
        }
        // Protect any database ending with _prod or _production
        if (str_ends_with($normalized, '_prod') || str_ends_with($normalized, '_production')) {
            return true;
        }
        return false;
    }

    /** A narrowly named target is necessary but explicit local configuration is also required. */
    public static function isDisposableDatabase(string $dbName): bool
    {
        return !self::isProtected($dbName)
            && preg_match('/\Astaff_attendance_[a-z0-9_]*disposable_[a-f0-9]{6,16}\z/i', trim($dbName)) === 1;
    }

    /** Fail closed before a database-backed test begins its fixture transaction. */
    public static function requireDisposableDatabase(PDO $pdo, string $suiteName): string
    {
        $currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $explicitDb = getenv('LASTO_DB_NAME');
        $host = strtolower((string)(defined('DB_HOST') ? DB_HOST : ''));
        if (!self::isDisposableDatabase($currentDb)
            || $explicitDb === false
            || trim($explicitDb) !== $currentDb
            || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException(
                "TEST SAFETY VIOLATION: '{$suiteName}' requires an explicitly selected, local, uniquely named disposable database; connected target was '{$currentDb}'."
            );
        }
        return $currentDb;
    }

    /**
     * Enforce that a test suite cannot perform schema-altering operations against a protected database.
     */
    public static function guardSchemaChanges(PDO $pdo, string $suiteName): void
    {
        $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        if (self::isProtected($currentDb)) {
            throw new RuntimeException(
                "TEST SAFETY VIOLATION: Test suite '{$suiteName}' attempted schema-altering operations against protected database '{$currentDb}'. Schema modifications must exclusively run inside isolated disposable test databases."
            );
        }
    }

    /**
     * Guard against dropping or truncating protected databases.
     */
    public static function guardDropDatabase(string $dbName): void
    {
        if (self::isProtected($dbName)) {
            throw new RuntimeException(
                "TEST SAFETY VIOLATION: Attempted DROP/TRUNCATE against protected database '{$dbName}'! Operation blocked."
            );
        }
    }

    /**
     * Require that a table exists in the connected database without auto-migrating it.
     */
    public static function requireTable(PDO $pdo, string $tableName, string $suiteName): void
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$tableName]);
        $exists = ((int) $stmt->fetchColumn()) > 0;

        if (!$exists) {
            $currentDb = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (self::isProtected($currentDb)) {
                throw new RuntimeException(
                    "ENVIRONMENT CONFIGURATION ERROR: Required table '{$tableName}' is missing from protected database '{$currentDb}' during '{$suiteName}'. Automatic in-test migration is prohibited against protected databases. Apply the appropriate authorized migration first."
                );
            }
        }
    }
}
