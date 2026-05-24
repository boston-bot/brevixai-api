# Brevix AI Production Readiness Review

Started: 2026-05-23

Scope:

- API: `/Users/joe.eagan/Documents/GitHub/brevixai-api`
- Frontend: `/Users/joe.eagan/Documents/GitHub/brevixai`
- Agent service: `/Users/joe.eagan/Documents/GitHub/brevixai-agents`

## Claude Handoff Snapshot

Last updated: 2026-05-23

Current state:

- `brevixai-api` is on `ready-review`, tracking `origin/ready-review`; latest commit at handoff time is `ea059fd`.
- `brevixai-api` has uncommitted Codex changes from this review pass. Do not assume the working tree is clean.
- `brevixai` was clean on `main` at `3825fca` when last checked.
- `brevixai-agents` was clean on `update-git-actions` at `8eaa984` when last checked.

Uncommitted API files at handoff:

- `.env.example`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Support/CorsAllowedOrigins.php`
- `config/cors.php`
- `docs/production-readiness-review.md`
- `tests/Feature/AuthOnboardingTest.php`
- `tests/Unit/CorsAllowedOriginsTest.php`

Completed before this handoff:

- PRR-011 upload workflow tenancy and session persistence were fixed and committed by the user before this snapshot.
- PRR-009 API CORS hardening is implemented but not committed. Production CORS now fails closed unless `APP_FRONTEND_URL` or `CORS_ALLOWED_ORIGINS` is set; local/development/testing keep Expo localhost origins.
- PRR-012 signup exception leakage is implemented but not committed. Signup failures now report server-side and return a generic client error without `details`.
- The tracker has the current findings, verification log, and open questions.

Verification completed:

- `php artisan test tests/Unit/CorsAllowedOriginsTest.php` passed.
- `php artisan test tests/Feature/AuthOnboardingTest.php` passed.
- Full `php artisan test` passed after the latest API changes: 241 tests, 1227 assertions.
- Earlier verification also passed for frontend typecheck, agent pytest, and agent quality gate; frontend Jest and Playwright still have known failures tracked as PRR-005 and PRR-008.

Recommended next steps:

1. Commit the current `brevixai-api` CORS/auth/tracker changes after reviewing the diff.
2. Ask the user for exact AWS production frontend origin(s), then verify production `APP_FRONTEND_URL`/`CORS_ALLOWED_ORIGINS` are set.
3. Resolve PRR-002 before major Rex work: decide whether production Rex is Laravel-to-agent-service, Node/TypeScript orchestration, or a transition state.
4. Fix PRR-007 if Laravel-to-agent-service is the path: pass `investigative_synthesis` through `AgentChatController` and add a contract test.
5. Clean PRR-001 in the frontend repo: remove tracked `orchestrator/venv` from git safely and add ignore coverage.
6. Fix PRR-005 and PRR-008 so frontend Jest and Playwright can become release gates.

Open user questions to ask explicitly:

- What are the exact production frontend origin(s) on AWS?
- What AWS services are hosting each surface: frontend, Laravel API, and agent service?
- Which Rex runtime is intended for production now?
- What first-use customer workflow should be most valuable: QuickBooks connection, upload ingestion, Rex investigation, or fraud/case workflow?

## Operating Rules

- Treat uncommitted work as user-owned unless explicitly assigned.
- Track findings here before changing production behavior.
- Use design approval before feature or behavior changes.
- Prefer small, verifiable readiness slices over broad rewrites.

## Current Repo State

| Repo | Branch | Working tree | Initial notes |
| --- | --- | --- | --- |
| `brevixai-api` | `ready-review` | Dirty | Current uncommitted CORS/auth hardening changes are listed in the Claude handoff snapshot. |
| `brevixai` | `main` | Clean | No README found; Expo/React Native web app. |
| `brevixai-agents` | `update-git-actions` | Clean | FastAPI/LangGraph service with benchmark gate docs. |

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
| Rex chat and agent flow | In progress | Resolve runtime ownership, OpenAI config, agent fallback behavior, persistence, and tests. |
| Data ingestion and integrations | Not started | Verify QuickBooks, uploads, S3 imports, validation, reconciliation, and error handling. |
| Fraud/risk/cases workflows | Not started | Verify deterministic scoring, recommendations, evidence, case lifecycle, and auditability. |
| Observability and operations | Not started | Verify structured logs, trace IDs, dashboards, alerts, SLOs, and runbooks. |
| Test and release gates | Not started | Verify CI, local test commands, smoke checks, benchmark gates, and e2e coverage. |
| Documentation and developer handoff | In progress | Replace skeleton docs with product-specific setup, deployment, and support docs. |

## Open Questions

1. What is the intended production Rex runtime now: Laravel API directly calling OpenAI/agent service, the older Node/TypeScript server, or a transition state?
2. What AWS services are currently in use for each repo: Amplify, ECS/Fargate, EC2, RDS, S3, CloudFront, Route 53, Secrets Manager, SSM?
3. What are the production URLs for frontend, API, and agent service health checks?
4. What is the target customer workflow that must feel most valuable on first use?

## Findings

| ID | Severity | Area | Status | Finding | Evidence | Next step |
| --- | --- | --- | --- | --- | --- | --- |
| PRR-001 | High | Frontend repo hygiene | Open | The frontend repo tracks a generated Python virtual environment under `orchestrator/venv`. | `git ls-files orchestrator/venv` returns thousands of files. | Plan safe removal from git and add ignore coverage. |
| PRR-002 | High | Rex architecture | Open | Existing docs conflict on runtime ownership: frontend docs say Node/TypeScript-first, while API work includes Laravel Rex chat routing and a Python agent runner. | `docs/superpowers/specs/2026-05-14-rex-chat-and-agent-architecture-review.md`, `brevixai-api/app/Services/Agents/BrevixAgentRunner.php`. | Decide current production control plane before hardening. |
| PRR-003 | Medium | Documentation | Open | API README is still mostly the Laravel skeleton and frontend has no README. | `brevixai-api/README.md`, missing `brevixai/README.md`. | Add product-specific setup/deploy/support docs. |
| PRR-004 | Medium | AI config | Open | API `.env.example` uses `LLM_MODEL=chat-latest`; model compatibility should be verified against current OpenAI production usage. | `brevixai-api/.env.example`. | Confirm deployed model and update docs/config if needed. |
| PRR-005 | Medium | Frontend tests | Open | Frontend Jest currently fails one landing-page redirect expectation. | `npm test -- --runInBand` in `brevixai`: 1 failed, 107 passed. | Update the stale test or restore the intended redirect behavior after confirming product intent. |
| PRR-006 | Medium | Frontend CI | Open | Frontend CI runs typecheck only; Jest and Playwright are not release gates. | `brevixai/.github/workflows/ci.yml`. | Add unit/e2e gates once current Jest failure is resolved. |
| PRR-007 | Medium | Rex contract | Open | Frontend can render `investigative_synthesis`, but Laravel `AgentChatController` drops that field from the agent-service response. | `brevixai/src/components/dashboard/rex/RexWorkspace.tsx`, `brevixai-api/app/Http/Controllers/Chat/AgentChatController.php`. | Include synthesis in the API contract and add a contract test. |
| PRR-008 | Medium | Frontend e2e | Open | Playwright cannot start the configured Expo web server because the config uses `npx expo start --web --port 8081`, but Expo 55 reports `--port` does not apply to web and prompts in non-interactive mode. | `brevixai/playwright.config.ts`, `npx expo start --help`, `npm run test:e2e -- --project=chromium --reporter=list`. | Update e2e webServer startup to a deterministic web port and add CI gate. |
| PRR-009 | Medium | API CORS | Resolved in code | API CORS config always included localhost origins, including production, and did not fail closed when `APP_FRONTEND_URL`/`CORS_ALLOWED_ORIGINS` were missing. | `brevixai-api/config/cors.php`. | `CorsAllowedOrigins` now fails closed in production and keeps localhost defaults only for local/development/testing; verify exact AWS frontend origins are set in production env. |
| PRR-010 | Low | Frontend env hygiene | Open | Frontend Expo export loads local `.env` containing backend/LLM/QBO variable names. Expo should only expose `EXPO_PUBLIC_*`, but frontend env files should still be separated from backend secrets to reduce deployment mistakes. | `npx expo export --platform web`, `brevixai/.env.example`. | Split frontend public env from server/private env docs. |
| PRR-011 | High | Upload tenancy | Resolved | Upload workflow endpoints could look up uploads by ID without company scope; upload creation also relied on a database-generated UUID that Eloquent did not own, and `positive` validation caused a 500 for `fileSizeBytes`. | `app/Services/UploadService.php`, `app/Models/Upload.php`, `app/Http/Controllers/Api/UploadController.php`. | Fixed and covered by `UploadWorkflowSecurityTest`. |
| PRR-012 | Medium | Auth error handling | Resolved | Signup failures returned raw exception details to the client. | `app/Http/Controllers/Api/AuthController.php`. | Fixed with a generic error response, server-side reporting, and `AuthOnboardingTest` coverage; continue auditing broad catch blocks in API controllers. |

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

## Source Notes

- OpenAI `chat-latest` docs: `https://developers.openai.com/api/docs/models/chat-latest`
- OpenAI latest model guide: `https://developers.openai.com/api/docs/guides/latest-model`
