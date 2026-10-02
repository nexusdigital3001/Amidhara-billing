# Docs index

| File | Read when |
|---|---|
| `00-CONTEXT.md` | **Always first.** What the app is, the business rules that must not break, file map, display conventions. |
| `05-security-audit.md` | Auditing, hardening, or before exposing the app to a new client. |
| `09-backup-and-recovery.md` | Setting up backups, or recovering lost data. |
| `17-runbook.md` | Something is broken in production and the client is waiting. |
| `sql/` | One-time additive migrations. Safe to run; they only create new tables. |

## Hardening quick start

1. Run `docs/sql/001-harden-login-attempts.sql` once in phpMyAdmin (creates 2 new tables, touches nothing existing)
2. Add this as the last line of `config.php`:
   `require_once __DIR__ . '/harden.php';`
3. Upload `harden.php` and `login.php`

To turn it all off again: delete that one line from `config.php`.
