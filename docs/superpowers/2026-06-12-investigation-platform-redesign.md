# Investigation Platform Redesign

Date: 2026-06-12
Status: Draft for product review
Scope: Large redesign of Brevix positioning, navigation, investigation model, and cross-module contracts

## Summary

Brevix should be redesigned around a single investigation platform model:

> Brevix helps CPAs and business owners investigate business health issues by turning financial records into findings, evidence, reviewer notes, and exportable case packages.

The recommended direction combines the user's three proposed directions:

- **Commercial wrapper:** CPA Investigation Workspace
- **Product promise:** Business Health Investigations
- **Architecture core:** Evidence Builder

Fraud and IRS/tax workflows should remain important, but they should become investigation categories rather than the dominant product identity. This creates a safer, broader, and more commercially viable platform: users bring records, Brevix identifies reviewable issues, organizes supporting evidence, suggests missing records, captures reviewer judgment, and exports a case package.

## Decision

Use **Direction 2: CPA Investigation Workspace** as the go-to-market wrapper, powered by **Direction 3: Evidence Builder** as the product architecture, with **Direction 1: Business Health Investigations** as the category taxonomy.

In plain terms:

- Sell it as a CPA Investigation Workspace.
- Build it as an Evidence Builder.
- Organize it around Business Health Investigations.

This avoids positioning Brevix as an IRS expert system or fraud-only platform while preserving both IRS and fraud workflows as valuable categories.

## Product Positioning

### Primary Statement

Brevix turns business records into investigation-ready findings, evidence, reviewer notes, and exportable case packages.

### Safer Secondary Statements

- Upload bank statements, QuickBooks exports or connections, IRS notices, payroll records, and supporting documents.
- Brevix helps identify business health issues across revenue, expenses, payroll, tax, fraud, reconciliation, and controls.
- Findings stay tied to supporting evidence and source records.
- Reviewers can approve, dismiss, comment, request more records, and export the case package.

### Statements To Avoid

- Brevix is an IRS expert system.
- Brevix detects fraud automatically.
- Brevix proves fraud.
- Brevix replaces a CPA, attorney, tax preparer, or forensic accountant.
- Brevix writes back to accounting systems without review.

### Trust Language

The product should consistently distinguish:

- "Issue found" from "risk indicator found"
- "Not enough evidence" from "no issue found"
- "Suggested next record" from "required legal action"
- "Reviewer approved" from "AI decided"
- "Case package" from "legal conclusion"

## Goals

- Make investigations the central product object.
- Demote fraud and IRS/tax from product identity to categories within a broader investigation platform.
- Preserve current strengths: findings, evidence, citations/source references, workflows, reviewer approvals, reports, and exports.
- Give CPAs a repeatable workspace for client issues: IRS notices, bookkeeping issues, payroll concerns, fraud suspicions, cash flow concerns, and reconciliation problems.
- Let business owners answer "What is actually happening in my business?"
- Build a coherent path from uploaded records to findings to evidence to review to export.
- Reduce navigation and conceptual duplication between Cases, Investigations, Alerts, Reports, and advanced tools.
- Create shared frontend contracts that can align with the Laravel API without requiring a full frontend rewrite.

## Non-Goals

- No automatic fraud determination.
- No legal, tax, or accounting advice claim.
- No automatic QuickBooks write-back in the redesign baseline.
- No promise to ingest arbitrary files or understand every accounting export.
- No replacement of the Laravel API as the durable system of record.
- No requirement to remove all existing routes in the first phase.
- No chat-first product model where Rex becomes the center of the app.
- No separate IRS-only or fraud-only product line.

## Current Baseline

The frontend already contains most primitives needed for the redesign.

### Existing Assets

- Primary navigation already frames the core app as a Review Workspace with Action Plan, Rex, Evidence, Findings, Cases, and Reports.
- Action Plan already has canonical intent keys for suspected fraud, tax/IRS, routine books review, reconciliation cleanup, vendor/payment controls, advisor-client review, and unsure.
- Evidence onboarding already builds checklist requirements by intent and measures readiness.
- Transactions already expose investigation metadata: linked alerts, linked cases, explainability, reconciliation state, source completeness, duplicate candidate, and review state.
- Reconciliation already models discrepancy categories, risk levels, recommended actions, statuses, transaction entries, detail events, notes, and confirmation flows.
- Investigations already support a detail drawer with linked alerts, linked recommendations, evidence items, notes, activity, reports, PDF export, report history, and package manifest generation.
- Reports already point users back to investigation workspaces instead of treating reports as global standalone output.
- Tax Notices already parses a notice into notice type, deadline, required action, risk level, amount, summary, and disclaimer.
- Vendor Risk already aggregates alerts into vendor-level exposure and recommended actions.

### Current Friction

- **Cases and Investigations overlap.** Users see both as separate concepts, but both describe formal issue workflows.
- **Findings and Alerts overlap.** Alerts are currently the primary list, while Action Plan uses "findings" language.
- **Tax Notices, Vendor Risk, Reconciliation, Transactions, AR Aging, Entity Graph, Controls, and Analytics are routes rather than category-specific tools inside investigations.**
- **Evidence readiness and investigation evidence are separate experiences.** One helps gather sources, the other attaches evidence to a selected investigation.
- **Finding contracts are shallow and page-specific.** Reconciliation discrepancies, transaction anomalies, tax notice results, vendor risk signals, and Action Plan findings each carry related concepts in different shapes.
- **Reports are conceptually correct but underpowered as the premium product outcome.** The exportable package should become the case deliverable, not just a report panel.

## Recommended Information Architecture

### Primary Navigation

The redesign should move to this primary navigation:

- **Action Plan** - what needs attention now
- **Investigations** - active business health investigations and case work
- **Evidence** - uploads, integrations, documents, readiness, and source coverage
- **Findings** - cross-category issue queue
- **Reports** - generated packages and export history
- **Rex** - investigation assistant and explanation layer
- **Settings** - account, integrations, billing, workspace settings

### Secondary Tools

The following should remain available, but not as primary product categories:

- Transactions
- Reconciliation
- Tax Notices
- Vendor Risk
- AR Aging
- Entity Graph
- Controls
- Analytics
- Alert Reviews
- Case Reviews

They should become investigation tools, category workspaces, or filtered drill-downs from Investigations, Findings, Evidence, and Reports.

### Naming Changes

Recommended user-facing naming:

- "Audit Queue" -> "Findings"
- "Alerts" -> "Findings" or "Risk Signals" depending on context
- "Audit Cases" -> "Investigations"
- "IRS Notice Intelligence" -> "Tax Notice Investigation"
- "Vendor Risk Tracking" -> "Vendor and Payment Findings"
- "Reconciliation Detective" -> "Reconciliation Investigation"
- "Action Plan" can remain, but its language should shift from audit memo to investigation brief where appropriate

## Product Taxonomy

The investigation categories should be broad enough for business owners and precise enough for CPAs.

### Revenue Problems

Examples:

- declining revenue
- unusual revenue trends
- customer concentration
- missing deposits
- revenue timing anomalies
- refund or chargeback spikes

Suggested evidence:

- bank deposits
- invoices
- sales reports
- AR aging
- customer ledger
- payment processor exports

### Expense Problems

Examples:

- duplicate payments
- vendor concentration
- unusual spending
- off-cycle payments
- uncategorized expenses
- missing receipts

Suggested evidence:

- bank and card statements
- transaction ledger
- vendor register
- invoice register
- approval records
- receipts

### Payroll Problems

Examples:

- ghost employees
- overtime spikes
- payroll anomalies
- payroll tax concerns
- unusual contractor payments
- employee/vendor overlap

Suggested evidence:

- payroll register
- employee roster
- timesheets
- contractor list
- payroll tax filings
- bank ACH detail

### Tax Problems

Examples:

- notice explanation
- filing issues
- payroll tax concerns
- unpaid balance questions
- deadline tracking
- period mismatch between notice and records

Suggested evidence:

- IRS or state notice
- prior returns
- payment confirmations
- payroll tax reports
- correspondence
- ledger records for the notice period

### Fraud Problems

Examples:

- suspicious transactions
- shell vendor indicators
- conflict indicators
- unusual payment routing
- repeated round-dollar withdrawals
- vendor/employee relationship concerns

Suggested evidence:

- bank statements
- transaction ledger
- vendor register
- access roster
- approval policy
- check images
- supporting invoices

### Reconciliation Problems

Examples:

- missing from books
- missing from bank
- duplicate suspected
- amount mismatch
- timing differences
- uncleared activity

Suggested evidence:

- bank statements
- accounting export
- prior reconciliation report
- open items
- account list

### Control Problems

Examples:

- signer/access risk
- missing approvals
- policy exceptions
- manual adjustment patterns
- vendor onboarding gaps

Suggested evidence:

- access roster
- approval policy
- bank signer list
- change logs
- accounting system audit log

## Core Product Model

The central object should be **Investigation**.

### Investigation

An investigation represents a scoped review of one business health concern.

Fields:

- `id`
- `workspaceId`
- `clientOrCompanyId`
- `title`
- `category`
- `subcategory`
- `status`
- `priority`
- `reviewPeriod`
- `scopeStatement`
- `scopeLimitations`
- `assignedTo`
- `createdBy`
- `openedAt`
- `lastActivityAt`
- `closedAt`
- `sourceFindingIds`
- `evidenceItemIds`
- `reviewerNoteIds`
- `packageIds`

Statuses:

- `open`
- `in_review`
- `waiting_on_records`
- `pending_reviewer_approval`
- `ready_for_package`
- `closed`
- `archived`

### Finding

A finding is a reviewable issue, risk signal, discrepancy, notice interpretation, or control concern. Findings are not conclusions.

Fields:

- `id`
- `investigationId`
- `category`
- `sourceModule`
- `sourceRecordType`
- `sourceRecordId`
- `title`
- `summary`
- `detail`
- `severity`
- `confidence`
- `reasonCode`
- `status`
- `evidenceRefs`
- `suggestedRecordRefs`
- `recommendedAction`
- `reviewerStatus`
- `createdAt`
- `updatedAt`

Statuses:

- `new`
- `in_review`
- `needs_more_evidence`
- `reviewed`
- `dismissed`
- `escalated`
- `included_in_package`

Severity:

- `info`
- `warning`
- `critical`

Confidence:

- `low`
- `medium`
- `high`

### Evidence Item

An evidence item is any record, source row, document, citation, import batch, transaction, notice, or user-provided note that supports or limits a finding.

Fields:

- `id`
- `investigationId`
- `findingId`
- `evidenceType`
- `sourceType`
- `sourceId`
- `sourceRecordId`
- `title`
- `summary`
- `citationLabel`
- `sourceRowRange`
- `fileName`
- `storageKey`
- `hash`
- `addedByActorType`
- `addedByActorId`
- `createdAt`
- `metadata`

Evidence types:

- `transaction`
- `reconciliation_discrepancy`
- `tax_notice`
- `uploaded_file`
- `source_row`
- `bank_statement`
- `ledger_export`
- `invoice`
- `receipt`
- `payroll_record`
- `reviewer_note`
- `system_summary`

### Suggested Record

A suggested record describes what the reviewer should request next to resolve a limitation.

Fields:

- `id`
- `investigationId`
- `findingId`
- `recordType`
- `label`
- `reason`
- `priority`
- `status`
- `satisfyingEvidenceItemId`

Statuses:

- `requested`
- `received`
- `waived`
- `not_available`

### Reviewer Note

A reviewer note captures human judgment, context, and decision history.

Fields:

- `id`
- `investigationId`
- `findingId`
- `authorId`
- `authorName`
- `body`
- `visibility`
- `createdAt`
- `updatedAt`

### Review Event

A review event captures auditable workflow activity.

Fields:

- `id`
- `investigationId`
- `findingId`
- `eventType`
- `actorType`
- `actorId`
- `previousStatus`
- `nextStatus`
- `note`
- `createdAt`
- `metadata`

### Case Package

A case package is the premium output. It is generated from an investigation, not from a global report page.

Fields:

- `id`
- `investigationId`
- `format`
- `title`
- `generatedAt`
- `generatedBy`
- `includedSections`
- `includedCounts`
- `packageHash`
- `filename`
- `storageKey`
- `manifest`

Included sections:

- scope statement
- limitations
- findings
- supporting evidence
- source citations
- suggested records
- reviewer notes
- activity timeline
- disclaimers
- package manifest

## Core User Workflows

### CPA Client Investigation

1. CPA creates or opens a client workspace.
2. CPA selects an investigation category or starts from "not sure yet."
3. Brevix creates an evidence checklist based on category, review period, and client context.
4. CPA uploads or connects records.
5. Brevix produces findings with evidence and limitations.
6. CPA reviews findings, adds notes, dismisses weak findings, requests more records, or opens a formal investigation.
7. CPA exports a case package when ready.

### Business Owner Health Check

1. Owner starts with "What is actually happening in my business?"
2. Brevix asks for the minimum records needed for a scoped review.
3. Brevix separates missing evidence from actual findings.
4. Owner sees business health categories with issue counts and confidence levels.
5. Owner can ask Rex for plain-English explanations, but findings remain tied to evidence.
6. Owner can invite or hand off to a CPA later.

### Tax Notice Investigation

1. User uploads or pastes a notice.
2. Brevix extracts notice type, period, deadline, amount, and required response category.
3. Brevix creates a tax investigation with suggested supporting records.
4. User attaches returns, payroll reports, payment confirmations, correspondence, or ledger data.
5. Reviewer adds notes and exports a package.

### Fraud Concern Investigation

1. User selects suspected fraud, missing funds, or misuse.
2. Brevix asks for bank records, ledger, vendor register, access roster, approval policy, and supporting documents.
3. Rules produce findings such as duplicate payments, unusual vendor activity, conflict indicators, or suspicious transactions.
4. Findings are labeled as risk indicators, not conclusions.
5. Reviewer can escalate, request records, add notes, and package evidence.

## UX Surface Design

### Action Plan

Action Plan remains the "what should I do next?" surface.

It should show:

- investigation objective
- current category
- readiness score
- open findings count
- missing evidence
- next best action
- recent changes
- reviewer-owned open questions
- Rex prompts tied to the investigation

Action Plan should not be the place where users do deep analysis. It should route into the right investigation, evidence, finding, or package workflow.

### Investigations

Investigations becomes the main work surface.

List view:

- category
- priority
- status
- assigned reviewer
- client/company
- review period
- finding count
- evidence count
- missing record count
- package readiness
- last activity

Detail view:

- summary
- scope and limitations
- findings
- evidence ledger
- suggested records
- reviewer notes
- linked source tools
- activity timeline
- package generation

### Evidence

Evidence becomes source coverage and record intake.

It should show:

- required records by investigation category
- connected sources
- uploads
- document evidence
- validation status
- row-level provenance
- source freshness
- evidence gaps

Evidence should support both global workspace readiness and investigation-specific evidence ledgers.

### Findings

Findings becomes the cross-category queue of reviewable issues.

Filters:

- investigation category
- source module
- severity
- confidence
- review status
- client/company
- review period
- evidence status

Finding detail:

- issue summary
- why flagged
- supporting evidence
- affected transactions/source rows
- suggested records
- reviewer notes
- create/open investigation
- include/exclude from package

### Reports

Reports becomes package history and export management.

It should show:

- packages by investigation
- package format
- generated by
- generated at
- package hash
- included sections
- package status

Reports should not be a separate analysis surface.

### Rex

Rex should be positioned as an investigation assistant.

It can:

- explain findings
- summarize evidence gaps
- draft reviewer notes
- help prepare record requests
- answer questions about source coverage
- generate plain-English summaries from structured findings

It should not:

- create final conclusions
- mark findings reviewed without explicit user action
- generate packages automatically without a reviewer action
- execute risky mutations without approval

## Module Deepening Opportunities

The redesign should deepen several modules instead of adding another shallow layer.

### 1. Finding Module

Files involved:

- `src/services/actionPlanContract.ts`
- `app/(dashboard)/reconciliation.tsx`
- `app/(dashboard)/transactions.tsx`
- `app/(dashboard)/alerts.tsx`
- `app/(dashboard)/tax-notices.tsx`
- `app/(dashboard)/vendor-risk.tsx`

Problem:

Finding-like data exists in multiple page-specific shapes. Each caller must understand source-specific fields, which makes the interface nearly as complex as the implementation.

Solution:

Create a shared Finding module with a small interface and source-specific adapters:

- ReconciliationDiscrepancyAdapter
- TransactionAnomalyAdapter
- TaxNoticeFindingAdapter
- VendorRiskFindingAdapter
- AlertFindingAdapter
- ARAgingFindingAdapter

Leverage:

Callers can render, filter, review, and package findings without knowing source-specific internals.

Locality:

Mapping complexity stays inside adapters instead of spreading across Action Plan, Findings, Investigations, and Reports.

### 2. Investigation Workspace Module

Files involved:

- `app/(dashboard)/cases.tsx`
- `app/(dashboard)/cases/[id].tsx`
- `app/(dashboard)/investigations.tsx`
- `app/(dashboard)/reports.tsx`

Problem:

Cases and Investigations are separate modules with overlapping interfaces: status, evidence, notes, linked alerts, linked transactions, reports, and exports.

Solution:

Make Investigation the canonical module. Treat "case" as a lifecycle state, package mode, or legacy API adapter. Existing case routes can redirect or adapt during migration.

Leverage:

The product has one place for formal review work and one interface for notes, evidence, status, and packages.

Locality:

Workflow and package behavior live in one module instead of two parallel page implementations.

### 3. Evidence Ledger Module

Files involved:

- `app/(dashboard)/evidence.tsx`
- `src/services/onboarding.ts`
- `app/(dashboard)/upload.tsx`
- `app/(dashboard)/investigations.tsx`

Problem:

Evidence readiness, upload state, and investigation evidence are related but managed separately.

Solution:

Create an Evidence Ledger module with adapters for uploads, integrations, source rows, documents, tax notices, transactions, and reviewer-created evidence.

Leverage:

Readiness, findings, investigations, and packages can reference the same evidence interface.

Locality:

Source provenance, labels, validation status, and citation behavior stay in the Evidence Ledger.

### 4. Package Builder Module

Files involved:

- `app/(dashboard)/investigations.tsx`
- `app/(dashboard)/reports.tsx`
- `src/components/action-plan/LeadSheetView.tsx`

Problem:

Report generation exists, but the product value is really an exportable investigation package with evidence, notes, citations, limitations, and manifest.

Solution:

Create a Package Builder module that consumes Investigation, Finding, Evidence Item, Suggested Record, Reviewer Note, and Review Event interfaces.

Leverage:

Any category can produce a consistent package without building category-specific report screens.

Locality:

Export formatting, package hashes, manifest structure, and disclaimer rules live together.

## Frontend Contract Proposal

### InvestigationCategory

```ts
export type InvestigationCategory =
    | 'revenue'
    | 'expense'
    | 'payroll'
    | 'tax'
    | 'fraud'
    | 'reconciliation'
    | 'controls'
    | 'vendor_payments'
    | 'cash_flow'
    | 'unsure';
```

### InvestigationFinding

```ts
export interface InvestigationFinding {
    id: string;
    investigationId?: string | null;
    category: InvestigationCategory;
    sourceModule: string;
    sourceRecordType: string;
    sourceRecordId: string;
    title: string;
    summary: string;
    detail: string;
    severity: 'info' | 'warning' | 'critical';
    confidence: 'low' | 'medium' | 'high' | null;
    reasonCode: string | null;
    status: 'new' | 'in_review' | 'needs_more_evidence' | 'reviewed' | 'dismissed' | 'escalated' | 'included_in_package';
    evidenceRefs: EvidenceReference[];
    suggestedRecords: SuggestedRecord[];
    recommendedAction: RecommendedAction | null;
    reviewerStatus: 'pending' | 'reviewed' | 'dismissed' | null;
    createdAt: string;
    updatedAt: string;
}
```

### EvidenceReference

```ts
export interface EvidenceReference {
    id: string;
    evidenceType: string;
    label: string;
    sourceType: string;
    sourceId: string | null;
    sourceRecordId: string | null;
    citationLabel: string | null;
    sourceRowRange?: string | null;
    summary?: string | null;
}
```

### SuggestedRecord

```ts
export interface SuggestedRecord {
    id: string;
    recordType: string;
    label: string;
    reason: string;
    priority: 'required' | 'recommended' | 'optional';
    status: 'requested' | 'received' | 'waived' | 'not_available';
}
```

### RecommendedAction

```ts
export interface RecommendedAction {
    key: string;
    label: string;
    explanation: string;
    requiresConfirmation: boolean;
}
```

## API Implications

This repo is the Expo/React frontend. The durable system of record lives in the Laravel API, so the redesign requires matching backend work.

Recommended API surface:

- `GET /api/investigations`
- `POST /api/investigations`
- `GET /api/investigations/:id`
- `PATCH /api/investigations/:id`
- `GET /api/investigations/:id/findings`
- `POST /api/investigations/:id/findings/:findingId/review`
- `GET /api/investigations/:id/evidence`
- `POST /api/investigations/:id/evidence`
- `GET /api/investigations/:id/suggested-records`
- `POST /api/investigations/:id/notes`
- `GET /api/investigations/:id/activity`
- `POST /api/investigations/:id/packages`
- `GET /api/investigations/:id/packages`

Recommended source adapters:

- `GET /api/findings?category=&source_module=&status=`
- `POST /api/findings/:id/create-investigation`
- `POST /api/tax-notices/interpret` should optionally create or attach to a tax investigation.
- `POST /api/reconciliation/discrepancies/:id/create-finding` or return normalized finding data directly.
- `GET /api/transactions/:id` should expose normalized evidence references.

## Data Flow

```text
Intake
  -> Business context
  -> Evidence checklist
  -> Uploads and integrations
  -> Source validation and provenance
  -> Source-specific analyzers
  -> Normalized findings
  -> Investigation workspace
  -> Reviewer notes and decisions
  -> Suggested records
  -> Case package export
```

### Source-Specific Analyzer Outputs

Each source-specific tool should output:

- normalized findings
- evidence references
- suggested records
- confidence
- limitations
- recommended actions

This creates a clean seam between source-specific implementation and investigation-level workflows.

## Migration Plan

### Phase 0: Spec and Naming Alignment

Goal:

Align product direction without breaking current routes.

Tasks:

- Approve this spec.
- Create shared language for Investigation, Finding, Evidence Item, Suggested Record, Reviewer Note, Review Event, and Case Package.
- Update product copy to avoid IRS-only and fraud-only positioning.
- Decide whether "case" remains user-facing or becomes legacy/internal language.

Acceptance criteria:

- Product team agrees that Investigations is the central object.
- Fraud and tax are listed as categories, not primary product identity.
- Existing user workflows remain reachable.

### Phase 1: Frontend Reframe

Goal:

Make the existing app feel like an investigation platform before deeper contract work.

Tasks:

- Rename primary navigation around Investigations, Evidence, Findings, Reports, Rex, and Action Plan.
- Move Tax Notices, Vendor Risk, Reconciliation, Transactions, AR Aging, Entity Graph, Controls, and Analytics into secondary tools.
- Update onboarding intent labels to business health categories while preserving canonical keys during transition.
- Reword empty states and headers around investigations, findings, evidence, and case packages.
- Make Reports clearly show package history and route users to investigation packages.

Acceptance criteria:

- New users can understand the product as an investigation workspace within the first screen.
- IRS and fraud appear as categories, not standalone identities.
- No backend migration is required for this phase.

### Phase 2: Shared Finding Contract

Goal:

Normalize findings across modules.

Tasks:

- Add `InvestigationFinding`, `EvidenceReference`, `SuggestedRecord`, and `RecommendedAction` frontend contracts.
- Build source adapters for current frontend payloads.
- Update Action Plan, Findings, Investigations, and Reports to consume the shared finding interface.
- Keep legacy payload normalizers during transition.

Acceptance criteria:

- Reconciliation discrepancies, transaction anomalies, alert recommendations, tax notice results, and vendor risk rows can all render as findings.
- Finding review status behaves consistently across categories.
- Package Builder can consume findings from more than one source module.

### Phase 3: Unified Investigation Workspace

Goal:

Consolidate Cases and Investigations.

Tasks:

- Decide canonical route: recommended `/(dashboard)/investigations`.
- Make `/(dashboard)/cases` a redirect, filtered view, or legacy alias.
- Move case detail functionality into the investigation detail workspace.
- Preserve existing case API compatibility through adapters if backend migration lags.
- Add category, scope, limitations, suggested records, and package readiness to the detail view.

Acceptance criteria:

- Users have one formal workspace for review work.
- Existing case links still resolve.
- Investigations can include findings from multiple source modules.

### Phase 4: Evidence Ledger

Goal:

Unify evidence readiness and investigation evidence.

Tasks:

- Create shared Evidence Ledger UI primitives.
- Connect upload/integration status to investigation evidence.
- Add source row/citation display where available.
- Make missing evidence and suggested records actionable from investigation detail.
- Preserve row-level provenance for package export.

Acceptance criteria:

- Evidence checklist items can be satisfied by uploaded files, integrations, source rows, documents, or reviewer-added records.
- Each finding can show supporting evidence and missing records.
- Package export can include evidence references consistently.

### Phase 5: Package Builder

Goal:

Make exportable case packages the premium product outcome.

Tasks:

- Create a package builder contract and UI.
- Include findings, evidence, suggested records, reviewer notes, activity timeline, limitations, disclaimers, and manifest.
- Add package history across investigations.
- Add safeguards so packages are user-triggered and reviewer-owned.

Acceptance criteria:

- A reviewer can generate a complete package from an investigation.
- Package output is consistent across tax, fraud, revenue, expense, payroll, reconciliation, and controls categories.
- Export history includes generated by, generated at, format, filename, and package hash/manifest.

## Testing Strategy

### Unit Tests

- Contract normalization for `InvestigationFinding`.
- Source adapter tests for reconciliation, transactions, alerts, tax notices, and vendor risk.
- Evidence reference formatting.
- Suggested record status transitions.
- Package manifest formatting.

### Component Tests

- Action Plan renders investigation category, findings, missing evidence, and next best action.
- Findings queue renders mixed-source findings.
- Investigation detail renders findings, evidence, suggested records, notes, and packages.
- Reports renders package history.
- Tax Notice result can be represented as a finding.

### End-to-End Tests

- Start an investigation from onboarding.
- Upload/connect evidence.
- Review a finding.
- Add reviewer notes.
- Generate a package.
- Confirm legacy case links still resolve.

### Regression Tests

- Existing routes remain reachable during migration.
- Existing Action Plan contract normalizers still accept legacy keys.
- User-triggered package generation remains explicit.
- Sensitive review metadata is not rendered in public-facing drawers or report previews.

## Risks

### Risk: Over-broad Product Scope

The taxonomy can expand too quickly. Mitigation: ship with the current strongest categories first: expense, tax, reconciliation, fraud, vendor/payments, and advisor-client review.

### Risk: Backend Contract Lag

The frontend can reframe faster than the Laravel API can normalize data. Mitigation: use frontend adapters and preserve legacy payload normalizers during migration.

### Risk: Legal/Tax/Fraud Claims

Users may overinterpret findings as conclusions. Mitigation: use "risk indicator," "reviewable finding," "supporting evidence," and "scope limitation" language throughout.

### Risk: Cases/Investigations Migration Confusion

Existing users may have links to case routes. Mitigation: keep redirects or aliases until backend and user-facing docs are migrated.

### Risk: Package Output Quality

Case packages are high-value but can expose weak evidence if generated too early. Mitigation: show package readiness, missing records, low-confidence findings, and limitations before generation.

## Open Questions

- Should "case" remain a user-facing term, or should the product use "investigation" everywhere?
- Which category should be the first polished vertical: tax notices, vendor payments, reconciliation, or advisor-client review?
- Should payroll be included in v1 taxonomy if payroll ingestion is not yet strong?
- Should CPA firms have a client workspace model distinct from owner-operated business workspaces?
- What package formats are required first: PDF, JSON, CSV evidence ledger, or ZIP bundle?
- How should Rex cite source records inside investigation answers?
- Should package generation require all critical findings to be reviewed, or should it allow draft packages with limitations?

## Recommended First Implementation Slice

The first implementation slice should be narrow:

1. Rename and reframe navigation and core copy around Investigations, Findings, Evidence, Reports, and Business Health categories.
2. Add shared frontend contracts for `InvestigationFinding`, `EvidenceReference`, and `SuggestedRecord`.
3. Build adapters for current Action Plan findings, reconciliation discrepancies, transaction anomalies, and tax notice interpretations.
4. Update the Investigations detail drawer to present "Findings," "Evidence," "Suggested Records," "Reviewer Notes," and "Case Package" as the primary structure.
5. Keep all existing routes working during migration.

This slice proves the redesign without waiting for every backend table or analyzer to be rebuilt.

## Success Criteria

- A new user understands Brevix as an investigation workspace, not an IRS or fraud-only tool.
- A CPA can start from a client issue and end with an evidence-backed case package.
- Fraud and tax workflows remain visible but no longer dominate the product.
- Findings from multiple source modules render in one consistent queue.
- Evidence, reviewer notes, source references, and packages are tied to investigations.
- The app can distinguish missing evidence from no issue found.
- The architecture becomes easier to extend because source tools adapt into shared investigation contracts.
