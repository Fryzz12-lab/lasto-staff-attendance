-- =====================================================================
-- Phase 3B rollback notice
--
-- This file intentionally performs no destructive action by default.
-- Dropping this table permanently removes replay records, conflict history,
-- and request tombstones. Stop application traffic and take a backup before
-- any manual rollback.
-- =====================================================================

SELECT 'WARNING: rollback is destructive and is intentionally disabled. Review the backup and manually execute the DROP below only after explicit approval.' AS rollback_warning;

-- Explicit destructive rollback (manual action; do not run automatically):
-- DROP TABLE IF EXISTS `attendance_idempotency_operations`;
