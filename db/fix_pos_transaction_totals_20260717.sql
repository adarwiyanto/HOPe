-- HOPe POS - koreksi grand total transaksi multi-item
-- Tanggal: 2026-07-17
-- Jalankan satu kali melalui phpMyAdmin setelah membuat backup database.
-- Aman dijalankan ulang: hasilnya deterministik berdasarkan detail item aktif.

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_pos_transaction_totals;
CREATE TEMPORARY TABLE tmp_pos_transaction_totals AS
SELECT
  transaction_code,
  GREATEST(
    0,
    SUM(COALESCE(total,0))
      - MAX(COALESCE(discount_amount,0))
      + MAX(COALESCE(tax_amount,0))
      + MAX(COALESCE(extra_fee,0))
  ) AS calculated_total
FROM sales
WHERE transaction_code IS NOT NULL
  AND transaction_code <> ''
  AND transaction_code LIKE 'TRX-%'
  AND COALESCE(is_active_revision,1)=1
GROUP BY transaction_code;

-- Preview transaksi yang akan dikoreksi.
SELECT
  s.transaction_code,
  COUNT(*) AS item_lines,
  SUM(COALESCE(s.total,0)) AS item_subtotal,
  MIN(COALESCE(s.grand_total,0)) AS old_min_grand_total,
  MAX(COALESCE(s.grand_total,0)) AS old_max_grand_total,
  t.calculated_total AS corrected_grand_total
FROM sales s
JOIN tmp_pos_transaction_totals t ON t.transaction_code=s.transaction_code
WHERE COALESCE(s.is_active_revision,1)=1
GROUP BY s.transaction_code, t.calculated_total
HAVING old_min_grand_total <> t.calculated_total
    OR old_max_grand_total <> t.calculated_total
ORDER BY s.transaction_code;

UPDATE sales s
JOIN tmp_pos_transaction_totals t ON t.transaction_code=s.transaction_code
SET s.grand_total=t.calculated_total
WHERE COALESCE(s.is_active_revision,1)=1
  AND COALESCE(s.grand_total,0) <> t.calculated_total;

COMMIT;

-- Verifikasi: query ini harus menghasilkan 0 baris.
SELECT
  s.transaction_code,
  MIN(COALESCE(s.grand_total,0)) AS min_grand_total,
  MAX(COALESCE(s.grand_total,0)) AS max_grand_total,
  GREATEST(
    0,
    SUM(COALESCE(s.total,0))
      - MAX(COALESCE(s.discount_amount,0))
      + MAX(COALESCE(s.tax_amount,0))
      + MAX(COALESCE(s.extra_fee,0))
  ) AS calculated_total
FROM sales s
WHERE s.transaction_code IS NOT NULL
  AND s.transaction_code <> ''
  AND s.transaction_code LIKE 'TRX-%'
  AND COALESCE(s.is_active_revision,1)=1
GROUP BY s.transaction_code
HAVING min_grand_total <> calculated_total
    OR max_grand_total <> calculated_total;
