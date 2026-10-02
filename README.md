# Amidhara Billing

Billing and invoicing for a stone-crushing / quarry business in Gujarat, India.
Manages the **challan → invoice → payment → ledger** flow, produces GST and
non-GST invoices in Indian formats, and prints A4 documents.

Built by [Nexus Digital](https://github.com/nexusdigital3001). Live in production.

---

## Read this first

**[`docs/00-CONTEXT.md`](docs/00-CONTEXT.md)** — what the app is, the business
rules that must not be broken, the file map, and the display conventions.
Read it before changing anything. It exists so nobody has to rediscover how
this works.

| Doc | Read when |
|---|---|
| [`docs/00-CONTEXT.md`](docs/00-CONTEXT.md) | **Always first.** Business rules, architecture, conventions. |
| [`docs/05-security-audit.md`](docs/05-security-audit.md) | Auditing, hardening, or onboarding a new client. |
| [`docs/09-backup-and-recovery.md`](docs/09-backup-and-recovery.md) | Setting up backups, or recovering lost data. |
| [`docs/17-runbook.md`](docs/17-runbook.md) | Production is broken and someone is waiting. |

---

## Stack

PHP 8 + MySQL/MariaDB. PDO with prepared statements throughout.

**No Composer, no npm, no build step, no framework, no CDN.** Upload the files
and it runs. This is deliberate: the target is cheap Indian shared hosting and
nobody will maintain a toolchain. It also means zero dependencies to patch and
no third-party script on any page.

PDFs come from browser print-to-PDF, not a server-side PDF library.

---

## Install

1. Create a MySQL database and user
2. Import `schema.sql` **(fresh installs only — never on a live database)**
3. `cp config.sample.php config.php` and fill in the four credential lines
4. Upload everything to the web root
5. Run `docs/sql/001-harden-login-attempts.sql` to enable login rate limiting
6. Log in with `admin` / `admin123` — **change this password immediately**
7. Set company details, GSTIN and bank details under Settings
8. Add your products and customers

### Updating an existing install

Upload only the changed files. Do **not** re-import `schema.sql`.
Take a database export first — always.

---

## Security

`harden.php` adds error suppression and logging, session cookie flags,
security headers, and login rate limiting. It is enabled by a single
`require_once` in `config.php` and is purely additive — remove the line and
the app behaves exactly as before.

Audited against the OWASP-style checklist in `docs/05-security-audit.md`.
Clean on SQL injection, password storage, CSRF, and secret handling.
Known accepted risks and outstanding items are documented there rather than
left implicit.

**`config.php` is gitignored.** Real credentials never enter this repo.

---

## Multi-client use

This is **single-tenant** — one database, one business. To serve another
client, run a separate install with its own database. Do not fork the repo
per client. See `docs/00-CONTEXT.md` §9.
