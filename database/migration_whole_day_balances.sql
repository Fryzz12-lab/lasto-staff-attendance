-- Convert adjustment balances to whole days only.
-- Run the validation SELECTs first. They must return 0 rows.
SELECT 'offday_adjustment_reviews' AS source, id, amount FROM offday_adjustment_reviews WHERE amount <> ROUND(amount);
SELECT 'leave_replacement_reviews' AS source, id, amount FROM leave_replacement_reviews WHERE amount <> ROUND(amount);
SELECT 'staff_balance_ledger' AS source, id, amount FROM staff_balance_ledger WHERE amount <> ROUND(amount);

-- The current business rule permits only 0 or 1 day for each decision.
ALTER TABLE offday_adjustment_reviews MODIFY amount TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE leave_replacement_reviews MODIFY amount TINYINT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE staff_balance_ledger MODIFY amount SMALLINT NOT NULL;
