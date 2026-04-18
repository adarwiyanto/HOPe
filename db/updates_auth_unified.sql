-- Unified auth update: pisahkan akun internal vs customer via username.

ALTER TABLE customers
  ADD COLUMN IF NOT EXISTS username VARCHAR(50) NULL AFTER name;

-- Backfill username customer lama (prioritas email prefix, fallback nama, fallback customer{id}).
UPDATE customers c
LEFT JOIN users u ON LOWER(u.username) = LOWER(
  CASE
    WHEN c.username IS NOT NULL AND TRIM(c.username) <> '' THEN TRIM(c.username)
    WHEN c.email IS NOT NULL AND c.email LIKE '%@%' THEN TRIM(SUBSTRING_INDEX(c.email, '@', 1))
    WHEN c.name IS NOT NULL AND TRIM(c.name) <> '' THEN LOWER(TRIM(REPLACE(REPLACE(REPLACE(c.name, ' ', '.'), '-', '.'), '..', '.')))
    ELSE CONCAT('customer', c.id)
  END
)
SET c.username =
  CASE
    WHEN c.username IS NOT NULL AND TRIM(c.username) <> '' THEN LOWER(TRIM(c.username))
    WHEN c.email IS NOT NULL AND c.email LIKE '%@%' THEN LOWER(TRIM(SUBSTRING_INDEX(c.email, '@', 1)))
    WHEN c.name IS NOT NULL AND TRIM(c.name) <> '' THEN LOWER(TRIM(REPLACE(REPLACE(REPLACE(c.name, ' ', '.'), '-', '.'), '..', '.')))
    ELSE CONCAT('customer', c.id)
  END
WHERE (c.username IS NULL OR TRIM(c.username) = '')
  AND u.id IS NULL;

-- Fallback deterministic untuk yang masih kosong/bentrok.
UPDATE customers c
LEFT JOIN users u ON LOWER(u.username) = LOWER(CONCAT('customer', c.id))
SET c.username = CONCAT('customer', c.id)
WHERE (c.username IS NULL OR TRIM(c.username) = '' OR u.id IS NOT NULL);

-- Pecahkan duplikat username antar customer dengan suffix id.
UPDATE customers c
JOIN (
  SELECT username
  FROM customers
  WHERE username IS NOT NULL AND username <> ''
  GROUP BY username
  HAVING COUNT(*) > 1
) d ON d.username = c.username
SET c.username = CONCAT(c.username, '.', c.id)
WHERE c.id NOT IN (
  SELECT min_id FROM (
    SELECT MIN(id) AS min_id, username
    FROM customers
    WHERE username IS NOT NULL AND username <> ''
    GROUP BY username
  ) x
);

ALTER TABLE customers
  ADD UNIQUE KEY uniq_customers_username (username);
