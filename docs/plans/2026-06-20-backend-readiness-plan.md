# Brevix API Readiness Plan

**Repository:** `brevixai-api`  
**Review date:** 2026-06-20  
**Status:** Planning only — no implementation work authorized by this plan  
**Purpose:** Establish whether the Laravel backend is ready to support a coherent Brevix product, then provide ordered, verifiable work slices for this repository and its dependencies.

## Executive conclusion

The backend is a substantial, well-tested foundation for a controlled financial-investigation product. Laravel is the durable system of record; it owns identity, tenancy, financial data, deterministic risk processing, evidence, investigations, and approval-gated mutations. The Expo app is a client of Laravel, and `brevixai-agents` is an internal orchestration dependency rather than a system of record.

It is **not ready to be called launch-ready**. Three release blockers are verified:

1. The pre-existing dirty `.env.example` has non-empty S3 access-key and secret-key entries. Do not publish, commit, or copy it. The owner must determine whether these are live credentials and rotate/revoke them if so.
2. The full Laravel suite does not complete under its configured 128 MB memory limit. `php artisan test --compact` exhausted memory while loading `routes/api.php`; passing tests before that point do not constitute a passing release gate.
3. The product direction is now decided, but its implementation contract, release criteria, and advisor-role controls still need to be specified and verified.

No agent should add product features, migrate data, or widen agent permissions until the Phase 0 decisions and release blockers are closed.

## Evidence-based architecture map

```text
brevixai (Expo frontend)
  -> Laravel API (this repository; source of truth)
       -> PostgreSQL / storage / QBO / Stripe / queue / scheduler
       -> brevixai-agents (private FastAPI/LangGraph orchestration)
            -> Laravel internal agent-tool routes only
```

### Confirmed responsibilities

| Area | Current owner | Evidence |
| --- | --- | --- |
| Authentication, workspace/business-profile access, durable records | Laravel | `app/Services/BusinessProfileContextService.php`, `routes/api.php` |
| Deterministic facts and mutations | Laravel | risk modules, findings, investigations, reports, agent approval execution |
| Agent orchestration and explanation | `brevixai-agents`, constrained by Laravel tools | `app/Services/Agents/BrevixAgentRunner.php`, `routes/api.php` internal-tool group |
| User workflow and presentation | `brevixai` | backend is its only declared API dependency |
| Sensitive actions | Human approval via Laravel | `AgentActionExecutorService`, `AgentApprovalController` |

### What this backend currently supports

- Workspace and business-profile tenancy; Sanctum authentication; subscriptions and site content.
- QuickBooks and GnuCash integration, CSV upload/import, transaction and reconciliation workflows.
- Deterministic vendor, reconciliation, entity-relationship, behavioral-baseline, and aggregate risk scoring.
- Recommendations, findings, investigation records, evidence, review notes/events, reports, and packages.
- Rex session chat and standalone agent chat, with persisted run records, steps, and approval-backed actions.
- IRS notice/IRM workflows and a separate personal-finance/admin-local feature area.

This is breadth, not proof of one coherent launch workflow. The plan below deliberately narrows readiness to a chosen end-to-end vertical.

## Product direction: decision record

### Approved product strategy

The product owner approved the **CPA Investigation Workspace** built as an **Evidence Builder** on 2026-06-20. Fraud and tax/IRS work are investigation categories rather than product identities. The source design remains `docs/superpowers/2026-06-12-investigation-platform-redesign.md`; its document status should be updated in a later documentation-only slice.

The target audience is both business owners and CPAs/advisors. This is not permission to create one generic user experience:

- A business owner needs a plain-language answer to *what requires attention, why, and what to review next*.
- A CPA/advisor needs inspectable evidence, limitations, reviewer control, and a defensible handoff/export.

The first release must state whether an advisor works **inside one client workspace as an invited reviewer** or operates a **multi-client portfolio workspace**. The latter is a separate tenancy/product capability and must not be implied by copy or navigation before it exists.

### Technically approved migration direction

The June 14 canonical designs establish `Investigation`, `Finding`, `EvidenceItem`, `SuggestedRecord`, `ReviewerNote`, `ReviewEvent`, and `CasePackage` as the redesign path. Legacy alerts and cases remain compatibility surfaces during migration. See:

- `docs/superpowers/specs/2026-06-14-canonical-finding-cutover-design.md`
- `docs/superpowers/specs/2026-06-14-investigation-api-contract-stabilization-design.md`

### Recorded and remaining decisions

1. **Confirmed:** CPA Investigation Workspace / Evidence Builder is the product direction.
2. **Confirmed:** business owners and CPAs/advisors are the target audiences, with separate jobs-to-be-done.
3. **Confirmed:** use **Evidence-backed Vendor & Payment Review** as the first end-to-end vertical. The first release supports a CPA/advisor as an invited reviewer in one client workspace; a multi-client portfolio workspace is deferred.
4. **Confirmed:** treat the uncommitted `brevixai-agents` work as the intended baseline; classify and test it, rather than excluding it as experimental.
5. **Still required:** decide which roles may execute approval-backed actions; the current endpoint validates membership/profile access but does not require owner/admin role.
6. **Still required:** decide whether the product promises CSV-only evidence ingestion at launch, or requires supported XLSX ingestion before launch.

### Confirmed first vertical: Evidence-backed Vendor & Payment Review

This is the recommended trust-building wedge because it has the best convergence of customer value, existing implementation, and safe claims:

- Both audiences understand the problem immediately: unusual vendor/payments, duplicate payments, concentration, and relationship indicators need review.
- The backend already supports transaction ingestion, deterministic vendor and relationship risk scoring, evidence, findings, reviewer actions, investigations, reports, and approval-gated action.
- Every claim can be anchored to a source record and rule. The product can say **“review this signal”**, not **“fraud occurred”** or **“this is professional advice.”**
- It creates an early, tangible owner outcome and a CPA/advisor outcome without needing the product to complete a tax filing, give legal advice, or operate as a full multi-client practice-management system.

The initial flow should be deliberately narrow:

```text
Connect QBO or import a supported ledger
  -> show data coverage, freshness, and known limitations
  -> produce bounded vendor/payment findings with cited source records
  -> owner or advisor reviews, dismisses, requests evidence, or opens an investigation
  -> preserve the review trail and export a package when needed
```

Trust is earned by product behavior, not an AI disclaimer. For every finding, show the source period/coverage, specific evidence, deterministic reason code, uncertainty/limitations, and the human who made the final review decision. NIST identifies reliability, transparency, explainability, privacy, and accountable human oversight as mutually supporting characteristics of trustworthy AI; its GenAI profile also calls out the risk of confidently presented false content in consequential contexts. [NIST AI RMF](https://airc.nist.gov/airmf-resources/airmf/3-sec-characteristics/) and [NIST AI 600-1](https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-generative-artificial-intelligence) support this approach. Product performance claims must be backed by competent, reliable evidence at the time they are made, not inferred from isolated demos or agent outputs. [FTC guidance on AI claims](https://search.ftc.gov/news-events/news/press-releases/2025/04/ftc-order-requires-workado-back-artificial-intelligence-detection-claims)

Why the alternatives are not the first vertical:

| Alternative | Role in the roadmap | Why not first |
| --- | --- | --- |
| Tax notices | High-value category after the core evidence/review loop is trusted | Urgent but highly sensitive; it can be mistaken for tax or legal advice before the product has earned trust in data provenance and limitations. |
| Reconciliation | A strong second reliability workflow and a source of evidence for investigations | It proves operational correctness but is less differentiated as the commercial opening and depends on very clean source matching. |
| Advisor-client review | The delivery model for the two-sided experience | It is not a vertical on its own; it depends on a trusted finding/evidence/review workflow and an explicit multi-client tenancy decision. |

## Readiness assessment

| Dimension | Status | Evidence and consequence |
| --- | --- | --- |
| Product contract | Directed | Product direction, audiences, first vertical, and first-release advisor model are approved; the implementation contract and release criteria remain to be specified. |
| System-of-record architecture | Strong foundation | Laravel owns auth, tenancy, facts, evidence, and writes; agent service has no direct database path. |
| Cross-repository contracts | At risk | The agent guided-intake workflow calls Laravel tools that are not registered; legacy and canonical investigation surfaces coexist. |
| Test/release gate | Blocked | Full test suite exhausts 128 MB. CI runs `php artisan test`, so it cannot currently establish a green release gate. |
| Credential hygiene | Blocked | Dirty `.env.example` contains non-empty S3 credential fields. |
| Data ingestion | Partial | CSV inspection/validation/promotion is implemented. Public upload acceptance includes XLSX, but all processing jobs explicitly reject XLSX. |
| External integrations | Partial | QBO credentials are encrypted and Stripe signatures are checked; QBO sync is synchronous, OAuth state is cache-backed, and webhook idempotency is not evidenced. |
| Agent safety | Good baseline, not ready for expansion | Internal tool authorization, tenant checks, persisted approvals, and no direct DB access are in place. Tool capability/action contracts drift across repositories. |
| Operations | Partial | Queue retries, scheduled commands, smoke check, and a GitHub Actions test/deploy workflow exist. The deployment checklist is stale and the repository lacks versioned worker/process/IaC configuration. |
| UX enablement | Partial | Backend exposes onboarding, evidence, findings, reports, and actions, but high-value no-data/onboarding flows and a single primary workflow remain frontend/product decisions. |

## Sequenced backend plan

### Phase 0 — secure and establish an auditable baseline

**Goal:** Make the source and test baseline trustworthy before interpreting readiness.

1. Credential owner: determine whether the S3 values in `.env.example` are live; rotate/revoke if they are. Replace them with non-sensitive placeholders and inspect Git history using an approved secret-response procedure.
2. Repository owner: classify the existing dirty files as intentional work, experiments, or discardable local state. Do not merge unclassified files into readiness work.
3. Backend test agent: reproduce and profile the suite memory failure, identify the first expensive test/route registration path, and set a justified CI memory policy or remove the leak.
4. Release agent: update CI only after the suite completes reliably; retain focused contract tests as fast gates rather than replacing the full suite.

**Exit criteria:** no live credentials in source or working examples; cleanly classified baseline; full suite exits successfully in the same environment CI uses; reproduction notes and command are recorded.

### Phase 1 — ratify the two-sided workflow and launch vertical

**Goal:** Stop new work from reinforcing conflicting products.

1. Create a short, versioned product-contract document shared by all repositories: owner and advisor jobs-to-be-done, Vendor & Payment Review, invited-reviewer scope, supported inputs, permitted claims, explicit non-goals, and first-value moment.
2. Define the deferred multi-client portfolio capability as a future tenancy decision rather than allowing it to emerge through frontend navigation or ad hoc role checks.
3. Map every active backend surface into one of: launch-critical, supporting, compatibility-only, experimental, or retired candidate. Personal finance, fraud testing, IRS workflows, legacy alerts/cases, and canonical investigations must be classified explicitly.
4. Define release success as an observable user outcome for the chosen vertical — not number of routes or agent capabilities.

**Exit criteria:** one approved two-sided product contract; one first vertical; every active top-level backend surface has a disposition; companion repositories use the same vocabulary.

### Phase 2 — stabilize the canonical investigation module

**Goal:** Make the selected vertical travel predictably from data to a reviewer decision.

1. Treat `Investigation`, `Finding`, `EvidenceItem`, `SuggestedRecord`, `ReviewerNote`, `ReviewEvent`, and `CasePackage` as the canonical module for the redesign path.
2. Define a migration ledger for legacy `Alert`, `AuditCase`, recommendation, and legacy investigation responses: owner, callers, compatibility duration, cutover condition, and removal test.
3. Add a contract gate that proves the chosen source produces an idempotent finding, preserves sanitized evidence, opens an investigation, accepts a reviewer action, and produces the intended report/package output.
4. Do not expand the package builder until the finding/evidence/reviewer contract is stable, consistent with the approved June 14 sequencing.

**Exit criteria:** the chosen vertical has one canonical happy path; compatibility behavior is explicit and tested; frontend and agent consumers no longer need source-specific finding shapes for that path.

### Phase 3 — make Laravel-to-agent contracts executable and observable

**Goal:** Retain the agent as a controlled explanation/orchestration layer.

1. Define the contract in one versioned artifact with request, response, event, tool capability, degraded-tool, action, and approval semantics. Generate or validate both Laravel and Python representations from it.
2. Resolve guided-intake drift before enabling it: the agent currently calls `onboarding-context`, `evidence-requirements`, `data-source-status`, and `first-snapshot`, none of which is in Laravel's registered internal tool set.
3. Reconcile advertised action types with executable action types and decide whether discovery workflows are information-only or use the normal action/approval path.
4. Require contract tests on both sides, including tenant/profile propagation, a partial-tool-degradation response, and a rejected/approved action.
5. Define trace/alert ownership for unavailable or degraded tools; do not silently degrade a user’s investigative result.

**Exit criteria:** every enabled agent intent has a registered, tested Laravel tool set; required versus optional tools are explicit; the frontend can explain partial results; no agent path can bypass Laravel authorization or human approval.

### Phase 4 — close ingestion and external-integration reliability gaps

**Goal:** Ensure evidence can reach the chosen vertical safely and predictably.

1. Align accepted upload types, UI copy, documentation, and actual processing. Either support XLSX end-to-end with validation tests or reject it before upload.
2. Decide whether `scan_status=clean` is a parsing state or a security promise. If malware scanning is promised, introduce a genuine scanning adapter and failure policy.
3. Move long-running QBO synchronization out of the request path; define idempotency, retry, source lineage, and stale-sync visibility.
4. Remove global QBO credential fallback; decide on durable OAuth-state storage and callback success behavior.
5. Add a Stripe event ledger/idempotency strategy before relying on webhook-driven subscription state.

**Exit criteria:** supported source types are truthful end-to-end; integration retries and duplicate delivery are safe; failures reach an operator and the user sees a truthful state.

### Phase 5 — make authorization and tenancy a releaseable security property

**Goal:** Prove that the selected persona can see and do only what it should.

1. Define a role/action matrix for every mutation in the chosen vertical, especially recommendation review, evidence changes, package/report generation, and approval execution.
2. Complete workspace/profile propagation through controllers that still rely directly on `user()->company_id` where selected-workspace behavior matters.
3. Add adversarial contract tests for cross-workspace, cross-profile, stale-token, and agent-header spoofing cases.
4. Decide whether application-enforced tenancy is adequate for the launch risk profile or whether database-level controls are needed.

**Exit criteria:** the matrix is approved, enforced, and tested; no selected-workspace action falls back to an unintended default company; internal agent calls cannot widen a user’s scope.

### Phase 6 — release operations and user-experience handoff

**Goal:** Make a production deployment and the first user journey observable.

1. Update the deployment runbook from the actual migration count, required services, queue worker/scheduler configuration, secrets, health endpoints, backup/rollback, and smoke-check scope.
2. Version the deployment/process configuration or document its authoritative external owner; do not rely on untracked server state.
3. Add metrics and alerts for queue failures, import failures, QBO sync, agent/tool failure, approval execution, authorization failures, and the chosen first-value event.
4. Hand the frontend repository a stable contract plus data-state vocabulary: no data, data pending, partial evidence, finding generated, review required, action approved, and export ready.
5. Verify the full journey in a production-like environment with a synthetic tenant and a redacted, representative input set.

**Exit criteria:** reproducible deploy, live operational signals, tested rollback, and an end-to-end first-value journey for the chosen vertical.

## Candidate deepening opportunities

These are candidates, not implementation instructions. They use the architecture vocabulary deliberately; no new interface should be designed until the product contract is approved.

1. **Canonical investigation module**
   - **Files:** `InvestigationPlatformService`, `InvestigationService`, `InvestigationPlatformContractService`, `CaseService`, `FindingService`, and `InvestigationController`.
   - **Problem:** canonical and legacy flows share endpoints and compatibility fallbacks, so callers must understand table availability and multiple record vocabularies.
   - **Solution to explore:** make the canonical investigation module the external seam for the approved vertical, with legacy adapters contained behind it.
   - **Benefits:** higher locality for lifecycle changes and more leverage for frontend, agent, and test callers. Deletion test: removing the compatibility layer today would reintroduce migration logic across callers, so it is earning its keep; the concern is that its current interface leaks too much migration state.

2. **Agent-process contract module**
   - **Files:** `AgentToolRegistry`, `AgentToolController`, `BrevixAgentRunner`, agent-side `app/tools/laravel.py`, and agent graph definitions.
   - **Problem:** tool routes, required/optional status, action types, and degraded-tool payloads are duplicated or drift across adapters.
   - **Solution to explore:** establish one versioned contract module at the Laravel/agent seam, with parity and negative-path tests.
   - **Benefits:** high leverage across every agent workflow; failures and compatibility are localized rather than discovered through user chat.

3. **Rex interaction module**
   - **Files:** `ChatController`, `ChatService`, `RexChatRouterService`, `AgentChatController`, `BrevixAgentRunner`, and the frontend Rex consumers.
   - **Problem:** session chat and standalone Rex use different paths, state models, and action lifecycles.
   - **Solution to explore:** decide whether both are enduring workflows; if not, converge the product-visible workflow behind one external seam.
   - **Benefits:** a smaller interface for the frontend and a single test surface for streaming, degraded results, approval, audit trail, and recovery.

4. **Evidence-ingestion module**
   - **Files:** `UploadService`, `UploadStorageService`, `ScanUploadJob`, `ValidateUploadJob`, `PromoteUploadJob`, and QBO ingestion.
   - **Problem:** source-format promises and lifecycle semantics are spread across user routes, jobs, and integration paths.
   - **Solution to explore:** define the supported-source lifecycle as one deep module once launch inputs are chosen; do not create hypothetical adapters for formats that are not in launch scope.
   - **Benefits:** better locality for security, retry, provenance, validation, and truthful UX status.

## Companion-repository plan dependencies

This review is Phase 1 of a three-repository program. Each later plan must be created in its own repository after its baseline is classified.

| Repository | Planned focus | Blocking dependency from this review |
| --- | --- | --- |
| `brevixai` | [Frontend readiness plan](../../brevixai/docs/plans/2026-06-20-frontend-readiness-plan.md): first-value UX, information architecture, canonical client contracts, data-state design, and release/e2e gates | Needs the approved persona/vertical and stable Laravel contract before visual or navigation work is treated as complete. |
| `brevixai-agents` | [Agent-service readiness plan](../../brevixai-agents/docs/plans/2026-06-20-agent-service-readiness-plan.md): intent scope, tool-capability contract, evaluations, prompt/claim safety, orchestration reliability, and observability | Its working tree is the declared baseline; guided-intake tools, selected-profile propagation, canonical finding persistence, and action semantics must be reconciled with Laravel before workflows are enabled. |
| `brevixai-api` | This plan | Security baseline, test gate, canonical contract, integration reliability, and authorization must precede feature expansion. |

## Agent coordination rules

1. One agent owns one plan slice and may not alter another repository's contract without an approved cross-repository decision record.
2. Contract changes are implemented Laravel-first, then consumed by frontend and agents; no consumer invents missing server behavior.
3. Every completed slice records: owner, source-of-truth contract, test/evaluation command, expected data state, rollback path, and affected repository plans.
4. No agent uses a model to invent financial facts, perform hidden writes, or bypass the Laravel approval process.
5. Dirty worktree files are never silently incorporated into a plan or implementation baseline.

## Companion readiness artifacts

The companion plans are now created and linked above. All three plans use the selected product direction, first vertical, and single-client invited-reviewer scope. Their first implementation slices must agree on a versioned Laravel contract and a real cross-repository release gate before feature agents begin work.
