# Business Profile Tenancy Hardening Implementation Plan

Date: 2026-05-31

## Objective

Ensure deterministic agent tools, Rex route responses, risk scoring, and recommendation review workflows operate inside the active business profile context whenever a workspace has business profiles.

## Phase 3 Status

Reconciled on 2026-06-01 after `dev` was refreshed following a commit conflict.

### Implemented

- `BusinessProfileContextService` exposes `resolveForUser()` so internal agent tools can resolve the same active profile context as request-driven controllers.
- Internal deterministic agent tool endpoints require an active business profile when profiles exist, return 422 for ambiguous multi-profile workspaces, and pass the resolved profile into transaction lookup, dashboard summaries, risk summaries, vendor risk, reconciliation risk, entity relationship risk, aggregate risk, pending recommendations, and behavioral baseline scoring.
- `BrevixAgentRunner` advertises deterministic tool metadata with `requires_business_profile_context` and `business_profile_header`.
- Rex alert, suspicious transaction, case, alert recommendation, case recommendation, transaction, and dashboard-health routes pass profile context into profile-aware services.
- Vendor, reconciliation, entity relationship, aggregate, behavioral baseline, and top-level agent risk scoring accept optional `businessProfileId` and apply profile filters when profile columns exist.
- Alert and case recommendation generation now computes underlying risk inputs from the active profile and persists profile IDs on recommendation records.
- Recommendation review audit history returns `business_profile_id`; case recommendation approval records profile metadata on investigation activity/evidence entries.
- Alert listing safely skips `priority_score` ordering/updates when partial schemas do not include the column.

### Regression Coverage

- Internal agent tool transaction detail is profile-scoped.
- Internal pending recommendations requires a profile for ambiguous multi-profile workspaces and propagates the selected profile into alert/case recommendation services.
- Agent deterministic tool metadata includes the business profile requirement.
- Rex alert-route artifacts are profile-scoped.
- Alert and case recommendation approvals cannot cross the active business profile context.

## Verification

- `vendor/bin/pint ...` on touched services and feature tests: passed.
- Focused profile-context suite: `72 passed, 369 assertions`.
- Broader agent/risk/recommendation suite: `134 passed, 714 assertions`.
- Full Laravel suite: `396 passed, 2025 assertions`.
