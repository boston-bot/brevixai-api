# Investigation Workspace

**Status:** Production-ready (Phases 4.1–4.8)
**Tests:** 83 passing (329 assertions) across 4 test files — 221 total suite tests pass

---

## Table of Contents

1. [Feature Overview](#1-feature-overview)
2. [Architecture](#2-architecture)
3. [Data Lifecycle](#3-data-lifecycle)
4. [Recommendation → Case → Investigation Flow](#4-recommendation--case--investigation-flow)
5. [Evidence Ledger Lifecycle](#5-evidence-ledger-lifecycle)
6. [Report Export Lifecycle](#6-report-export-lifecycle)
7. [Package Manifest Lifecycle](#7-package-manifest-lifecycle)
8. [Audit / Event Lifecycle](#8-audit--event-lifecycle)
9. [Security Rules](#9-security-rules)
10. [Agent Restrictions](#10-agent-restrictions)
11. [API Endpoint Reference](#11-api-endpoint-reference)
12. [Required Environment and Configuration Values](#12-required-environment-and-configuration-values)
13. [Artisan Commands](#13-artisan-commands)
14. [Test Commands](#14-test-commands)
15. [Deployment Checklist](#15-deployment-checklist)
16. [Known Limitations](#16-known-limitations)
17. [Future Enhancements](#17-future-enhancements)

---

## 1. Feature Overview

The Investigation Workspace gives analysts a structured, auditable environment for managing active fraud and compliance investigations tied to approved case recommendations.

Key capabilities:

- **Workspace layer** — per-case status, priority, assignee, summary, and notes tracked independently of the underlying `audit_cases` workflow status
- **Evidence ledger** — a typed, append-only ledger of evidence items linked to an investigation, built by analysts and system processes
- **Report export** — on-demand JSON and PDF reports summarizing the investigation; every export is hashed and recorded for audit purposes
- **Package manifest** — on-demand JSON inventory of all investigation materials, suitable for chain-of-custody review before sharing or archiving
- **Immutable activity timeline** — every write to the workspace produces an `investigation_activity_event` record; the timeline is never modified after creation

All investigation features are **human-initiated only**. Agents have no write access to any part of this system.

---

## 2. Architecture

### Components

```
routes/api.php
    └── /api/investigations  (auth:sanctum)
            └── InvestigationController
                    ├── InvestigationService             (workspace CRUD + activity recording)
                    ├── InvestigationEvidenceService     (evidence ledger)
                    ├── InvestigationReportService       (JSON + PDF report export)
                    └── InvestigationPackageManifestService  (export manifest)
```

### Models

| Model | Table | Purpose |
|---|---|---|
| `AuditCase` | `audit_cases` | Extends with 7 investigation columns |
| `InvestigationActivityEvent` | `investigation_activity_events` | Immutable audit log |
| `InvestigationEvidenceItem` | `investigation_evidence_items` | Evidence ledger entries |
| `InvestigationReportExport` | `investigation_report_exports` | Report export history |

### Migrations

| File | Adds |
|---|---|
| `2026_05_18_150000_add_investigation_workspace_to_audit_cases.php` | 7 investigation columns on `audit_cases`; creates `investigation_activity_events` |
| `2026_05_18_160000_create_investigation_evidence_items_table.php` | Creates `investigation_evidence_items` |
| `2026_05_19_120000_create_investigation_report_exports_table.php` | Creates `investigation_report_exports` |

### Key Design Decisions

- **Dual-status design.** `audit_cases.status` tracks the case workflow (`open`, `investigating`, `resolved`, `archived`). `investigation_status` tracks the investigator workspace (`open`, `in_review`, `escalated`, `resolved`, `archived`). Both can change independently.
- **No persisted reports.** Report content is built dynamically from `InvestigationService::detail()` on every request. Only the export metadata row (format, hash, counts, generator) is persisted.
- **Service-layer agent block.** Agent restrictions are enforced inside each service method via `assertNotAgent()`, not at the route or middleware level, preventing any code path — direct or indirect — from bypassing the check.
- **Metadata sanitization at every layer.** Sensitive keys are stripped before writing to the database and again before returning responses. This is a double-sanitization that prevents sensitive data from leaking into the audit trail or API output even if a caller passes it inadvertently.

---

## 3. Data Lifecycle

```
audit_cases
├── investigation_status          (workspace state, independent of case status)
├── investigation_assigned_user_id
├── investigation_priority
├── investigation_summary
├── investigation_notes
├── last_activity_at              (updated on every workspace write)
└── investigation_metadata        (sanitized; internal extensible state)

investigation_activity_events     (append-only; one row per workspace action)
investigation_evidence_items      (ledger; rows deleted only on explicit analyst remove)
investigation_report_exports      (audit record per report generation; content not stored)
```

### `audit_cases` — Investigation Columns

| Column | Type | Default | Description |
|---|---|---|---|
| `investigation_status` | text | `open` | Workspace-level status |
| `investigation_assigned_user_id` | uuid FK | null | Assigned analyst (nulls on user delete) |
| `investigation_priority` | text | `medium` | Triage priority |
| `investigation_summary` | text | null | Analyst-authored synthesis |
| `investigation_notes` | text | null | Working notes (mutable, max 10,000 chars via API) |
| `last_activity_at` | timestamptz | null | Updated on every workspace activity |
| `investigation_metadata` | jsonb | null | Extensible structured state (sanitized) |

### `investigation_activity_events`

| Column | Type | Description |
|---|---|---|
| `id` | uuid PK | Event identifier |
| `audit_case_id` | uuid FK | Parent case (cascades on delete) |
| `company_id` | uuid FK | Denormalized for query efficiency |
| `event_type` | text | See event type table in §8 |
| `actor_type` | text | `user`, `system`, or `agent` |
| `actor_id` | uuid | Nullable for system events |
| `event_summary` | text | Human-readable description |
| `event_metadata` | jsonb | Sanitized context; never contains raw evidence |
| `created_at` | timestamptz | Set at insert; immutable |

### `investigation_evidence_items`

| Column | Type | Description |
|---|---|---|
| `id` | uuid PK | Item identifier |
| `audit_case_id` | uuid FK | Parent case (cascades on delete) |
| `company_id` | uuid FK | Denormalized |
| `evidence_type` | text | See type constants in §5 |
| `evidence_reference_id` | uuid | Optional FK to source entity |
| `title` | text | Max 500 chars |
| `summary` | text | Max 5,000 chars |
| `source` | text | Origin description, max 500 chars |
| `added_by_actor_type` | text | `user` or `system` |
| `added_by_actor_id` | uuid | Nullable |
| `metadata` | jsonb | Sanitized |
| `created_at` | timestamptz | Set at insert |

### `investigation_report_exports`

| Column | Type | Description |
|---|---|---|
| `id` | uuid PK | Export identifier |
| `audit_case_id` | uuid FK | Parent case (cascades on delete) |
| `company_id` | uuid FK | Denormalized |
| `generated_by_user_id` | uuid FK | User who generated the report (restrict delete) |
| `format` | text | `json` or `pdf` |
| `filename` | text | PDF filename; null for JSON |
| `report_hash` | text | SHA-256 of canonicalized report payload |
| `generated_at` | timestamptz | Generation timestamp |
| `metadata` | jsonb | Evidence/event counts at time of generation |

---

## 4. Recommendation → Case → Investigation Flow

```
CaseRecommendation  (status: pending_review)
        │
        │  POST /api/case-recommendations/{id}/approve   (user action)
        ▼
AuditCase created
        │  status:                open
        │  investigation_status:  open      (default)
        │  investigation_priority: medium   (default)
        │
        │  → InvestigationActivityEvent: case_created
        │  → InvestigationEvidenceItem:  recommendation type (ACTOR_SYSTEM, auto-created)
        │
        │  POST /api/investigations/{id}/assign
        ▼
investigation_assigned_user_id set
        │  → InvestigationActivityEvent: assigned
        │
        │  POST /api/investigations/{id}/status
        ▼
investigation_status: in_review → escalated → resolved → archived
        │  → InvestigationActivityEvent: status_changed  (each transition)
        │
        │  POST /api/investigations/{id}/notes
        ▼
investigation_notes updated
        │  → InvestigationActivityEvent: notes_added
        │
        │  POST /api/investigations/{id}/evidence  (analyst adds items)
        ▼
investigation_evidence_items rows created
        │  → InvestigationActivityEvent: evidence_linked
        │
        │  POST /api/investigations/{id}/reports
        ▼
Report generated (JSON or PDF); InvestigationReportExport row created
        │  → InvestigationActivityEvent: report_generated
        │
        │  POST /api/investigations/{id}/package-manifest
        ▼
Package manifest returned (in-memory only, not persisted)
           → InvestigationActivityEvent: package_manifest_generated
```

**Integration point:** `CaseRecommendationReviewService` is responsible for creating the `AuditCase` and the initial `case_created` activity event and recommendation evidence item when a recommendation is approved. It uses `Schema::hasTable` guards so it degrades gracefully if the tables do not yet exist.

---

## 5. Evidence Ledger Lifecycle

### Evidence Types

| Constant | Value | Used for |
|---|---|---|
| `TYPE_TRANSACTION` | `transaction` | A specific transaction flagged as evidence |
| `TYPE_VENDOR` | `vendor` | A vendor entity linked to the investigation |
| `TYPE_ALERT` | `alert` | A risk alert surfaced by the scoring pipeline |
| `TYPE_RECOMMENDATION` | `recommendation` | The originating case recommendation (auto-linked on case creation) |
| `TYPE_NOTE` | `note` | Analyst narrative note added as evidence |
| `TYPE_DOCUMENT` | `document` | External document reference |
| `TYPE_SYSTEM_FINDING` | `system_finding` | Automated system finding |

### Lifecycle

1. A `recommendation` type item is **auto-created** by `CaseRecommendationReviewService` when a recommendation is approved (`ACTOR_SYSTEM`).
2. Analysts can add additional items via `POST /api/investigations/{id}/evidence`.
3. Analysts can remove items via `DELETE /api/investigations/{id}/evidence/{evidenceItemId}`.
4. Agents **cannot** add or remove evidence items (403 at service layer).
5. Metadata is sanitized (sensitive keys stripped) before storage and before API response.
6. Evidence items are ordered by `created_at ASC` in list and report output.
7. An `evidence_linked` or `evidence_removed` activity event is recorded on every change.

### Sensitive Metadata Keys (Stripped Everywhere)

The following keys are removed from all metadata fields before storage and before API output, at any nesting level:

- `evidence`
- `supporting_evidence`
- `raw_evidence`
- `transaction_details`
- `raw_payload`
- `review_note`
- `payload`

---

## 6. Report Export Lifecycle

Reports are built **on demand** from the live investigation state at the time of the request. Report content is **never persisted** — only the export metadata row is stored.

### Report Sections

| Section | Contents |
|---|---|
| `case_summary` | id, title, description, status, severity, dates, created_by, assigned_to |
| `risk_summary` | case_type, severity, source_risk_domains, confidence_score, linked_alert_count |
| `investigative_synthesis` | investigation_status, priority, summary, last_activity_at, assigned_user, recommendation details |
| `evidence_items` | All evidence items with metadata sanitized |
| `activity_timeline` | All activity events; `event_metadata` is **excluded entirely** |
| `notes` | Notes array with content and type |
| `disclaimer` | Fixed: *"This report summarizes risk indicators and review activity. It is not a legal conclusion or proof of fraud."* |

### Disclaimer

The disclaimer is always included and appears prominently in both JSON and PDF output. In the PDF it is rendered at the top of the document and again in the footer.

### Report Integrity Hash

Every export row includes `report_hash`: a SHA-256 hex digest of the canonicalized (recursively sorted keys) report payload sections. This allows downstream systems to verify the report content has not been modified.

### PDF Format

PDF reports are rendered via `barryvdh/laravel-dompdf` using the Blade view `resources/views/reports/investigation-pdf.blade.php`. The response uses `Content-Type: application/pdf` with `Content-Disposition: attachment`.

### Export Audit Row

Every `POST /api/investigations/{id}/reports` call creates an `InvestigationReportExport` row regardless of format. The `generated_by_user_id` foreign key uses `restrict` on delete, preserving the audit record of who generated the report even if the user account is later removed.

---

## 7. Package Manifest Lifecycle

The package manifest is an **in-memory, non-persisted** JSON inventory of all materials that would be included in an investigation export package. It is intended for human review before sharing or archiving.

### What It Includes

| Section | Contents |
|---|---|
| `report_exports` | All export records (id, format, filename, hash, generated_by, generated_at) |
| `evidence_items` | All evidence items (title, summary, type, source, reference_id) — metadata excluded |
| `linked_alerts` | Alert references — raw `detail` and `evidence` fields excluded |
| `linked_recommendations` | Recommendation references — raw `evidence`, `source_rule_ids`, review_note excluded |
| `activity_events` | All activity events — `event_metadata` excluded |
| `notes` | Reference only: character_count and updated_at; note body is not returned |
| `disclaimer` | Fixed disclaimer string |

### What Is Not Persisted

Only a single `package_manifest_generated` activity event is written. The manifest payload itself is returned in the API response and discarded; it is not written to the database or filesystem.

### Manifest Response Shape

```json
{
  "manifest": {
    "investigation_id": "uuid",
    "generated_at": "ISO8601",
    "generated_by_user_id": "uuid",
    "included_sections": ["report_exports", "evidence_items", "linked_alerts",
                          "linked_recommendations", "activity_events", "notes", "disclaimer"],
    "included_counts": {
      "report_exports": 1,
      "evidence_items": 3,
      "linked_alerts": 2,
      "linked_recommendations": 1,
      "activity_events": 7,
      "notes": 1
    },
    "report_exports": [],
    "evidence_items": [],
    "linked_alerts": [],
    "linked_recommendations": [],
    "activity_events": [],
    "notes": [],
    "disclaimer": "This package manifest summarizes included investigation materials and review activity. It is not a legal conclusion or proof of fraud."
  }
}
```

---

## 8. Audit / Event Lifecycle

Every write to the investigation workspace produces an **immutable** `InvestigationActivityEvent` record. Records are never updated or deleted (only cascade-deleted when the parent `AuditCase` is deleted).

### Event Types

| Event | Triggered by |
|---|---|
| `case_created` | Case recommendation approved via `POST /api/case-recommendations/{id}/approve` |
| `assigned` | `POST /api/investigations/{id}/assign` |
| `status_changed` | `POST /api/investigations/{id}/status` |
| `notes_added` | `POST /api/investigations/{id}/notes` |
| `evidence_linked` | Evidence item added (user or system) |
| `evidence_removed` | Evidence item deleted by analyst |
| `recommendation_approved` | Internal: additional recommendation linkage |
| `report_generated` | `POST /api/investigations/{id}/reports` (JSON or PDF) |
| `package_manifest_generated` | `POST /api/investigations/{id}/package-manifest` |

### Actor Types

| Type | When used |
|---|---|
| `user` | All analyst-initiated workspace actions |
| `system` | Automated processes (recommendation approval auto-linking) |
| `agent` | Reserved; no agent write path currently exists |

### `last_activity_at`

`audit_cases.last_activity_at` is updated to `now()` on every call to `InvestigationService::recordActivity()`. The list endpoint sorts by priority (critical → low) then `last_activity_at DESC`.

### Timeline in Reports

The activity timeline included in report exports and package manifests has `event_metadata` stripped entirely to prevent internal operational context from leaking into exported documents.

---

## 9. Security Rules

### Authentication

All investigation endpoints require `auth:sanctum`. Unauthenticated requests receive `401`.

### Company Isolation

Every service method accepts `$companyId` and cross-references all queries against it. A case belonging to company A is a `404` for a user from company B. Assignees must belong to the same company as the investigation; cross-company assignment returns `422`.

### Metadata Sanitization

The following keys are stripped from all metadata fields before **storage** and before **API output**:

```
evidence, supporting_evidence, raw_evidence,
transaction_details, raw_payload, review_note, payload
```

This applies to:
- `investigation_activity_events.event_metadata`
- `investigation_evidence_items.metadata`
- `audit_cases.investigation_metadata`
- Report JSON and PDF output
- Package manifest output

### Report Integrity

Each `InvestigationReportExport` row stores a SHA-256 hash of the report payload at generation time. This hash enables tamper-detection if the report is later compared against a re-generated copy.

### Audit Preservation

`investigation_report_exports.generated_by_user_id` uses `restrictOnDelete`. If a user account is deactivated or deleted, the export row is preserved with the user reference intact, ensuring the audit trail of who generated reports is never lost.

### Input Validation

| Field | Constraint |
|---|---|
| `investigation_status` | Must be one of: `open`, `in_review`, `escalated`, `resolved`, `archived` |
| `investigation_priority` | Must be one of: `critical`, `high`, `medium`, `low` |
| `evidence_type` | Must be one of: `transaction`, `vendor`, `alert`, `recommendation`, `note`, `document`, `system_finding` |
| `notes` | Max 10,000 characters |
| `title` (evidence) | Max 500 characters |
| `summary` (evidence) | Max 5,000 characters |
| `source` (evidence) | Max 500 characters |
| `format` (report) | Must be one of: `json`, `pdf` |
| `limit` | 1–100 (default 50) |

---

## 10. Agent Restrictions

Agents are explicitly blocked from all write operations in the investigation workspace. The restriction is enforced at the **service layer** via `assertNotAgent()` in each service class, not at the route or middleware level.

| Operation | Agent access |
|---|---|
| List investigations | Allowed (read-only) |
| View investigation detail | Allowed (read-only) |
| List evidence items | Allowed (read-only) |
| View report exports list | Allowed (read-only) |
| Assign investigation | **Blocked — 403** |
| Update investigation status | **Blocked — 403** |
| Add investigation notes | **Blocked — 403** |
| Add evidence item | **Blocked — 403** |
| Remove evidence item | **Blocked — 403** |
| Generate report (JSON or PDF) | **Blocked — 403** |
| Generate package manifest | **Blocked — 403** |

The `ACTOR_AGENT` constant exists in models for completeness and future auditing. No current code path produces an agent-originated event.

---

## 11. API Endpoint Reference

All endpoints are under the `auth:sanctum` middleware. All IDs are UUIDs.

### List Investigations

```
GET /api/investigations
```

**Query parameters:**

| Parameter | Type | Values | Default |
|---|---|---|---|
| `investigation_status` | string | `all`, `open`, `in_review`, `escalated`, `resolved`, `archived` | `open` |
| `investigation_priority` | string | `critical`, `high`, `medium`, `low` | — |
| `assigned_to` | uuid | User ID | — |
| `limit` | int | 1–100 | 50 |
| `offset` | int | ≥ 0 | 0 |

**Response:**
```json
{
  "investigations": [...],
  "total": 12,
  "status_counts": {
    "open": 5,
    "in_review": 4,
    "escalated": 1,
    "resolved": 2,
    "archived": 0
  }
}
```

---

### Get Investigation Detail

```
GET /api/investigations/{id}
```

**Response shape:**
```json
{
  "investigation": {
    "id": "uuid",
    "title": "string",
    "description": "string|null",
    "status": "open|investigating|resolved|archived",
    "severity": "critical|warning|info",
    "created_at": "ISO8601",
    "updated_at": "ISO8601",
    "resolved_at": "ISO8601|null",
    "resolution_notes": "string|null",
    "created_by": "Full Name",
    "assigned_to": "Full Name|null"
  },
  "workspace": {
    "investigation_status": "open|in_review|escalated|resolved|archived",
    "investigation_priority": "critical|high|medium|low",
    "investigation_summary": "string|null",
    "investigation_notes": "string|null",
    "investigation_metadata": "object|null",
    "last_activity_at": "ISO8601|null",
    "assigned_user": { "id": "uuid", "name": "string" }
  },
  "recommendation": { ... },
  "linked_alerts": [...],
  "evidence_items": [...],
  "activity_timeline": [...],
  "report_exports": [...]
}
```

---

### Assign Investigation

```
POST /api/investigations/{id}/assign
```

**Body:**
```json
{ "assignee_id": "uuid" }
```

Assignee must belong to the same company. Returns updated investigation detail.

---

### Update Investigation Status

```
POST /api/investigations/{id}/status
```

**Body:**
```json
{ "investigation_status": "in_review" }
```

Valid values: `open`, `in_review`, `escalated`, `resolved`, `archived`. No-op if status is unchanged.

---

### Add / Update Investigation Notes

```
POST /api/investigations/{id}/notes
```

**Body:**
```json
{ "notes": "string (max 10,000 chars)" }
```

Replaces the existing `investigation_notes` value.

---

### List Evidence Items

```
GET /api/investigations/{id}/evidence
```

**Response:**
```json
{
  "evidence_items": [...],
  "total": 4
}
```

---

### Add Evidence Item

```
POST /api/investigations/{id}/evidence
```

**Body:**
```json
{
  "evidence_type": "transaction",
  "evidence_reference_id": "uuid (optional)",
  "title": "string (max 500)",
  "summary": "string (max 5,000)",
  "source": "string (max 500, optional)",
  "metadata": "object (optional, sanitized)"
}
```

Agents are blocked. Returns the created evidence item.

---

### Remove Evidence Item

```
DELETE /api/investigations/{id}/evidence/{evidenceItemId}
```

Agents are blocked. Returns `204 No Content`.

---

### List Report Exports

```
GET /api/investigations/{id}/reports
```

Returns the list of `InvestigationReportExport` rows for the case (metadata only — report content is not stored).

---

### Generate Report

```
POST /api/investigations/{id}/reports
```

**Body:**
```json
{ "format": "json" }
```
or
```json
{ "format": "pdf", "filename": "investigation-report.pdf" }
```

- `format=json` (default): Returns a JSON report payload.
- `format=pdf`: Returns `Content-Type: application/pdf` with `Content-Disposition: attachment`.

Agents are blocked. Creates an `InvestigationReportExport` row and a `report_generated` activity event on every call.

---

### Generate Package Manifest

```
POST /api/investigations/{id}/package-manifest
```

**Body:**
```json
{ "format": "json" }
```

Returns a sanitized manifest of all investigation materials. Not persisted. Creates a `package_manifest_generated` activity event.

---

## 12. Required Environment and Configuration Values

The investigation workspace uses the standard Laravel application stack. No additional environment variables are required beyond a functioning Laravel installation.

### Required Standard Variables

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel encryption key (required for all Sanctum sessions) |
| `DB_CONNECTION` | `pgsql` recommended in production (JSONB column support) |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL connection |
| `QUEUE_CONNECTION` | Not used by investigation workspace directly; `database` or `redis` for other system jobs |

### PDF Export Dependency

PDF generation uses `barryvdh/laravel-dompdf`. This package is installed via Composer and uses `config/dompdf.php` for settings. No additional environment variables are required. Default DomPDF settings work for investigation reports.

For production environments with strict CSP or font requirements, review the `options` array in `config/dompdf.php`.

### No Custom Config Keys

The investigation workspace has no `config/investigations.php` file. All business constants live in model and service class constants.

---

## 13. Artisan Commands

There are no custom Artisan commands specific to the investigation workspace. Standard Laravel database and queue commands apply.

### Database Setup

```bash
# Run all three investigation workspace migrations
php artisan migrate

# Or run migrations for a specific date range to target only investigation tables
php artisan migrate --path=database/migrations/2026_05_18_150000_add_investigation_workspace_to_audit_cases.php
php artisan migrate --path=database/migrations/2026_05_18_160000_create_investigation_evidence_items_table.php
php artisan migrate --path=database/migrations/2026_05_19_120000_create_investigation_report_exports_table.php
```

### Roll Back

```bash
php artisan migrate:rollback --step=3
```

### Fresh Install (Development Only)

```bash
php artisan migrate:fresh --seed
```

---

## 14. Test Commands

### Run All Investigation Tests

```bash
php artisan test --filter Investigation
```

Expected output: **83 tests, 329 assertions, 0 failures**

### Run Individual Test Files

```bash
# Workspace (assignment, status, notes, activity recording, metadata sanitization)
php artisan test tests/Feature/InvestigationWorkspaceTest.php

# Evidence ledger (add, remove, agent blocking, metadata sanitization)
php artisan test tests/Feature/InvestigationEvidenceLedgerTest.php

# Report export (JSON, PDF, hash, export history, agent blocking)
php artisan test tests/Feature/InvestigationReportExportTest.php

# Package manifest (manifest structure, sanitization, agent blocking)
php artisan test tests/Feature/InvestigationPackageManifestTest.php
```

### Run Full Suite

```bash
php artisan test
```

Expected output: **221 tests, 1133 assertions, 0 failures**

### Verbose Output

```bash
php artisan test --filter Investigation --verbose
```

---

## 15. Deployment Checklist

### Pre-Deployment

- [ ] `composer install --no-dev --optimize-autoloader` completes without errors
- [ ] `barryvdh/laravel-dompdf ^3.1` is present in `vendor/`
- [ ] Environment variables: `APP_KEY`, `DB_*` are set in production `.env`
- [ ] `DB_CONNECTION=pgsql` for production (JSONB column support)
- [ ] `php artisan config:cache` completes without errors
- [ ] `php artisan route:cache` completes without errors

### Database Migration

- [ ] Run `php artisan migrate` — 3 new investigation tables/columns added
- [ ] Verify `audit_cases` has columns: `investigation_status`, `investigation_assigned_user_id`, `investigation_priority`, `investigation_summary`, `investigation_notes`, `last_activity_at`, `investigation_metadata`
- [ ] Verify table `investigation_activity_events` exists with correct schema
- [ ] Verify table `investigation_evidence_items` exists with correct schema
- [ ] Verify table `investigation_report_exports` exists with correct schema
- [ ] Confirm check constraints on `investigation_status` and `investigation_priority` are applied (PostgreSQL)

### Functionality Verification

- [ ] `GET /api/investigations` returns `200` for authenticated user
- [ ] `POST /api/investigations/{id}/reports` with `{"format":"json"}` returns a valid JSON report
- [ ] `POST /api/investigations/{id}/reports` with `{"format":"pdf"}` returns a PDF attachment
- [ ] Agent-authenticated request to `POST /api/investigations/{id}/evidence` returns `403`
- [ ] Cross-company request to any investigation endpoint returns `404`
- [ ] `php artisan test --filter Investigation` passes (83 tests, 329 assertions)

### Post-Deployment

- [ ] Confirm `investigation_report_exports` rows are being written on report generation
- [ ] Confirm `investigation_activity_events` rows are being written on all workspace actions
- [ ] Review logs for any unexpected `500` errors from `InvestigationController`

---

## 16. Known Limitations

### No Status Transition Enforcement

`investigation_status` transitions are not machine-enforced. Any status can be set to any valid value in one step. For example, an analyst can move directly from `open` to `archived` without passing through `in_review` or `resolved`. The system records whatever transition occurred in the activity timeline.

### Notes Are Replace-Only

`POST /api/investigations/{id}/notes` replaces the entire `investigation_notes` field. There is no append-only note history or versioning. The activity event records `has_previous_notes` and `note_length` but not the previous content.

### Reports Are Ephemeral

Report content is generated dynamically and not stored. Re-generating a report after new evidence or activity is added will produce a different payload even with the same case ID. The `report_hash` on the export row reflects the content at the time of generation, not current state.

### Package Manifest Is Not a Package

The manifest lists materials by reference. It does not create a downloadable archive, does not bundle files, and does not export the underlying transaction or vendor records. It is a chain-of-custody inventory only.

### Single Format for Package Manifests

`POST /api/investigations/{id}/package-manifest` only accepts `format=json`. PDF manifest export is not implemented.

### Evidence Metadata Is Never Surfaced in Manifests

The `metadata` field on evidence items is sanitized before storage and is never included in the package manifest output. Analysts needing the raw metadata must query the evidence detail directly.

### No Pagination on Evidence or Activity Timeline

`GET /api/investigations/{id}/evidence` and the `activity_timeline` in the detail endpoint return all rows without pagination. For long-running investigations with many events, response sizes may grow large.

---

## 17. Future Enhancements

These items are tracked as known gaps but are not committed to any release:

- **Evidence pagination** — paginate `investigation_evidence_items` on the list endpoint
- **Activity timeline pagination** — paginate `investigation_activity_events` in the detail and report endpoints
- **Note history** — version `investigation_notes` with append-only history instead of replace semantics
- **Status transition enforcement** — define and enforce valid transition paths (e.g., `open → in_review → resolved`)
- **PDF package manifest** — extend the manifest endpoint to support `format=pdf`
- **Report comparison** — expose an endpoint to compare two report hashes and flag changes
- **Evidence soft-delete** — soft-delete evidence items instead of hard-delete to preserve the full ledger history
- **Bulk status update** — allow updating multiple investigations to the same status in a single request
- **Investigation search** — full-text search on `investigation_notes`, `investigation_summary`, and evidence item titles/summaries
- **Notification hooks** — emit events when `investigation_status` changes to `escalated` so downstream notification systems can alert senior reviewers
