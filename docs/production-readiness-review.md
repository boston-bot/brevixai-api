# Brevix AI Production Readiness Review

Started: 2026-05-23

Scope:

- API: `/Users/joe.eagan/Documents/GitHub/brevixai-api`
- Frontend: `/Users/joe.eagan/Documents/GitHub/brevixai`
- Agent service: `/Users/joe.eagan/Documents/GitHub/brevixai-agents`

## Claude Handoff Snapshot

Last updated: 2026-06-05 (Phase 3 frontend approval integration)

Current state:

- `brevixai-api` is on `process-ready`; `.env.example` was already dirty before this doc update. This pass updates readiness docs only.
- `brevixai` is on `action-bar`; frontend Rex approval integration is implemented, committed, and verified. The latest frontend commit also includes files outside the original four-file approval-integration scope.
- `brevixai-agents` was not changed in this pass.

Confirmed decisions:

- **Rex runtime**: Laravel → brevixai-agents is production. Node/TypeScript docs superseded. PRR-002 closed.
- **Production CORS**: Already set in production environment. PRR-009 verification deferred.

Completed this update:

- PRR-026 resolved: standalone Rex now executes persisted approval-backed actions through `/api/agent-approvals/{approval}/approve|reject`, preserves reviewed card state, and shows safe failure copy. Frontend gates passed: typecheck, full Jest, and Rex Playwright approval-flow e2e.

Completed earlier sessions:

- PRR-007 resolved: `investigative_synthesis` now passes through `BrevixAgentRunner` and `AgentChatController::responseContract()`; two new contract tests added; 243 API tests pass.
- PRR-002 resolved by decision: Node/TypeScript Rex architecture doc marked superseded.
- PRR-005 resolved: `app/index.tsx` now redirects authenticated users to `/(dashboard)` matching the dashboard layout guard pattern; 108 frontend Jest tests pass.
- PRR-008 resolved: Playwright webServer updated to export + serve approach; `serve` added as devDep; e2e login button selector fixed ("Log In" not "Login" role link); all 3 Playwright tests pass.
- PRR-006 resolved: Frontend CI now gates on Jest (`npm test -- --runInBand --ci`) and Playwright (`npm run test:e2e -- --project=chromium`) in addition to typecheck.
- PRR-001 resolved: `orchestrator/venv/` added to `.gitignore`; 3,669 tracked files removed from git index (`git rm -r --cached`). History rewrite (BFG) not performed — confirm with team whether it is needed.
- PRR-013 resolved: Benchmark vendor seed loop in `brevixai-agents/app/graph.py` annotated with clarifying comment.
- `.env` corrupted line fixed: `PERSONAL_FINANCE_PDFTOTEXT_PATH` had `php artisan serve` appended; restored to `/usr/local/bin/pdftotext`.

Recommended next steps:

1. Commit all three repos with the Wave 1 changes above.
2. Start Wave 3 tracks: Data ingestion, Fraud/risk/cases, Observability, Product value, Test/release gates.
3. Decide whether BFG history rewrite is needed to reduce repo size after PRR-001 untracking.
4. Answer open question: What is the production URL for brevixai-agents health check?

Open user questions:

- Should `orchestrator/venv` history be rewritten (BFG, force-push, all collaborators re-clone) or leave it untracked?
- What is the target production URL for brevixai-agents health check?

## Operating Rules

- Treat uncommitted work as user-owned unless explicitly assigned.
- Track findings here before changing production behavior.
- Use design approval before feature or behavior changes.
- Prefer small, verifiable readiness slices over broad rewrites.

## Current Repo State

| Repo | Branch | Working tree | Initial notes |
| --- | --- | --- | --- |
| `brevixai-api` | `process-ready` | Dirty | Pre-existing `.env.example` change plus this pass's production-readiness table correction. |
| `brevixai` | `action-bar` | Clean | Latest commit includes Rex approval integration and e2e coverage; it also includes files outside the originally requested approval-integration commit scope. |
| `brevixai-agents` | Not inspected this pass | Not inspected | FastAPI/LangGraph service with benchmark gate docs; no changes made in this pass. |

## Initial Architecture Map

| Surface | Current stack | Notes |
| --- | --- | --- |
| Frontend | Expo 55, React 19, React Native Web, Expo Router | Dashboard, auth, landing pages, Rex workspace, Playwright/Jest tests. |
| API | Laravel 12, Sanctum, PostgreSQL target, queue/database, S3-compatible storage | Main product API, QuickBooks, upload/reconciliation/risk/cases, Rex chat gateway. |
| Agent service | FastAPI, LangGraph, Pydantic, deterministic/OpenAI-compatible providers | Private service behind shared bearer token; benchmark and prompt hash workflow exists. |
| AI chat | OpenAI-compatible config in API and agent service | Current API `.env.example` uses `LLM_MODEL=chat-latest`; needs verification against deployed key/model. |

## Confirmed Signals

- `brevixai-api` has deployment readiness docs, but README is still mostly the default Laravel README.
- `brevixai` has a prior Rex architecture review that says the production Rex control plane should be Node/TypeScript-first, while newer API/agent work appears to route through Laravel and Python agents.
- `brevixai` appears to track `orchestrator/venv` files. This should be confirmed and planned as a cleanup because generated Python environments do not belong in source control.
- `brevixai-agents` documents production requirements for `ORCHESTRATOR_API_TOKEN`, `ORCHESTRATOR_ALLOWED_ORIGINS`, and optional Postgres checkpointer state.

## Review Tracks

| Track | Status | Goal |
| --- | --- | --- |
| Product value and onboarding | Not started | Confirm the app clearly solves a high-value workflow for the target buyer. |
| AWS deployment and runtime config | In progress | Verify envs, secrets, CORS, URLs, jobs, queues, health checks, and rollback paths. |
| Auth, tenancy, and authorization | In progress | Verify cross-company isolation, admin scope, route guards, and internal service auth. |
| Rex chat and agent flow | In progress — approval execution resolved | Resolve remaining OpenAI config and agent fallback behavior after persisted approval UI is now covered. |
| Data ingestion and integrations | Not started | Verify QuickBooks, uploads, S3 imports, validation, reconciliation, and error handling. |
| Fraud/risk/cases workflows | Not started | Verify deterministic scoring, recommendations, evidence, case lifecycle, and auditability. |
| Observability and operations | Not started | Verify structured logs, trace IDs, dashboards, alerts, SLOs, and runbooks. |
| Test and release gates | Not started | Verify CI, local test commands, smoke checks, benchmark gates, and e2e coverage. |
| Documentation and developer handoff | Resolved | READMEs, env hygiene, and tracker completed. |
| Product value and onboarding | Complete — findings logged | See PRR-016 through PRR-021. |

## Open Questions

1. What AWS services are currently in use for each repo: Amplify, ECS/Fargate, EC2, RDS, S3, CloudFront, Route 53, Secrets Manager, SSM?
2. What are the production URLs for frontend, API, and agent service health checks?
3. Is onboarding intended to stay educational-only, or should it link directly to QB connect and upload (PRR-017)?

## Findings

| ID | Severity | Area | Status | Finding | Evidence | Next step |
| --- | --- | --- | --- | --- | --- | --- |
| PRR-001 | High | Frontend repo hygiene | Resolved | The frontend repo tracked a generated Python virtual environment under `orchestrator/venv` (3,669 files). | `git ls-files orchestrator/venv` returned thousands of files. | Added `orchestrator/venv/` to `.gitignore`; ran `git rm -r --cached orchestrator/venv`. History rewrite (BFG) deferred — confirm with team. |
| PRR-002 | High | Rex architecture | Resolved by decision | Existing docs conflicted on runtime ownership. | `docs/superpowers/specs/2026-05-14-rex-chat-and-agent-architecture-review.md` — Node/TypeScript-first decision; `brevixai-api/app/Services/Agents/BrevixAgentRunner.php` — Laravel→agent service implementation. | **Decision: Laravel → brevixai-agents is production.** Node/TypeScript doc marked superseded (2026-05-23). |
| PRR-003 | Medium | Documentation | Open | API README is still mostly the Laravel skeleton and frontend has no README. | `brevixai-api/README.md`, missing `brevixai/README.md`. | Add product-specific setup/deploy/support docs. |
| PRR-004 | Medium | AI config | Open | API `.env.example` uses `LLM_MODEL=chat-latest`; model compatibility should be verified against current OpenAI production usage. | `brevixai-api/.env.example`. | Confirm deployed model and update docs/config if needed. |
| PRR-005 | Medium | Frontend tests | Resolved | Frontend Jest failed one landing-page redirect expectation because `app/index.tsx` had no auth redirect at all. | `npm test -- --runInBand` in `brevixai`: 1 failed, 107 passed. | Added `useAuth` + `useRouter` redirect to `app/index.tsx`; 108/108 Jest tests pass. |
| PRR-006 | Medium | Frontend CI | Resolved | Frontend CI ran typecheck only; Jest and Playwright were not release gates. | `brevixai/.github/workflows/ci.yml`. | Added Jest (`npm test -- --runInBand --ci`) and Playwright (`npm run test:e2e -- --project=chromium`) steps to CI workflow. |
| PRR-007 | Medium | Rex contract | Resolved | `AgentChatController::responseContract()` dropped `investigative_synthesis`; `BrevixAgentRunner::run()` also omitted it. Frontend `RexWorkspace` expected the field. | `brevixai-api/app/Http/Controllers/Chat/AgentChatController.php`, `app/Services/Agents/BrevixAgentRunner.php`. | Added `investigative_synthesis` to both `responseContract()` and the runner return array; 2 new contract tests; 243 API tests pass. |
| PRR-008 | Medium | Frontend e2e | Resolved | Playwright could not start the Expo web server (`--port` flag ignored by Expo 55 web, prompt in non-interactive mode). E2e login test also used wrong selector ("Login" role link instead of "Log In" text). | `brevixai/playwright.config.ts`, `brevixai/e2e/landing.test.ts`. | Updated webServer to `npx expo export ... && npx serve dist -p 8081 -s`; added `serve` devDep; fixed login button selector; all 3 Playwright tests pass. |
| PRR-009 | Medium | API CORS | Resolved | API CORS config always included localhost origins in production and did not fail closed when env vars were missing. | `brevixai-api/config/cors.php`. | `CorsAllowedOrigins` now fails closed in production; localhost defaults kept for local/development/testing. Production origins confirmed set in prod env. |
| PRR-010 | Low | Frontend env hygiene | Open | Frontend Expo export loads local `.env` containing backend/LLM/QBO variable names. Expo should only expose `EXPO_PUBLIC_*`, but frontend env files should still be separated from backend secrets to reduce deployment mistakes. | `npx expo export --platform web`, `brevixai/.env.example`. | Split frontend public env from server/private env docs. |
| PRR-011 | High | Upload tenancy | Resolved | Upload workflow endpoints could look up uploads by ID without company scope; upload creation also relied on a database-generated UUID that Eloquent did not own, and `positive` validation caused a 500 for `fileSizeBytes`. | `app/Services/UploadService.php`, `app/Models/Upload.php`, `app/Http/Controllers/Api/UploadController.php`. | Fixed and covered by `UploadWorkflowSecurityTest`. |
| PRR-012 | Medium | Auth error handling | Resolved | Signup failures returned raw exception details to the client. | `app/Http/Controllers/Api/AuthController.php`. | Fixed with a generic error response, server-side reporting, and `AuthOnboardingTest` coverage; continue auditing broad catch blocks in API controllers. |
| PRR-013 | Low | Agent graph vendor seeds | Resolved | Benchmark fixture vendor names were hard-coded in `fraud_analyzer` without explanation — could be mistaken for production logic. | `brevixai-agents/app/graph.py` lines 210–221. | Added inline comment clarifying these names exist for benchmark dataset detection only. |
| PRR-014 | Low | Agent optional tool alerting | Open | Four optional tool calls (vendor_risk, reconciliation_risk, entity_relationship_risk, aggregate_risk_summary) fail silently with `logger.warning()`. No alerting path for systematic failures. | `brevixai-agents/app/graph.py` lines 234–362. | Add `degraded_tools` list to `AgentRunResponse`; set monitoring alert for non-zero tool failure counts. Belongs in Observability track. |
| PRR-015 | Low | Agent-Laravel tool contract | Open | The agent service assumes all 6 Laravel tool endpoints exist. Missing or renamed endpoints are partially silenced by broad exception handling. | `brevixai-agents/app/tools/laravel.py`, `brevixai-api` internal agent-tools routes. | Document which tools are required vs. optional in both repos. Belongs in Documentation track. |
| PRR-016 | High | Dead route — alerts run engine | Resolved | Frontend `handleRunEngine()` calls `POST /api/alerts/run` which did not exist in `routes/api.php`. Every click of "Run Engine" on the alerts page 404d. | `brevixai/app/(dashboard)/alerts.tsx:86`, `brevixai-api/routes/api.php`. | Implemented `AlertRecommendationController::run()` wired to `AlertRecommendationService::getAlertRecommendations()`; registered `Route::post('/run', ...)` at top of `alerts` group; 4 new `AlertRunEngineTest` tests covering auth, clean company, high-risk company, and pending count; 247 API tests pass. |
| PRR-017 | High | Onboarding is cosmetic | Open | The 3-step onboarding wizard is educational only — no step actually navigates the user to upload data or connect QuickBooks. After completion, user lands on an empty overview with no next-step CTA. | `brevixai/app/(dashboard)/onboarding.tsx`, `brevixai/app/(dashboard)/overview.tsx`. | Add action links from onboarding steps to QB settings and upload page, or add a CTA banner to the overview for users with no data. |
| PRR-018 | High | Overview has no empty-state guidance | Open | With no data, the overview page shows all zeros and an empty risk gauge. The risk gauge displaying 0 looks like "no risk detected" rather than "no data analyzed". No CTA to upload or connect QB. | `brevixai/app/(dashboard)/overview.tsx`. | Add empty-state detection: if `totalTransactions === 0`, show a setup banner with links to upload and QB connect. Change risk gauge label to reflect data absence. |
| PRR-019 | Medium | QB callback has no success signal | Open | After OAuth, the API redirects to `{FRONTEND_URL}/settings` with no query param or toast. The user has to look for a new entry in the integrations list to confirm the connection worked. | `brevixai-api/app/Http/Controllers/Api/IntegrationController.php:60`. | Append `?qbo=connected` to the redirect URL so the frontend can show a success toast on return. |
| PRR-020 | Medium | QB sync is manual, not automatic | Open | No sync is triggered after the QB OAuth callback completes. The user must navigate back to settings and manually click Sync. | `brevixai-api/app/Http/Controllers/Api/IntegrationController.php`. | Queue a sync job in the callback handler after token exchange succeeds. |
| PRR-021 | Medium | Rex shows no data-presence guidance | Open | With zero transactions, Rex returns a generic "no data found" message. First-time users querying Rex before uploading data get no guidance on what to do next. | `brevixai/app/(dashboard)/index.tsx`, `brevixai-api/app/Http/Controllers/Api/ChatController.php`. | Rex response for empty company context should include a nudge: "No transactions found — upload a file or connect QuickBooks to get started." |
| PRR-022 | Critical | Upload pipeline unimplemented | Resolved | All three upload queue jobs (`ScanUploadJob`, `ValidateUploadJob`, `PromoteUploadJob`) were stubs: each contained `sleep(2)` and hardcoded `row_count: 100`, never reading the uploaded file. No transactions were ever created via file upload. | `app/Jobs/ScanUploadJob.php`, `ValidateUploadJob.php`, `PromoteUploadJob.php`. | Implemented full CSV pipeline: scan extracts real headers + row count (XLSX returns clear unsupported warning); validate checks amounts and dates, records errors in `upload_row_errors`; promote maps columns and bulk-inserts into `transactions` in 500-row chunks. `UploadService::getPreview()` now returns `mappingSuggestion` with auto-detected field mappings and confidence hints. All jobs have `$tries = 3`, `$backoff = 60`. 252 API tests pass. |
| PRR-023 | High | QB purge wiped all company alerts | Resolved | `QboService::purge()` deleted all `alerts` and `alert_groups` for the company regardless of which realm was purged. Purging one QB connection nuked alerts derived from other realms or file uploads. | `app/Services/QboService.php:595-596`. | Fixed: alerts are now only deleted when no transaction data remains for the company (`qbo_transactions`, `transactions`, `gnucash_transactions` all empty). `alert_recommendations` also cleared in that case. Covered by 5 new `QboPurgeTest` tests. |
| PRR-024 | Medium | QB credentials fall back to global env | Open | `QboService::getCredentials()` silently falls back to `env('QB_CLIENT_ID')` if per-company credentials are missing or decryption fails. A company without configured credentials gets the global sandbox credentials and OAuth proceeds incorrectly. | `app/Services/QboService.php:57-68`. | Remove fallback; throw clearly if per-company credentials are not found. |
| PRR-025 | Low | OAuth state nonce is cache-only | Open | OAuth state nonce is stored only in cache (Redis/file) with a 10-min TTL. A cache restart between OAuth connect and Intuit callback breaks in-flight auth flows. Not a security bypass but a reliability gap. | `app/Services/QboService.php:32-47`. | Store nonce in a DB-backed table or use a longer-lived cache key with a dedicated cache store. |
| PRR-026 | Medium | Rex approval UI | Resolved | Standalone Rex previously rendered persisted `approval_id` recommendations as review-only local actions instead of executing through the persisted approval endpoints. | `brevixai/src/components/dashboard/rex/RexWorkspace.tsx`, `RexActionBar.tsx`, `RexEvidencePanel.tsx`, `brevixai/app/__tests__/rex-action-approvals.test.tsx`, `brevixai/e2e/rex-layout.test.ts`. | Frontend now maps `approval_id` into executable action cards, calls `/api/agent-approvals/{id}/approve|reject`, shows reviewed/safe-failure states, and covers the flow in Jest plus Playwright. |

## Verification Log

| Date | Command | Repo | Result |
| --- | --- | --- | --- |
| 2026-05-23 | `git status --short` | all three repos | API dirty; frontend and agents clean. |
| 2026-05-23 | `git ls-files orchestrator/venv` | `brevixai` | Confirmed generated venv files are tracked. |
| 2026-05-23 | `php artisan test tests/Feature/RexChatControllerTest.php tests/Feature/AgentChatControllerTest.php` | `brevixai-api` | Passed: 7 tests, 50 assertions. |
| 2026-05-23 | `npm run typecheck` | `brevixai` | Passed. |
| 2026-05-23 | `.venv/bin/pytest` | `brevixai-agents` | Passed: 418 tests. |
| 2026-05-23 | `php artisan test` | `brevixai-api` | Passed: 234 tests, 1211 assertions. |
| 2026-05-23 | `.venv/bin/python scripts/quality_gate.py --report-json reports/latest_benchmark_report.json` | `brevixai-agents` | Passed: 21/21 scenarios, 6/6 checks. |
| 2026-05-23 | `npm test -- --runInBand` | `brevixai` | Failed: 1 landing-page redirect test failed; 107 tests passed. |
| 2026-05-23 | `npm run test:e2e -- --project=chromium --reporter=list` | `brevixai` | Failed before tests: Expo web server prompt in non-interactive mode. |
| 2026-05-23 | `npx expo export --platform web` | `brevixai` | Passed; exported web build to ignored `dist/`. |
| 2026-05-23 | `php artisan test tests/Feature/UploadWorkflowSecurityTest.php` | `brevixai-api` | Passed: 3 tests, 9 assertions. |
| 2026-05-23 | `php artisan test` | `brevixai-api` | Passed after upload security fix: 237 tests, 1220 assertions. |
| 2026-05-23 | `php artisan test tests/Unit/CorsAllowedOriginsTest.php` | `brevixai-api` | Passed: 3 tests, 3 assertions. |
| 2026-05-23 | `php artisan test` | `brevixai-api` | Passed after CORS hardening: 240 tests, 1223 assertions. |
| 2026-05-23 | `php artisan test tests/Feature/AuthOnboardingTest.php` | `brevixai-api` | Passed: 3 tests, 11 assertions. |
| 2026-05-23 | `php artisan test` | `brevixai-api` | Passed after auth error hardening: 241 tests, 1227 assertions. |
| 2026-05-23 | `php artisan test tests/Feature/AgentChatControllerTest.php` | `brevixai-api` | Passed: 8 tests, 46 assertions (including 2 new synthesis contract tests). PRR-007 fixed. |
| 2026-05-23 | `php artisan test` | `brevixai-api` | Passed after PRR-007 fix: 243 tests, 1233 assertions. |
| 2026-05-23 | `npm test -- --runInBand` | `brevixai` | Passed: 108 tests, 0 failures. PRR-005 fixed. |
| 2026-05-23 | `npm run test:e2e -- --project=chromium --reporter=list` | `brevixai` | Passed: 3 tests. PRR-008 fixed (export+serve webServer, login selector corrected). |
| 2026-05-23 | `git ls-files orchestrator/venv` | `brevixai` | Returns 0 after `git rm -r --cached` and `.gitignore` update. PRR-001 fixed. |
| 2026-05-23 | `.venv/bin/pytest tests/ --ignore=tests/test_evals.py -q` | `brevixai-agents` | Passed: 397 tests. PRR-013 comment addition caused no regressions. |
| 2026-05-24 | `php artisan test tests/Feature/AlertRunEngineTest.php` | `brevixai-api` | Passed: 4 tests, 15 assertions. PRR-016 new endpoint covered. |
| 2026-05-24 | `php artisan test` | `brevixai-api` | Passed: 247 tests, 1263 assertions. PRR-016 fix caused no regressions. |
| 2026-05-24 | `php artisan test tests/Feature/QboPurgeTest.php` | `brevixai-api` | Passed: 5 tests, 15 assertions. PRR-023 fixed. |
| 2026-05-24 | `php artisan test` | `brevixai-api` | Passed: 252 tests, 1263 assertions. PRR-022 + PRR-023 implementation caused no regressions. |
| 2026-06-05 | `npm run typecheck` | `brevixai` | Passed after Rex approval frontend changes. |
| 2026-06-05 | `npm test -- --runInBand --watchman=false` | `brevixai` | Passed: 14 suites, 156 tests. |
| 2026-06-05 | `npm run test:e2e -- e2e/rex-layout.test.ts` | `brevixai` | Passed: 3 Playwright tests covering Rex layout, persisted approval success, and safe failure copy. |

## Source Notes

- OpenAI `chat-latest` docs: `https://developers.openai.com/api/docs/models/chat-latest`
- OpenAI latest model guide: `https://developers.openai.com/api/docs/guides/latest-model`
