-- Migration: add employment_end_date to users
-- Run this once against the live database.
-- Safe to run on an existing database; it is idempotent via IF NOT EXISTS emulation.

USE `staff_attendance`;

-- Add employment_end_date immediately after joining_date
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `employment_end_date` DATE DEFAULT NULL
    COMMENT 'Last working day (inclusive). NULL = still employed.'
  AFTER `joining_date`;

-- Index used by eligibility queries that filter on this column
ALTER TABLE `users`
  ADD KEY IF NOT EXISTS `idx_users_end_date` (`employment_end_date`);
