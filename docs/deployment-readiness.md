# Deployment Readiness — Brevix API

This document covers everything needed to deploy the Brevix API to a new environment, with emphasis on the investigation workspace feature (Phases 4.1–4.3) and the recommendation expiration scheduler.

---

## Quick Reference

```bash
# 1. Install dependencies (no dev)
composer install --no-dev --optimize-autoloader

# 2. Set environment variables (copy and fill .env.example)
cp .env.example .env
php artisan key:generate

# 3. Run migrations
php artisan migrate

# 4. Cache config and routes
php artisan config:cache
php artisan route:cache

# 5. Run smoke check
php artisan smoke:check

# 6. Run full test suite (staging only)
composer test
```

The admin personal finance S3 import path requires `league/flysystem-aws-s3-v3`; run `composer update league/flysystem-aws-s3-v3` after changing Composer dependencies and commit the refreshed lockfile before deploying.

---

## Required Migrations

All 27 migrations must run in order. The investigation workspace adds 3 new structures:

| Migration | What it adds |
|---|---|
| `2026_05_18_150000_add_investigation_workspace_to_audit_cases` | 7 investigation columns on `audit_cases`; creates `investigation_activity_events` |
| `2026_05_18_160000_create_investigation_evidence_items_table` | `investigation_evidence_items` table |
| `2026_05_19_120000_create_investigation_report_exports_table` | `investigation_report_exports` table |

### Verify migrations ran

```sql
-- Confirm investigation columns on audit_cases
SELECT column_name
FROM information_schema.columns
WHERE table_name = 'audit_cases'
  AND column_name IN (
    'investigation_status',
    'investigation_assigned_user_id',
    'investigation_priority',
    'investigation_summary',
    'investigation_notes',
    'last_activity_at',
    'investigation_metadata'
  );
-- Expect: 7 rows

-- Confirm tables exist
SELECT table_name
FROM information_schema.tables
WHERE table_name IN (
    'investigation_activity_events',
    'investigation_evidence_items',
    'investigation_report_exports'
);
-- Expect: 3 rows
```

### Rollback

```bash
# Roll back the 3 investigation migrations only
php artisan migrate:rollback --step=3

# Full rollback (destructive — drops all tables)
php artisan migrate:reset
```

---

## Required Environment Variables

### Core (all environments)

| Variable | Required | Notes |
|---|---|---|
| `APP_KEY` | Yes | Generate with `php artisan key:generate` |
| `APP_ENV` | Yes | `production` in prod |
| `APP_URL` | Yes | Public base URL (no trailing slash) |
| `APP_FRONTEND_URL` | Yes | Frontend origin for CORS |
| `DB_CONNECTION` | Yes | Must be `pgsql` in production (JSONB support required) |
| `DB_HOST` | Yes | PostgreSQL host |
| `DB_PORT` | Yes | Default `5432` |
| `DB_DATABASE` | Yes | Database name |
| `DB_USERNAME` | Yes | |
| `DB_PASSWORD` | Yes | |

### Investigation workspace

| Variable | Required | Default | Notes |
|---|---|---|---|
| `RECOMMENDATION_EXPIRATION_DAYS` | Yes | `30` | Days before pending recommendations expire; must be ≥ 1 |

### Queue

| Variable | Required | Default | Notes |
|---|---|---|---|
| `QUEUE_CONNECTION` | Yes | `database` | Use `redis` for production scale |
| `REDIS_HOST` | If redis | `127.0.0.1` | |
| `REDIS_PORT` | If redis | `6379` | |
| `REDIS_PASSWORD` | If redis | | |

### Agent service integration

| Variable | Required | Notes |
|---|---|---|
| `BREVIX_AGENT_SERVICE_URL` | Yes | Internal agent service base URL |
| `BREVIX_AGENT_SERVICE_KEY` | Yes | Shared secret for agent tool requests |
| `BREVIX_AGENT_TIMEOUT` | No | Default `60` seconds |

### Admin personal finance

| Variable | Required | Notes |
|---|---|---|
| `PERSONAL_FINANCE_ENABLED` | Yes | Set `true` in the dev environment to enable `/api/admin/personal-finance/*` |
| `PERSONAL_FINANCE_STATEMENT_DISK` | Yes | Set `s3` for deployed imports |
| `PERSONAL_FINANCE_STATEMENT_PREFIX` | Yes | Set `personal-finance` for `s3://brevix-s3-bucket-1/personal-finance/` |
| `BREVIX_S3_BUCKET` | Yes | Set `brevix-s3-bucket-1`; avoids Amplify's reserved `AWS_*` env prefix |
| `BREVIX_S3_ACCESS_KEY_ID` | Yes | Must have read access to the statement prefix |
| `BREVIX_S3_SECRET_ACCESS_KEY` | Yes | |
| `BREVIX_S3_REGION` | Yes | Bucket region |
| `ADMIN_EMAIL` | Yes | `admin@admin.brevixai.com` |
| `ADMIN_EMAILS` | Yes | Comma-separated admin allowlist; include `admin@admin.brevixai.com` |
| `ADMIN_PASSWORD` | Yes for seeding | Set in the deployment secret store, then run the admin seeder |

Create or update the admin login after migrations:

```bash
php artisan db:seed --class=AdminUserSeeder
```

Do not commit `ADMIN_PASSWORD`; keep it in the environment/secret manager.

---

## Storage and PDF Requirements

### Storage directories

The following paths must exist and be writable by the web server process:

```
storage/app/
storage/framework/cache/
storage/framework/sessions/
storage/framework/views/
storage/logs/
```

```bash
# Fix permissions (adjust user as needed)
chown -R www-data:www-data storage/ bootstrap/cache/
chmod -R 775 storage/ bootstrap/cache/
```

### DomPDF (PDF report generation)

`POST /api/investigations/{id}/reports` with `format=pdf` uses `barryvdh/laravel-dompdf ^3.1`.

- The package must be present in `vendor/` after `composer install`.
- No additional system packages are required (DomPDF is pure PHP).
- The DomPDF config is auto-loaded from `vendor/barryvdh/laravel-dompdf/config/dompdf.php`. Publishing it is optional.
- Verify with the smoke check: `php artisan smoke:check`

PDF reports are generated dynamically and not written to disk. No persistent storage path is required for PDF output.

### Personal finance statement imports

The admin personal finance import endpoints read PDF statements from Laravel storage. For the dev environment configured for S3:

```env
PERSONAL_FINANCE_ENABLED=true
PERSONAL_FINANCE_STATEMENT_DISK=s3
PERSONAL_FINANCE_STATEMENT_PREFIX=personal-finance
BREVIX_S3_BUCKET=brevix-s3-bucket-1
```

The deployed API path is `/api/admin/personal-finance/*`. The legacy `/api/local/personal-finance/*` path remains available only in configured local/testing environments.

---

## Queue and Scheduler

### Queue worker

The application uses a database-backed queue (configurable via `QUEUE_CONNECTION`).

```bash
# Start queue worker (production — use a process manager like Supervisor)
php artisan queue:work --tries=3 --timeout=90

# Supervisor example config (adjust paths):
# [program:brevix-worker]
# command=php /var/www/artisan queue:work --tries=3 --timeout=90
# autostart=true
# autorestart=true
# user=www-data
# numprocs=2
# stdout_logfile=/var/www/storage/logs/worker.log
```

### Scheduled commands

Register the Laravel scheduler in cron (runs every minute):

```cron
* * * * * cd /var/www && php artisan schedule:run >> /dev/null 2>&1
```

#### `recommendations:expire`

This command marks stale pending recommendations as expired. It is the only scheduled command directly related to the investigation workspace.

- **Signature:** `recommendations:expire`
- **Configured by:** `RECOMMENDATION_EXPIRATION_DAYS` (default: 30 days)
- **Recommended schedule:** daily (e.g., midnight UTC)
- **Add to** `routes/console.php`:

```php
Schedule::command('recommendations:expire')->dailyAt('00:00');
```

Run manually to verify:

```bash
php artisan recommendations:expire
```

---

## Test Commands

### Full test suite

```bash
composer test
# Equivalent: php artisan config:clear && php artisan test
```

### Investigation workspace only (83 tests, 329 assertions)

```bash
php artisan test --filter Investigation
```

### Individual feature suites

```bash
php artisan test tests/Feature/InvestigationWorkspaceTest.php
php artisan test tests/Feature/InvestigationEvidenceLedgerTest.php
php artisan test tests/Feature/InvestigationReportExportTest.php
php artisan test tests/Feature/InvestigationPackageManifestTest.php
```

### Verbose output

```bash
php artisan test --filter Investigation --verbose
```

---

## Deployment Smoke Check

A dedicated artisan command verifies readiness without touching the database:

```bash
php artisan smoke:check
```

Checks performed:

| Category | Check |
|---|---|
| Environment | `APP_KEY`, `APP_URL`, `DB_CONNECTION`, `RECOMMENDATION_EXPIRATION_DAYS` |
| Dependencies | `barryvdh/laravel-dompdf` installed, DomPDF config loaded |
| Routes | All 7 investigation workspace routes registered |
| Commands | `recommendations:expire` registered |
| Storage | `storage/app` and `storage/logs` writable |

Returns exit code `0` on full pass, `1` on any failure. Safe to run in CI/CD pipelines.

---

## Pre-Deploy Checklist

- [ ] `composer install --no-dev --optimize-autoloader` completes without errors
- [ ] All required environment variables are set in production `.env`
- [ ] `DB_CONNECTION=pgsql` (JSONB column support required)
- [ ] `php artisan key:generate` has been run (if new environment)
- [ ] `php artisan migrate` completes — confirm 27 migrations ran
- [ ] Investigation columns present on `audit_cases` (7 columns)
- [ ] `investigation_activity_events`, `investigation_evidence_items`, `investigation_report_exports` tables exist
- [ ] `storage/` and `bootstrap/cache/` are writable by the web process
- [ ] `php artisan config:cache` completes without errors
- [ ] `php artisan route:cache` completes without errors
- [ ] `php artisan smoke:check` exits 0

---

## Post-Deploy Smoke Test

After deploying, run these checks against the live environment:

### Health endpoint

```bash
curl -s https://your-api.example.com/up
# Expect: HTTP 200
```

### Authentication

```bash
curl -s -X POST https://your-api.example.com/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"test@example.com","password":"..."}' | jq .token
# Expect: a non-null token string
```

### Investigation list (authenticated)

```bash
TOKEN=<token-from-above>
curl -s https://your-api.example.com/api/investigations \
  -H "Authorization: Bearer $TOKEN" | jq .data
# Expect: an array (may be empty for a fresh environment)
```

### Report generation

```bash
# Requires a valid investigation ID
curl -s -X POST https://your-api.example.com/api/investigations/{id}/reports \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -d '{"format":"json"}' | jq .data.report_hash
# Expect: a non-null SHA-256 hash string
```

### Agent block

```bash
# Use an agent-tool token (BREVIX_AGENT_SERVICE_KEY)
curl -s -X POST https://your-api.example.com/api/investigations/{id}/evidence \
  -H "X-Agent-Tool-Key: $BREVIX_AGENT_SERVICE_KEY" \
  -H 'Content-Type: application/json' \
  -d '{"title":"test","type":"document"}' | jq .status
# Expect: 403
```

### Activity events written

After any workspace action, confirm a row appears in `investigation_activity_events`:

```sql
SELECT event_type, created_at
FROM investigation_activity_events
ORDER BY created_at DESC
LIMIT 5;
```

### Report export history

After generating a report, confirm a row appears in `investigation_report_exports`:

```sql
SELECT format, report_hash, generated_at
FROM investigation_report_exports
ORDER BY generated_at DESC
LIMIT 5;
```

---

## Rollback Procedure

### Application rollback

1. Redeploy the previous release artifact.
2. Run `php artisan config:cache` and `php artisan route:cache` against the previous release.
3. Do **not** roll back migrations unless the new tables/columns caused data corruption — the investigation tables are additive and backward-compatible.

### Migration rollback (if needed)

```bash
# Roll back only the 3 investigation migrations
php artisan migrate:rollback --step=3
```

This drops `investigation_report_exports`, `investigation_evidence_items`, and removes the 7 investigation columns from `audit_cases`. All investigation activity events, evidence, and report export history will be permanently lost.

---

## See Also

- [Investigation Workspace — full feature reference](investigation-workspace.md)
- [Investigation Lifecycle — case → recommendation → workspace flow](investigation-workspace-lifecycle.md)
