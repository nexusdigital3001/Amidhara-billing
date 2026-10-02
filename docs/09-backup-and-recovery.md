# Backup & Recovery

> The skill's rule: **a backup you have never restored is not a backup.**
> Do the restore drill in §4 once. It takes 15 minutes and is the only
> thing that proves any of this works.

---

## 1. What has to be backed up

| What | Where it lives | Changes | Priority |
|---|---|---|---|
| **Database** | MySQL — challans, invoices, payments, customers, products, users, settings | constantly | **CRITICAL** — this is the business. Cannot be recreated. |
| Application files | the app folder on the host | only when you deploy | Low — you have the zip |
| `config.php` | app folder | almost never | Medium — 4 lines, but note them down |

Everything that matters is the database. The code is replaceable.

---

## 2. Backup schedule (recommended)

| Layer | Frequency | Retention | Who |
|---|---|---|---|
| Host's own automatic backup | daily | per host plan | hosting provider |
| **Manual/cron SQL export** | daily | 30 days | you |
| Off-host copy (Drive/email) | weekly | 12 weeks | you |
| Before any file update or data reset | every time | keep 3 | you |

**Do not rely only on the host's backup.** If the account is suspended or
deleted, the backups usually go with it. At least one copy must live
somewhere that is not the hosting account.

---

## 3. How to take a backup

### Option A — phpMyAdmin (manual, no setup)
1. cPanel → phpMyAdmin → select the database
2. **Export** tab → Quick → SQL → **Go**
3. Save as `amidhara-YYYY-MM-DD.sql`
4. Once a week, upload that file to Google Drive

### Option B — cPanel scheduled export (automatic, preferred)
If the host allows cron jobs, schedule daily at 02:00:

```bash
mysqldump -u DBUSER -p'DBPASS' DBNAME \
  --single-transaction --quick --routines \
  | gzip > ~/backups/amidhara-$(date +\%F).sql.gz

# keep only the last 30 days
find ~/backups -name 'amidhara-*.sql.gz' -mtime +30 -delete
```

Create `~/backups` first and make sure it is **outside `public_html`** so the
dumps are not downloadable from the web.

### Before every deploy or "Reset all data"
Take a manual export first. Non-negotiable — see the runbook.

---

## 4. Restore drill — do this once, now

Prove the backup works *before* you need it.

1. In cPanel → MySQL Databases, create a scratch DB, e.g. `amidhara_test`
2. phpMyAdmin → select it → **Import** → choose your latest `.sql` → Go
3. Copy the app to a subfolder, e.g. `public_html/resttest/`
4. Edit that copy's `config.php` to point at `amidhara_test`
5. Open it, log in, confirm challans and invoices are all there
6. Delete the scratch DB and the folder

If step 5 shows the data, your backup is real. Write today's date here:

**Last successful restore drill:** `____________`

---

## 5. Recovery scenarios

| Scenario | Action |
|---|---|
| Someone deleted an invoice by mistake | Invoices are soft-recoverable: *Cancel* keeps the record. If it was *Deleted*, restore yesterday's dump to a scratch DB, read the row, re-enter manually. Do **not** restore over live data for one record. |
| "Reset all data" pressed by accident | Restore the most recent dump over the live DB. Everything after that dump's timestamp is lost — this is why daily backups matter. |
| Site shows a database connection error | See `17-runbook.md` §1 |
| Host account lost / provider gone | Take the off-host weekly dump + the app zip, put it on any PHP+MySQL host, edit `config.php`. Roughly a 1-hour recovery. This is the reason for the off-host copy. |
| Data looks wrong but nothing is missing | Do not restore. Diagnose first — see the runbook. A restore would destroy correct newer data. |

---

## 6. Restoring over the live database (dangerous — read fully)

Only when you genuinely mean to roll the whole business back in time.

1. **Take a fresh export first** — even of the broken state. You may need it.
2. phpMyAdmin → select the live DB → **Import** → your `.sql` file
3. If the dump does not include `DROP TABLE` statements, empty the DB first,
   otherwise you get duplicate-key errors
4. Log in and verify the most recent challan and invoice look correct
5. Tell the client exactly which hours of data were lost, so they can re-enter

---

## 7. What is NOT backed up and cannot be recovered

- Anything typed after the last backup ran
- PDFs — these are generated on demand and never stored. Not a problem:
  the invoice can always be re-printed from its record.
