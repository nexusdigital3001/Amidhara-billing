# Amidhara Stone Crusher — Billing App · CONTEXT FILE

> **Read this first before making any change.** It exists so that no one
> (human or AI) has to rediscover how this app works. Keep it updated:
> when you change behaviour, change this file in the same edit.

Last updated: 2026-10-02 · Status: **LIVE IN PRODUCTION** · Hardening: **ENABLED**

---

## 1. What this is

A billing system for **Amidhara Stone Crusher**, a quarry / stone-crushing
business in Jamnagar, Gujarat. Built by Kartik (Nexus Digital) and handed
over to the client. It replaces manual/Tally billing for truck loads.

Status: live in production. The deployment URL and credentials are kept in
Nexus Digital's own records, deliberately not in this repository.

**Scale tier: T1 (Product).** A handful of staff users, a few thousand
records a year, single business. Not multi-tenant. Architect one tier up
(T2) only if this is resold to several clients — see §9.

---

## 2. Business domain (the part that must not be broken)

The whole app models one real-world flow:

```
Truck load  →  CHALLAN  →  INVOICE  →  PAYMENT  →  LEDGER
```

| Concept | Meaning |
|---|---|
| **Challan** | One truck load: party, item, weight, rate, kata chithi no, vehicle. Starts `open`. |
| **Invoice** | Bills a customer's open challans. Locks them to `invoiced`. |
| **Payment** | Money received from a customer. |
| **Ledger** | Running balance per customer = opening + active invoices − payments. |

### Rules that are load-bearing — do not "simplify" these

1. **Each challan item prints as its own invoice line.** They are NOT merged
   by product. There is a Challan No. column so every line traces back to a
   truck load. This was an explicit client requirement.
2. **Challan numbers are entered by hand** by the user (e.g. `5124`). They are
   NOT auto-generated. Uniqueness is enforced on `challans.challan_no`.
3. **Invoice numbers ARE auto-generated**, in two independent series:
   - GST: `ASC/01/2026-27`
   - Non-GST: `ASC/C/01/2026-27`
   Both reset to 1 on 1 April (Indian FY). **Numbers are never reused**, even
   after cancellation — GST law requires an unbroken series.
4. **Royalty** is a product flagged `is_royalty=1`. On a GST invoice it is
   auto-added as the last line, at a weight equal to the *total material
   weight* on that invoice, HSN 997337. Toggleable per invoice.
   *The Royalty product must exist in `products` or the option disappears.*
5. **GST split by state code.** Buyer state code `24` (Gujarat, same as seller)
   → CGST + SGST at half each. Any other state → IGST at full rate.
   One GST rate per invoice; mixed rates are refused with an error.
6. **Pricing**: rate comes from the product master unless that customer has an
   override in `customer_prices`. Billing at a different rate auto-remembers
   it for that customer. The master rate is never changed by this.
7. **Cancel vs Delete are deliberately different**:
   - *Cancel* → invoice stays, number stays burned, challans reopen.
   - *Delete* (admin only) → invoice removed entirely, challans reopen so they
     can be edited. This is the only way to unlock a challan for editing.

### Reference invoice (regression anchor)

Any change to invoice maths must still reproduce this exactly:

| Line | Weight | Rate | Amount |
|---|---|---|---|
| Kapchi 20 MM | 151.52 | 500 | 75,760.00 |
| 10 MM | 108.40 | 350 | 37,940.00 |
| Royalty | 259.92 | 96 | 24,952.32 |
| Taxable | | | **1,38,652.32** |
| CGST 2.5% + SGST 2.5% | | | 3,466.31 each |
| **Grand total** | | | **₹1,45,584.94** |

Words: *INR One Lakh Forty Five Thousand Five Hundred Eighty Four and Ninety Four paise Only*

---

## 3. Tech stack

- **PHP 8** + **MySQL/MariaDB**, PDO only, prepared statements everywhere.
- **No Composer, no build step, no framework.** Upload the files and it runs.
  This is deliberate — the client is on cheap shared hosting and nobody
  will maintain a toolchain.
- **No external fonts or CDNs.** System font stack only. (Google Fonts were
  removed because they cost 300–800ms per page on Indian shared hosting.)
- PDFs are produced by **browser print-to-PDF**, not a server PDF library.

## 4. File map

```
config.php            DB credentials — THE ONLY FILE TO EDIT ON DEPLOY
                      NOT in git. Copy config.sample.php and fill it in.
harden.php            production hardening — errors, session cookie, headers,
                      login rate limiting. Enabled by one require in config.php.
                      Purely additive; remove the require to disable.
schema.sql            fresh install only — NEVER run on a live DB
lib.php               db(), auth, GST maths, Indian number format + words, FY logic
header.php footer.php shared shell; footer holds the JS enhancers
login.php logout.php
index.php             dashboard (plain menu grid, no stats)
challans.php          list (paginated, compact)   challan_form.php  create/edit
invoices.php          list (paginated)            invoice_form.php  create/view/cancel/delete
payments.php  ledger.php
customers.php customer_form.php
products.php  product_form.php
users.php     settings.php   (settings holds the "Reset all data" danger zone)
pager.php             shared pagination control
print_challan.php     A4 challan
print_invoice.php     A4 invoice — TWO templates in one file (see below)
assets/app.css        screen      assets/print.css   A4 print
.htaccess             gzip, caching, blocks direct access to includes
docs/                 this documentation
```

### print_invoice.php has two completely separate layouts

- `if (!$isGst)` → **simple bill**: S.N., Date, Challan No., Kata Chithi,
  Description, Weight (Ton), Rate, Total. No bank details.
- `else` → **full GST tax invoice**, Tally-style, two-table structure
  (header block + items block) so the Challan No. column does not disturb
  the seller/buyer meta. Keeps bank details.

Both end with a money summary under the total:
`Previous balance → This invoice → Amount received (−) → Net outstanding`

---

## 5. Display conventions (client-specified, easy to break by accident)

| Thing | Format |
|---|---|
| Dates | `dd/mm/yyyy`, numeric month — `07/09/2026` |
| Weights | **2 decimals** — `151.52` |
| Money | Indian grouping — `1,45,584.94` |
| Unit ("Ton") | In the **column header only**, never repeated per row |

`qty()` and `dmy()` in `lib.php` are the single source of truth for these.

---

## 6. Roles

| Role | Can |
|---|---|
| `admin` | everything + users, settings, delete invoices, reset data |
| `biller` | challans, invoices, payments, masters |
| `viewer` | read + print only |

Default login on a fresh install is `admin` / `admin123` — **must be changed
immediately after deploy.**

---

## 7. Deploy / update procedure

Normal update = **upload the changed PHP/CSS files only**. Nothing else.

- **Never re-import `schema.sql`** on the live server — it would wipe data.
- Migrations, when genuinely needed, ship as separate one-time `.sql` files
  (`migrate-*.sql`) with a comment explaining exactly what they change.
- Always take a phpMyAdmin export before any file swap. See `09-backup-and-recovery.md`.

## 8. Known deliberate choices (don't "fix" these)

- Challan list sorts by **date desc**, not challan number. A manually typed
  higher number can therefore appear below a lower one. Client is aware.
  (There is one live record with a typo date `25/08/0026` that sorts to the
  bottom — that is bad data, not a bug.)
- No PDF library. Browser print is intentional.
- No soft-delete on products; use the `active` flag instead.
- Challan list: Status column removed, Item column added (between Customer and
  Kata Chithi), smaller font via `table.data.compact`. Client request.
- Lists paginate at 15 rows. The bottom total row sums **all** matching rows,
  not just the visible page — that is intentional, not a bug.
- `harden.php` fails **open**: if its tables are missing or the DB hiccups,
  login still works. Protection degrades rather than locking people out.

## 9. If this is resold to another client

Today it is **single-tenant**: one database, one business, `settings` holds
the company identity. That is correct for now.

To serve several clients, **do not fork the repo** (see security doc §5).
Two acceptable paths:

1. **Separate install per client** (simplest, recommended at this size):
   own folder, own database, own `config.php`. Cost: you must apply updates
   N times. Keep a single source repo and a deploy script.
2. **True multi-tenant**: add `tenant_id` to every table, scope every query,
   verify with a second test account. This is a significant architecture
   change and must be planned before it is coded — it is not a patch.

Path 1 is the right answer until roughly 5+ clients.

---

## 10. Open items / not built

- Round-off line on invoices (the client's sample had none)
- E-way bill fields, GSTR-1 / JSON export
- Mixed GST rates on one invoice
- Editing invoice lines after creation (cancel or delete and redo instead)
- Optional: hide the royalty toggle entirely on non-GST bills
  (currently it is still an optional checkbox on both bill types) — **[DECISION NEEDED]**
