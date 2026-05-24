# Brevix AI PRR — Handoff Context

Generated: 2026-05-24. Use this to resume the Production Readiness Review in a new context window.

## Three-codebase scope

| Repo | Path | Branch | State |
|---|---|---|---|
| `brevixai-api` | `/Users/joe.eagan/Documents/GitHub/brevixai-api` | `ready-review` | Uncommitted PRR-016 changes. Commit before next session. |
| `brevixai` | `/Users/joe.eagan/Documents/GitHub/brevixai` | `main` | Clean. All Wave 1 + Wave 2 changes committed. |
| `brevixai-agents` | `/Users/joe.eagan/Documents/GitHub/brevixai-agents` | unknown | Clean. |

Primary tracker: `brevixai-api/docs/production-readiness-review.md`

## Architecture

```
brevixai (Expo 55 / React Native Web, Expo Router, React 19)
  → brevixai-api (Laravel 12, Sanctum, PostgreSQL, S3-compatible storage)
    → brevixai-agents (FastAPI, LangGraph, 7-node DAG)
      → Laravel internal agent-tool endpoints (/api/internal/agent-tools/...)
```

**Confirmed decision**: The production Rex (AI chat) control plane is Laravel → brevixai-agents. Node/TypeScript orchestrator docs in `brevixai` are stale and have been marked superseded.

## Test suite baselines

| Repo | Command | Expected |
|---|---|---|
| `brevixai-api` | `php artisan test` | 247 tests pass |
| `brevixai` | `npm test -- --runInBand` | 108 tests pass |
| `brevixai` | `npm run test:e2e -- --project=chromium` | 3 tests pass |
| `brevixai-agents` | `.venv/bin/pytest tests/ --ignore=tests/test_evals.py -q` | 397 tests pass |
| `brevixai-agents` | `.venv/bin/python scripts/quality_gate.py --report-json reports/latest_benchmark_report.json` | 21/21 scenarios |

## What is committed vs. uncommitted

### Uncommitted in `brevixai-api` (commit this first)

1. `app/Http/Controllers/Api/AlertRecommendationController.php` — added `AlertRecommendationService` dependency and `run()` method (PRR-016)
2. `routes/api.php` — added `Route::post('/run', [AlertRecommendationController::class, 'run'])` at top of alerts group (PRR-016)
3. `tests/Feature/AlertRunEngineTest.php` — new file; 4 tests covering auth, clean company, high-risk company, pending count (PRR-016)
4. `docs/production-readiness-review.md` — tracker updated; PRR-016 resolved; handoff snapshot updated

### All committed (do not redo)

Wave 1 (all in `brevixai-api`, `brevixai`, `brevixai-agents`):
- PRR-007: `investigative_synthesis` passes through `BrevixAgentRunner::run()` and `AgentChatController::responseContract()` (was dropped entirely — frontend `RexWorkspace` expected it)
- PRR-002: Stale Node/TypeScript Rex architecture doc marked superseded in `brevixai/docs/superpowers/specs/2026-05-14-rex-chat-and-agent-architecture-review.md`
- PRR-005: `brevixai/app/index.tsx` now has auth redirect: `if (!isLoading && isAuthenticated) router.replace('/(dashboard)')`
- PRR-008: `brevixai/playwright.config.ts` webServer changed from `expo start --web --port 8081` to `npx expo export --platform web --output-dir dist && npx serve dist -p 8081 -s`; `serve` added as devDep; `brevixai/e2e/landing.test.ts` login selector fixed from `getByRole('link', { name: /Login/i })` to `getByText('Log In').first()`
- PRR-006: `brevixai/.github/workflows/ci.yml` now gates on Jest and Playwright in addition to typecheck
- PRR-001: `orchestrator/venv/` added to `brevixai/.gitignore`; `git rm -r --cached orchestrator/venv` run; 3,669 files removed from index (BFG history rewrite NOT done — ask user before force-push)
- PRR-013: Benchmark vendor seed loop in `brevixai-agents/app/graph.py` annotated with clarifying comment
- `.env` corruption: `PERSONAL_FINANCE_PDFTOTEXT_PATH` had `php artisan serve --host=127.0.0.1 --port=8000` appended; restored to `/usr/local/bin/pdftotext`
- PRR-009: CORS fails closed in production; `CorsAllowedOrigins` reads from env
- PRR-011: Upload tenancy — endpoints now scope by `company_id`; `UploadWorkflowSecurityTest` added
- PRR-012: Signup/auth error handling hardened; `AuthOnboardingTest` added

Wave 2 (all committed):
- PRR-003: `brevixai-api/README.md` rewritten; `brevixai/README.md` created
- PRR-010: `brevixai/.env.example` scoped to `EXPO_PUBLIC_*` only

## All open findings

| ID | Severity | Status | Finding | Key file(s) |
|---|---|---|---|---|
| PRR-004 | Medium | Open | `LLM_MODEL=chat-latest` in `.env.example` — verify against production model | `brevixai-api/.env.example` |
| PRR-014 | Low | Open | Agent optional tools fail silently (`vendor_risk`, `reconciliation_risk`, etc.) — no alerting | `brevixai-agents/app/graph.py` lines 234–362 |
| PRR-015 | Low | Open | Laravel–agent tool contract is informal; missing endpoints partially silenced | `brevixai-agents/app/tools/laravel.py` |
| PRR-017 | High | Open | Onboarding wizard is educational only — no step links to upload or QB connect | `brevixai/app/(dashboard)/onboarding.tsx` |
| PRR-018 | High | Open | Overview empty state looks like "no risk" not "no data"; no CTA to upload or connect | `brevixai/app/(dashboard)/overview.tsx` |
| PRR-019 | Medium | Open | QB OAuth callback redirects to `/settings` with no success signal | `brevixai-api/app/Http/Controllers/Api/IntegrationController.php:60` |
| PRR-020 | Medium | Open | QB sync is manual — no sync job queued after OAuth callback | `brevixai-api/app/Http/Controllers/Api/IntegrationController.php` |
| PRR-021 | Medium | Open | Rex returns generic "no data" message; no nudge to upload or connect QB | `brevixai/app/(dashboard)/index.tsx` |

## Wave 3 tracks not started

These are open-ended exploration + findings passes, not pre-known bugs:

1. **Data ingestion and integrations** — QuickBooks OAuth flow end-to-end, upload ingestion pipeline, S3 import handling, validation and error surfacing. Key files: `app/Services/QuickBooks*`, `app/Services/UploadService.php`, `app/Http/Controllers/Api/UploadController.php`.

2. **Fraud/risk/cases workflows** — Deterministic scoring completeness, case lifecycle state machine, evidence linking, recommendation expiration, audit trail accuracy. Key files: `app/Services/Risk*`, `app/Services/Cases*`, `app/Models/Case.php`, investigation controllers.

3. **Observability and operations** — Structured logging, trace ID propagation (Laravel ↔ agent service ↔ tools), LangSmith integration, health check endpoints, SLOs. Key files: `app/Logging/`, `app/Http/Middleware/`, `brevixai-agents/app/main.py` health route.

4. **Test and release gates (completion pass)** — Confirm all CI gates are wired in all three repos; no track leaves a blocking gap uncovered.

## Key service interfaces

### `AlertRecommendationService::getAlertRecommendations(string $companyId): array`

Returns: `{ company_id: string, recommended_alerts: array<AlertRecommendationDTO> }`

Runs 4 deterministic scoring services: `VendorRiskScoringService`, `ReconciliationRiskScoringService`, `EntityRelationshipRiskScoringService`, `AggregateRiskScoringService`. Upserts `AlertRecommendation` records and expires stale ones.

Now wired to `POST /api/alerts/run` (PRR-016, this session).

### `BrevixAgentRunner::run(array $payload): array`

Calls `brevixai-agents` service. Returns contract with keys:
`message`, `intent`, `findings`, `recommended_actions`, `can_create_alert`, `requires_review`, `trace_id`, `investigative_synthesis`

`investigative_synthesis` is `null` when agent omits it (PRR-007 fix).

### Agent service internal tool endpoints (auth: `X-Brevix-Agent-Key`)

All under `/api/internal/agent-tools/`:
- `GET /companies/{id}/context`
- `GET /companies/{id}/risk-summary`
- `GET /company/{id}/vendor-risk`
- `GET /company/{id}/reconciliation-risk`
- `GET /company/{id}/entity-relationship-risk`
- `GET /company/{id}/aggregate-risk-summary`
- `GET /company/{id}/alert-recommendations`
- `GET /company/{id}/case-recommendations`

## Recommended next actions (in priority order)

1. **Commit PRR-016** in `brevixai-api` (controller + route + test + tracker).
2. **PRR-017/018** — quick UX wins before next demo: add onboarding action links and overview empty-state banner.
3. **Wave 3: Data ingestion track** — QB OAuth and upload pipeline audit.
4. **Wave 3: Fraud/risk/cases track** — scoring completeness, case lifecycle.
5. **PRR-019/020** — QB success signal and auto-sync after OAuth.

## Open questions for user

- Should `orchestrator/venv` git history be rewritten (BFG, requires force-push and all collaborators re-clone)?
- What is the production URL for brevixai-agents health check (needed for Observability track)?
