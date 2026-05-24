# Brevix AI — API

Laravel 12 backend for Brevix AI. Provides authentication, QuickBooks OAuth, document upload/ingestion, deterministic fraud/risk scoring, Rex chat gateway, investigation workspace, recommendations, and case management.

## Architecture

```
brevixai (Expo frontend)
  → brevixai-api (this repo, Laravel 12, Sanctum auth)
      → brevixai-agents (FastAPI/LangGraph agent service, internal only)
      → PostgreSQL (production) / SQLite (local/test)
      → S3-compatible storage (uploads)
      → QuickBooks Online API
```

The agent service is a private internal dependency. The frontend never calls it directly.

## Local setup

**Requirements:** PHP 8.3+, Composer, SQLite (local/test), PostgreSQL (production)

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed          # optional: seed demo data
php artisan queue:work       # run queue worker (separate terminal)
php artisan schedule:work    # run scheduler (separate terminal)
php artisan serve            # http://localhost:8000
```

## Running tests

```bash
php artisan test             # full suite
php artisan test tests/Feature/AgentChatControllerTest.php   # single file
php artisan test --filter "test_name"                        # single test
```

The test suite uses an in-memory SQLite database and fakes all HTTP calls. No external services are needed.

## Key environment variables

See `.env.example` for the full list with comments. The most important for each deployment context:

| Variable | Where set | Notes |
|---|---|---|
| `APP_KEY` | All | Generate with `php artisan key:generate` |
| `APP_ENV` | All | `local`, `testing`, `production` |
| `APP_FRONTEND_URL` | Production | Exact frontend origin; sets CORS allowed origins |
| `CORS_ALLOWED_ORIGINS` | Production | Optional additional origins (comma-separated) |
| `DB_CONNECTION` / `DB_*` | Production | PostgreSQL connection details |
| `QUEUE_CONNECTION` | Production | `database` (or `redis` if available) |
| `FILESYSTEM_DISK` | Production | `s3` for production uploads |
| `BREVIX_S3_*` | Production | S3-compatible bucket credentials |
| `OPENAI_API_KEY` | Production | LLM provider key for Rex chat routing |
| `LLM_MODEL` | Production | Verify against deployed model name |
| `BREVIX_AGENT_SERVICE_URL` | Production | Internal URL of the agent service |
| `BREVIX_AGENT_SERVICE_KEY` | Production | Shared bearer token with agent service |
| `QB_REDIRECT_URI` | Production | Must match exactly what is registered in the QB developer portal |
| Company-scoped QuickBooks credentials | Application DB | Saved through the Settings page before OAuth connect; global `QB_CLIENT_ID` / `QB_CLIENT_SECRET` env fallback is intentionally not supported |

## Deployment

See [docs/deployment-readiness.md](docs/deployment-readiness.md) for the full checklist: migrations, storage setup, queue/scheduler, smoke check command, and rollback procedure.

Key pre-deploy commands:
```bash
php artisan migrate --force
php artisan smoke:check
```

## Documentation

- [Deployment Readiness](docs/deployment-readiness.md) — Environment setup, queue, storage, rollback
- [Investigation Workspace](docs/investigation-workspace.md) — Investigation lifecycle, evidence, report export
- [Production Readiness Review](docs/production-readiness-review.md) — Ongoing security and readiness tracker
