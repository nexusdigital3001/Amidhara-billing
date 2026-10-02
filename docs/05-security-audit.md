# Security Audit — Amidhara Billing

Audited 2026-09-10 against the vibe-production-engineer security checklist.
Method: source review of every endpoint, grep for the known vibe-coded
failure patterns. **Not yet done: automated scanner (OWASP ZAP) and a
second-model adversarial pass** — see §4.

---

## 1. What is already correct

These were checked and passed. Do not regress them.

| Control | Status |
|---|---|
| SQL injection | **PASS** — PDO prepared statements everywhere. The only interpolated SQL is `TRUNCATE \`$t\`` in settings.php, where `$t` comes from a hardcoded array, and `$where` fragments in list pages, which are fixed strings with `?` placeholders. No user input reaches SQL as text. |
| Password storage | **PASS** — `password_hash()` / `password_verify()` (bcrypt). No plaintext, no MD5. |
| Session fixation | **PASS** — `session_regenerate_id(true)` on login. |
| CSRF | **PASS** — `check_csrf()` on every state-changing form (challan, invoice, customer, product, payment, user, settings). |
| Secrets in code | **PASS** — credentials live only in `config.php`, which `.htaccess` blocks from direct web access. Nothing in client-side JS. |
| Include files exposed | **PASS** — `.htaccess` denies `config.php`, `schema.sql`, `lib.php`, `header.php`, `footer.php`. `Options -Indexes` set. |
| Client-side trust | **PASS** — all totals, GST maths and numbering are computed server-side. The JS preview is display only. |
| Third-party scripts | **PASS** — there are none. No CDN, no analytics, no fonts. Unusually good attack surface. |
| Dependencies | **PASS** — zero. No Composer, no npm. Nothing to audit or patch. |

---

## 2. Findings, ranked by exploitability

### HIGH — no brute-force protection on login
`login.php` accepts unlimited password attempts. A script can try thousands
of passwords against `admin`. Combined with the well-known default
(`admin123`), this is the most realistic way this app gets breached.

**Fix:** rate-limit by IP + username; lock for N minutes after ~5 failures.
Additive, does not change successful-login behaviour. See `harden.php`.

**Deployment rule:** the seeded default password must be changed before a
install is handed over. Verified done on the current deployment.

---

### HIGH — verbose errors may leak DB internals
PHP's `display_errors` is not explicitly turned off. If the host leaves it
on, an uncaught PDO exception prints the query, file path, and potentially
the database name to the browser. A user clicking a broken link sees it.

**Fix:** force `display_errors=Off` + `log_errors=On` in production, and add
a global exception handler that shows a neutral page and logs the detail.
See `harden.php`.

---

### MEDIUM — session cookie not hardened
No `httponly`, `samesite`, or `secure` flags are set on the session cookie.
Any injected script could read it, and it can be sent over plain HTTP.

**Fix:** set cookie params before `session_start()`. See `harden.php`.
Requires HTTPS to be live (it is).

---

### MEDIUM — HTTPS not enforced
The site serves over HTTPS but there is no redirect from HTTP, and no HSTS.
A login submitted over `http://` would send the password in clear text.

**Fix:** redirect rule + HSTS header in `.htaccess`. See `harden.php` notes.

---

### MEDIUM — IDOR is possible but currently low impact
`print_invoice.php?id=7`, `invoice_form.php?id=7`, `challan_form.php?id=7`
load by sequential ID and check *that you are logged in*, not *that you may
see this record*.

Today every user belongs to the same business and every role is allowed to
read everything, so this is **not a live vulnerability** — it is by design.

**It becomes a real breach the moment a second client shares this database.**
If you ever go multi-tenant, ownership verification on every ID-bearing
request is mandatory, not optional. Recorded here so it is not forgotten.

---

### LOW — no audit trail of who did what
`challans.created_by` exists, but invoice cancel, invoice delete and the
"Reset all data" wipe are not recorded anywhere. If data goes missing there
is no way to answer "who deleted this and when".

**Fix:** an `audit_log` table written on destructive actions. Additive —
new table, no change to existing ones.

---

### LOW — "Reset all data" is a very large red button
It is admin-only and requires typing `DELETE ALL DATA`, which is good. But
it TRUNCATEs nine tables with no backup step and no record that it happened.

**Fix:** log it to `audit_log`, and put a line in the runbook that a manual
export must be taken first.

---

## 3. Deliberately accepted risks

| Risk | Why accepted |
|---|---|
| No MFA | 3–4 internal users on one business's billing. Disproportionate today. Revisit if the app holds several clients. |
| Sequential IDs in URLs | Single-tenant; all users may see all records. Would need UUIDs in a multi-tenant build. |
| No WAF / CDN | Low-value target, low traffic. Rate limiting at the app layer is the proportionate control. |

---

## 4. Not yet done — required before calling this "secure"

Per the skill, these cannot be claimed without actually running them:

- [ ] **OWASP ZAP scan** against the live URL (free, ~20 min). First scans on
      an app the builder thought was ready routinely find double-digit issues.
- [ ] **Adversarial review by a different model** — the model that wrote the
      code will not flag its own shortcut.
- [ ] **Second-account test** — log in as a `viewer` and confirm every
      admin-only URL (`users.php`, `settings.php`, invoice delete) is refused
      server-side, not just hidden in the nav.

That last one is quick and worth doing this week.
