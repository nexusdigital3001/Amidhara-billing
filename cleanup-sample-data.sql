-- Amidhara Stone Crusher — one-time cleanup
--
-- Run this ONCE on your live database to remove the sample product and
-- customer data that came with the first upload. It keeps your admin
-- login and all Settings untouched.
--
-- How to run:
--   phpMyAdmin -> select your database -> SQL tab -> paste this -> Go
--
-- Safe to run even if you've already created real challans/invoices —
-- it only touches the seeded sample rows, not anything you've entered.
-- (If you want to wipe EVERYTHING including your own data, use the
-- "Reset all data" button on the Settings page inside the app instead —
-- it's safer because it requires typing a confirmation phrase.)

DELETE FROM customer_prices WHERE customer_id IN (
  SELECT id FROM customers WHERE name = 'POOJA BUILDERS'
);
DELETE FROM customers WHERE name = 'POOJA BUILDERS';

DELETE FROM products WHERE name IN (
  'Kapchi 20 MM','10 MM','Grit','Dust','Rubble','GSB','WMM','40 MM','Sand','Royalty'
);

-- Done. Products and Customers should now both be empty:
SELECT (SELECT COUNT(*) FROM products)  AS products_left,
       (SELECT COUNT(*) FROM customers) AS customers_left;
