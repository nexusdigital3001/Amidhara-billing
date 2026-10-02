-- Amidhara Stone Crusher — migration for the "manual challan numbers" update
--
-- Run this ONCE on an ALREADY-DEPLOYED database (one created with the older
-- schema.sql). A brand-new install from the current schema.sql does NOT need it.
--
-- How to run:  phpMyAdmin -> your database -> SQL tab -> paste -> Go
--
-- What it does:
--   1. Drops the old uniqueness rule on the auto-generated (fy, seq_no).
--   2. Adds a uniqueness rule on the challan number you now type by hand.
--   3. Re-adds the Royalty product if a previous data reset removed it, so the
--      royalty option reappears on GST invoices.

-- 1 + 2: swap the unique key.  (If a step errors with "check that
-- column/key exists", it just means it was already applied — safe to ignore.)
ALTER TABLE challans DROP INDEX uq_ch_series;
ALTER TABLE challans ADD UNIQUE KEY uq_ch_no (challan_no);

-- 3: ensure the Royalty product exists (only inserts if it's missing)
INSERT INTO products (name, hsn, unit, rate, gst_rate, is_royalty, sort_order)
SELECT 'Royalty','997337','Ton',96.00,5.00,1,99
WHERE NOT EXISTS (SELECT 1 FROM products WHERE is_royalty = 1);

-- Done. You should see your Royalty product back under Products, and the
-- royalty option back on GST invoices.
SELECT name, hsn, is_royalty FROM products WHERE is_royalty = 1;
