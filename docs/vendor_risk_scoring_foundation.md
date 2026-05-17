# Deterministic Vendor Risk Scoring Foundation

This document defines the architecture, rules, and scoring philosophy for Phase 3.0: **Deterministic Vendor Risk Scoring Foundation** in BrevixAI. This component calculates high-fidelity risk profiles for vendors based strictly on historical transaction ledgers and system metadata, avoiding non-deterministic LLM reasoning or direct agent database access.

---

## 1. Scoring Philosophy

The BrevixAI Vendor Risk Scoring service uses a **strictly deterministic, rule-based approach** to calculate risk scores. The design is based on the following key principles:

*   **Deterministic Integrity**: No LLM reasoning or speculative logic is used during the score calculation phase. Scoring the same dataset multiple times will always yield the exact same score, rule triggers, and supporting evidence.
*   **Defense-in-Depth Risk Classification**: Each rule represents a well-known fraud pattern or financial anomaly. Risk scores range from `0` to `100` (capped), grouping vendors into four qualitative levels:
    *   🔴 **Critical Risk** (Score $\ge 90$): Severe, multi-rule violations requiring immediate transaction holds and forensic audits.
    *   🟡 **High Risk** (Score $70$ to $89$): Multiple medium-priority violations requiring supervisor manual review.
    *   🟢 **Medium Risk** (Score $40$ to $69$): Single moderate-priority violation or minor pattern requiring periodic routine audit flags.
    *   🔵 **Low Risk** (Score $< 40$): Routine B2B relationships requiring only continuous automated monitoring.
*   **Explainable & Actionable Evidence**: Rather than returning a black-box percentage, the scoring engine provides comprehensive supporting evidence, showing exactly *which* transactions, dates, and amounts triggered which rules, as well as a concrete recommended next action.

---

## 2. Deterministic Risk Rules & Casing Rules

The scoring engine evaluates **eight deterministic rule categories**, each associated with a designated risk weight:

| Rule Key | Rule Name | Weight | Triggering Logic & Calculation |
| :--- | :--- | :---: | :--- |
| `new_vendor` | New Vendor Risk | **15** | Triggered if the vendor's first transaction date is within **30 days** of the company's overall latest transaction date. |
| `vendor_concentration` | Vendor Concentration Risk | **20** | Triggered if the vendor's total spend represents $\ge 25\%$ of the company's total spend across all vendors. |
| `rapid_payment` | Rapid Payment after Onboarding | **15** | Triggered if the vendor receives a transaction of **$\ge \$2,500.00$** within **7 days** of their very first appearance in the ledger. |
| `similar_vendor_name` | Duplicate/Similar Vendor Names | **15** | Triggered if another unique vendor has a name within a Levenshtein distance of **$1$ to $3$** (ignoring case), indicating a potential duplicate setup. |
| `round_dollar` | Round-Dollar Payment Patterns | **15** | Triggered if a vendor has **$\ge 2$ transactions** and $\ge 50\%$ of those transactions have round-dollar amounts (multiples of $\$100.00$). |
| `threshold_splitting` | Threshold Splitting Behavior | **20** | Triggered if multiple payments just below the $\$5,000.00$ approval limit (defined as $\$4,000.00$ to $\$4,999.99$) occur within a **5-day window** of each other. |
| `unusual_timing` | Unusual Payment Timing | **10** | Triggered if any transaction for the vendor is processed on a weekend (Saturday or Sunday), which is anomalous for normal B2B spend. |
| `shared_payment_indicators`| Shared Payment/Account Indicators | **15** | Triggered if there is an active, open system alert containing this vendor's name, indicating shared bank accounts, billing info, or reconciliation mismatches. |

> [!NOTE]
> The final risk score is calculated as the sum of all triggered rule weights, capped at a maximum of `100`.

---

## 3. API Specification

The protected internal agent tool endpoint is:

```http
GET /api/internal/agent-tools/company/{companyId}/vendor-risk
```

### Authentication & Authorization
*   Requires a valid bearer agent tool API token (`Authorization: Bearer <token>`).
*   Requires `X-Brevix-User-Id` header to authorize that the requesting user belongs to the target company.

### Query Parameters
*   `vendor` *(string, optional)*: If provided, returns a detailed risk breakdown for this specific vendor name. If omitted, returns an ordered list of risk scores for all unique vendors of the company (highest risk first).

---

## 4. Why Scoring is Deterministic

1.  **Repeatability and Auditability**: Audit firms and regulators require that fraud risk models be predictable. Non-deterministic models (like raw LLM prompts) are subject to prompt drift, model updates, and hallucinations, which make them indefensible in legal or compliance audits.
2.  **Performance and Scalability**: Calculating Levenshtein distances, datetime differences, and concentration percentages using standard SQL and PHP takes milliseconds. LLM generation for large ledgers takes seconds to minutes and incurs significant token costs.
3.  **Harnessing Specialist Agents**: By keeping the tool output deterministic, the LangGraph LLM planner acts as a strict "Reviewer" or "Explainer", relying on verified ground-truth math for its forensic writeups.

---

## 5. Current Limitations

*   **Vendor Entity Resolution**: Currently, similarity checks are based on Levenshtein string matching of the `vendor_customer` text field. More advanced entity resolution (matching "Acme, Inc." with "Acme Inc") could be added in future phases.
*   **Static Thresholds**: The split transaction threshold is hardcoded to $\$5,000.00$ (with scanning ranges between $\$4,000.00$ and $\$4,999.99$). Future revisions could support dynamic company-specific control thresholds retrieved from the `control_definitions` table.
