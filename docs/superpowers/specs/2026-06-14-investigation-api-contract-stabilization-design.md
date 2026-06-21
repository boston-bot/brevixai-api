# Investigation API Contract Stabilization Design

Date: 2026-06-14
Status: Approved for implementation

## Purpose

The first implementation slice should make the Laravel API the source of truth for the investigation platform contract before frontend adapters, Rex, analyzers, or package builder work depend on it.

Package builder remains last. This slice only stabilizes canonical investigation, finding, evidence, suggested-record, reviewer-note, review-event, and case-package contracts, plus compatibility endpoints that let existing pages migrate without a hard frontend rewrite.

## Scope

In scope:

- Declare canonical resource shapes from `GET /api/investigation-platform/contract`.
- Preserve compatibility on existing investigation, finding, evidence, report, and package endpoints.
- Persist source analyzer projections into canonical findings, evidence items, and suggested records.
- Let standalone findings exist before they are attached to an investigation.
- Keep tax notice interpretation capable of returning normalized projections without persistence, and optionally materializing them.
- Add focused route and feature tests for the contract surface.

Out of scope:

- Frontend TypeScript contracts and navigation copy changes.
- Rex or agent-service migration.
- Full package builder expansion beyond existing JSON package compatibility.
- Removing legacy case routes or old investigation payload aliases.

## Architecture

### Canonical Modules

The canonical modules are:

- `Investigation`
- `Finding`
- `EvidenceItem`
- `SuggestedRecord`
- `ReviewerNote`
- `ReviewEvent`
- `CasePackage`

Their interface lives in `InvestigationPlatformContractService` and `InvestigationPlatformService`. Callers should not need source-module-specific payload knowledge to render a finding, cite evidence, request missing records, record reviewer decisions, or list packages.

### Compatibility Strategy

Existing endpoints keep working while canonical endpoints mature:

- `/api/investigations/*` prefers canonical tables when available.
- Legacy investigation and case behavior remains a fallback.
- Responses may include legacy aliases during migration, but canonical camelCase fields are the contract frontend/Rex/analyzers should target.
- `/api/findings` can return durable canonical rows or source projections when canonical tables are not available.

### Source Adapters

`SourceFindingAdapterService` adapts route-specific analyzer outputs into projected canonical findings, evidence items, and suggested records.

`SourceFindingMaterializationService` persists those projections idempotently by `(company, business profile, source module, source record type, source record id)`. Evidence and suggested records can exist without `investigation_id` until a reviewer opens or attaches an investigation.

### Sequencing

1. Stabilize API contracts and compatibility endpoints.
2. Add frontend shared contracts and adapters in the Expo repo.
3. Reframe frontend copy/nav around Investigations, Findings, Evidence, and Reports.
4. Move analyzers and Rex onto the shared model.
5. Build package builder last, after findings/evidence/reviewer notes are stable.

## Error Handling

- Missing workspace/profile context returns the existing safe JSON authorization response.
- Missing canonical tables returns compatibility fallback where possible and `503` only for canonical-only mutations.
- Missing investigations return `404`.
- Invalid materialization targets return `404` or `422` rather than silently creating cross-tenant links.
- Raw source records, raw notice text, and sensitive analyzer payloads must not be embedded in normalized responses.

## Testing

Focused tests should cover:

- Required contract routes are registered.
- Source projections can be materialized idempotently.
- Materialized findings can open investigations and attach previously standalone evidence and suggested records.
- Tax notice interpretation can return normalized projections and optionally persist them without leaking raw notice text.
- Canonical contract responses expose stable camelCase resource shapes for frontend adapters.

## Implementation Notes

The existing package endpoints can remain compatibility wrappers. Do not expand package builder in this slice except where needed to keep package listing/generation contract-safe.

Rex and agent-service work should wait until this API contract is stable enough for the frontend to adopt.
