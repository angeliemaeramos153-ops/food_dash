-- Requirement D: Account Lockout & Brute-Force Protection Schema Migration
-- Food Dash Database

-- 1. Ensure `failed_login_attempts` column exists in `users` table
SET @exist_failed_login := (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'users' 
      AND COLUMN_NAME = 'failed_login_attempts'
);

SET @sql_failed_login := IF(
    @exist_failed_login = 0,
    'ALTER TABLE users ADD COLUMN failed_login_attempts INT DEFAULT 0 AFTER role;',
    'SELECT "Column failed_login_attempts already exists" AS info;'
);
PREPARE stmt1 FROM @sql_failed_login;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- 2. Ensure `lockout_until` column exists in `users` table
SET @exist_lockout := (
    SELECT COUNT(*) 
    FROM INFORMATION_SCHEMA.COLUMNS 
    WHERE TABLE_SCHEMA = DATABASE() 
      AND TABLE_NAME = 'users' 
      AND COLUMN_NAME = 'lockout_until'
);

SET @sql_lockout := IF(
    @exist_lockout = 0,
    'ALTER TABLE users ADD COLUMN lockout_until DATETIME NULL AFTER failed_login_attempts;',
    'SELECT "Column lockout_until already exists" AS info;'
);
PREPARE stmt2 FROM @sql_lockout;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- 3. Synchronize existing failed attempts if any
UPDATE users 
SET failed_login_attempts = COALESCE(failed_attempts, 0)
WHERE (failed_login_attempts = 0 OR failed_login_attempts IS NULL) 
  AND failed_attempts IS NOT NULL 
  AND failed_attempts > 0;
