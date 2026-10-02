# Runbook — diagnosing problems fast

For whoever is on the phone with the client at 9pm. Work top to bottom.

**Golden rule before touching anything: take a database export.** Every
recovery path is easier from a backup than from memory.

---

## 0. First three questions to the client

1. **What exactly did you click, and what did you expect?**
2. **Does it happen every time, or once?**
3. **Is it one user, or everyone?** (one user → browser/cache/role; everyone → server)

Get a screenshot including the URL bar. The URL alone often identifies the page.

---

## 1. "The site won't open" / database error

| Symptom | Likely cause | Fix |
|---|---|---|
| Blank white page | PHP fatal error, display off | Check `logs/php-error.log` (if `harden.php` enabled) or cPanel → Errors |
| "Something went wrong" page | Caught exception | Same log. The message and line number are in there. |
| DB connection error | Wrong creds / DB server down / host suspended | Verify the 4 lines in `config.php`; check cPanel → MySQL is running; check account status/billing |
| 500 error | Usually `.htaccess` | Rename `.htaccess` to `.htaccess.off`. If the site returns, a directive is unsupported on that host — remove the offending block. |
| 403 on everything | File permissions | Folders `755`, files `644` |

**Never** fix a DB error by re-importing `schema.sql`. That wipes the business.

---

## 2. "The numbers are wrong"

Before assuming a bug, check the data:

1. Open the invoice → check each line against the underlying challans
2. Check the **product rate** and any **customer price override**
   (Customers → Prices) — a remembered override is the usual explanation
3. Check the **buyer's state code**. `24` → CGST+SGST. Anything else → IGST.
   A wrong state code on the customer is the most common "GST is wrong" cause.
4. Check whether **Royalty** was ticked on that invoice
5. Compare against the reference invoice in `00-CONTEXT.md` §2 — if that one
   still totals ₹1,45,584.94, the maths engine is fine and the issue is data

---

## 3. "A challan can't be edited"

By design. A challan locks once it is on an invoice (shows *View*, not *Edit*).

To unlock: open the invoice → **Delete this invoice** (admin only) → the
challans return to `open` and become editable. *Cancel* also reopens them but
keeps the invoice number burned.

## 4. "A challan is in the wrong order in the list"

The list sorts by **date**, newest first — not by challan number. A typo in
the year (e.g. `0026` instead of `2026`) sends that row to the bottom.
Fix the date on the challan. This is data, not a bug.

## 5. "It says the challan number already exists"

Challan numbers are manual and must be unique. Search the list for that
number — it is already used. Either the user is re-entering a load, or they
meant a different number.

## 6. "The PDF downloads as a file instead of printing"

The live app serves a real page (`print_invoice.php?id=…`) and prints
correctly. If a browser downloads it, that is a browser setting —
check Settings → Downloads → "Always open certain file types".
(The standalone demo HTML had this issue; it was fixed with Blob URLs.)

## 7. "Nobody can log in"

- Check for the lockout message → someone tried too many wrong passwords.
  Wait 15 minutes, or clear it:
  `DELETE FROM login_attempts WHERE username = 'admin';`
- Still failing → reset the password directly:
  ```sql
  -- generates a hash for the new password; run the PHP first, then the SQL
  -- php -r "echo password_hash('NEWPASSWORD', PASSWORD_DEFAULT);"
  UPDATE users SET password_hash = 'PASTE_HASH_HERE' WHERE username = 'admin';
  ```

## 8. "It's slow"

1. Is it slow for everyone, or one user on bad internet?
2. cPanel → check CPU/memory limits — shared hosts throttle silently
3. Confirm PHP **OPcache** is enabled (cPanel → Select PHP Version → Options)
4. Confirm `DB_HOST` is `localhost`, not a remote address
5. The lists are paginated at 15 rows, so row count should not matter.
   If a specific page is slow, note which one — it is fixable.

---

## 9. Where the evidence lives

| Source | Where |
|---|---|
| PHP errors | `logs/php-error.log` (needs `harden.php`), or cPanel → Errors |
| Failed logins | `login_attempts` table |
| Destructive actions | `audit_log` table (once wired up) |
| Who created a challan | `challans.created_by` |
| Web server errors | cPanel → Metrics → Errors |

---

## 10. Escalation

If the fix is not obvious in 20 minutes:

1. Take a DB export (always)
2. Collect: screenshot with URL, exact time, which user, what they clicked
3. Copy the relevant lines from the error log
4. Then debug — with the data safe, you can experiment freely on a copy

**Never debug by editing files directly on the live server.** Copy the app
to a subfolder pointed at a scratch database and break that instead.
