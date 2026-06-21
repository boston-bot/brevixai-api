# Legacy Migration Ledger
**Date:** 2026-06-21

This ledger tracks the migration of legacy alerts, cases, and recommendations to the canonical redesign path (`Investigation`, `Finding`, `EvidenceItem`, `SuggestedRecord`, `ReviewerNote`, `ReviewEvent`, and `CasePackage`).

| Legacy Entity | Owner | Callers | Compatibility Duration | Cutover Condition | Removal Test |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `Alert` | Backend Team | Legacy dashboard, Rex alerts API | Until canonical findings cover all alert rules. | All active alert generation migrates to `FindingService`. | Run test suite with `Alert` model deleted. |
| `AuditCase` | Backend Team | Legacy cases API, Action Plan UI | Until `CasePackage` supports all legacy outputs. | All open `AuditCase` instances migrated to `Investigation` records. | `CaseController` routes and tests deleted. |
| `Recommendation` | Backend Team | Legacy recommendation approvals API | Until review actions on canonical findings reach feature parity. | Approvals API safely migrates to generic Finding/Investigation review actions. | `CaseRecommendationController` deleted. |
| `Legacy Investigation Responses` | Backend Team | Rex tools, `brevixai-agents` | Until the normalized investigation APIs are consumed. | Frontend and Agent adapters consume canonical APIs exclusively. | Direct route-specific payloads no longer returned from the API. |
