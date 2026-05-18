# Deterministic Aggregate Risk Summary Service

This document defines the architecture, rules, and scoring philosophy for Phase 3.3: **Deterministic Aggregate Risk Summary Service** in BrevixAI. This component combines domain-specific risks—vendor risk, reconciliation risk, and entity relationship risk—into a single quantitative overall score, returning explainable findings directly to the LangGraph agent orchestrator.

---

## 1. Combining Logic & Scoring Philosophy

Like all foundational risk services in BrevixAI, the Aggregate Risk Summary engine is **100% deterministic and rule-based**. It operates entirely on active ledger states and computed domain scores, rejecting any non-deterministic LLM calculations.

### Score Aggregation
The overall score is computed as the **mathematical maximum** of the three underlying domain scores:

$$\text{overall\_risk\_score} = \max(\text{vendor\_risk\_score}, \text{reconciliation\_risk\_score}, \text{entity\_relationship\_risk\_score})$$

This approach guarantees that if any single audit domain exhibits Critical risk (e.g. $\ge 90$), the entire company is correctly elevated to Critical status without being artificially dragged down by averages or weight dilutions.

### Risk Level Mapping
The overall risk level maps as follows:
*   🔴 **Critical Risk** (Score $\ge 90$): High probability of conflict of interest, bank-to-ledger mismatches, or threshold splitting.
*   🟡 **High Risk** (Score $70$ to $89$): Multiple unresolved banking anomalies or moderate-priority overlaps.
*   🟢 **Medium Risk** (Score $40$ to $69$): Duplicate vendor profiles or moderate-priority discrepancies.
*   🔵 **Low Risk** (Score $< 40$): Safe parameters across all audit domains.

---

## 2. API Specification

### Endpoint 1: Pure Aggregate Summary (New)
*   **Method**: `GET`
*   **Path**: `/api/internal/agent-tools/company/{companyId}/aggregate-risk-summary`
*   **Headers**:
    *   `Authorization: Bearer <agent_api_key>`
    *   `X-Brevix-User-Id: <user_uuid>`

### Endpoint 2: Unified Risk Summary (Backward Compatible)
*   **Method**: `GET`
*   **Path**: `/api/internal/agent-tools/companies/{companyId}/risk-summary`
*   Includes all existing response fields (`risk_score`, `stats`, `top_drivers`) and embeds this aggregate output nested under the `aggregate_summary` key.

### Response Schema

```json
{
  "company_id": "11111111-1111-4111-8111-111111111111",
  "overall_risk_score": 60,
  "overall_risk_level": "medium",
  "contributing_risk_domains": {
    "vendor_risk": {
      "score": 60,
      "risk_level": "medium"
    },
    "reconciliation_risk": {
      "score": 0,
      "risk_level": "low"
    },
    "entity_relationship_risk": {
      "score": 20,
      "risk_level": "low"
    }
  },
  "highest_risk_findings": [
    {
      "domain": "vendor_risk",
      "source": "New Vendor Office Supplies",
      "rule_key": "vendor_concentration",
      "name": "Vendor Concentration Risk",
      "weight": 20,
      "explanation": "Vendor spend of $2,500.00 represents 83.3% of total company spend ($3,000.00)."
    },
    {
      "domain": "entity_relationship_risk",
      "source": "Entity Graph / Metadata",
      "rule_key": "employee_vendor_overlap",
      "name": "Employee/Vendor Overlap",
      "weight": 20,
      "explanation": "Identified employee 'Test User' whose name matches/is a substring of vendor 'Test User Services'."
    }
  ],
  "triggered_rules_summary": {
    "vendor_risk": 4,
    "reconciliation_risk": 0,
    "entity_relationship_risk": 1
  },
  "recommended_next_actions": [
    "Merge duplicate vendor spelling profiles and perform employee-vendor relationship conflict review."
  ],
  "supporting_evidence_summary": {
    "vendor_risk": {
      "total_vendors_analyzed": 2,
      "flagged_vendors": 1
    },
    "reconciliation_risk": {
      "triggered_anomalies": 0,
      "stale_unreconciled_items_count": 0
    },
    "entity_relationship_risk": {
      "overlapping_employees_count": 1,
      "duplicate_vendor_identity_clusters_count": 0
    }
  }
}
```

---

## 3. LangGraph Workflow Orchestration

During execution, the LangGraph `fraud_analyzer_node` asynchronously fires tool queries to gather domain data. It requests the `aggregate_risk_summary` tool client. It records a structured `step` inside the agent history reflecting the `overall_risk_score` and `overall_risk_level`, and embeds the full detailed aggregate payload into `tool_results`, ensuring maximum explainability for the human approval and LLM explanation steps.
