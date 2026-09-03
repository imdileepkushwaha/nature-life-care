-- Binary MLM stored procedures
-- Auto-installed by includes/procedures.php on first use.
-- You can also run this manually in phpMyAdmin / MySQL client.

DROP PROCEDURE IF EXISTS sp_update_upline_counts;
DROP PROCEDURE IF EXISTS sp_find_binary_placement;
DROP PROCEDURE IF EXISTS sp_activate_member;
DROP PROCEDURE IF EXISTS sp_approve_withdrawal;
DROP PROCEDURE IF EXISTS sp_reject_withdrawal;

-- See includes/procedures.php for full CREATE PROCEDURE definitions
-- (kept in PHP so the app can auto-install safely without DELIMITER).
