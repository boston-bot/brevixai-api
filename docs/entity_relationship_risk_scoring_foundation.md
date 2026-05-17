# Deterministic Entity Relationship Risk Scoring Foundation

This document defines the architecture, rules, and scoring philosophy for Phase 3.2: **Deterministic Entity Relationship Risk Scoring Foundation** in BrevixAI. This component detects employee/vendor overlaps, duplicate vendor spelling clusters, shared contact details, and unusual concentration in related entities, returning explainable findings directly to the LangGraph agent orchestrator.

---

## 1. Scoring Philosophy

Like Phases 3.0 and 3.1, the Entity Relationship Risk Scoring engine is **100% deterministic and rule-based**. It translates complex relationship status data into concrete quantitative risk ratings without relying on non-deterministic LLM reasoning or direct database connections from external agents.

### Risk Classifications
Risk scores range from `0` to `100` (capped), categorizing companies into four qualitative risk levels:
*   🔴 **Critical Risk** (Score $\ge 90$): High probability of conflict of interest, self-dealing, or shell vendor payment routing. Requires immediate conflict of interest audit.
*   🟡 **High Risk** (Score $70$ to $89$): Multiple unresolved banking or address overlaps suggesting potentially related internal accounts.
*   🟢 **Medium Risk** (Score $40$ to $69$): Duplicate vendor profiles or moderate-priority overlaps requiring vendor record consolidation.
*   🔵 **Low Risk** (Score $< 40$): Safe parameters. Minimal duplicate spellings or overlaps.

---

## 2. Deterministic Risk Rules & Casing Rules

The engine evaluates **seven distinct rule categories**, each associated with a risk weight:

| Rule Key | Rule Name | Weight | Triggering Logic & Calculation |
| :--- | :--- | :---: | :--- |
| `employee_vendor_overlap` | Employee/Vendor Overlap | **20** | Triggered if any user first/last name combination or email matches/is a substring of any `vendor_customer` in `transactions`. |
| `shared_bank_account` | Shared Bank Accounts | **20** | Triggered if any active unresolved alert has `rule_key` = `'shared_bank_account'` or has details indicating shared bank routing details. |
| `shared_address` | Shared Addresses | **15** | Triggered if any active unresolved alert has `rule_key` = `'shared_address'` or indicates shared physical address registration. |
| `shared_phone_email` | Shared Phone/Email | **10** | Triggered if any active unresolved alert has `rule_key` = `'shared_phone_email'` or indicates shared telephone/domain contacts. |
| `duplicate_vendor_cluster` | Duplicate Vendor Spelling Clusters | **15** | Triggered if any unique vendor names share a spelling similarity with Levenshtein distance 1 to 3, excluding common public terms (Google, AWS, Microsoft, Zoom, etc.). |
| `vendor_vendor_payment` | Vendor-to-Vendor Payments | **10** | Triggered if any active unresolved alert indicates transactions routed directly between vendor accounts. |
| `unusual_concentration` | spend concentration | **10** | Triggered if the overall company spend on any duplicate spelling cluster exceeds **15%** of the company total overall spend. |

> [!NOTE]
> The final risk score is calculated as the sum of all triggered rule weights, capped at a maximum of `100`.

---

## 3. API Specification

The protected internal agent tool endpoint is:

```http
GET /api/internal/agent-tools/company/{companyId}/entity-relationship-risk
```

### Headers Required
*   `Authorization: Bearer <agent_api_key>`
*   `X-Brevix-User-Id: <user_uuid>`

### Response Schema

```json
{
  "company_id": "11111111-1111-4111-8111-111111111111",
  "entity_relationship_risk_score": 25,
  "risk_level": "low",
  "triggered_rules": [
    {
      "rule_key": "duplicate_vendor_cluster",
      "name": "Duplicate Vendor Identity Clusters",
      "weight": 15,
      "explanation": "Identified closely misspelled or duplicate vendor accounts that may represent split profiles."
    },
    {
      "rule_key": "unusual_concentration",
      "name": "Concentration in Related Entities",
      "weight": 10,
      "explanation": "Concentration of overall company spend in closely spelling-similar vendor clusters exceeds the 15% threshold."
    }
  ],
  "rule_weights": {
    "employee_vendor_overlap": 20,
    "shared_bank_account": 20,
    "shared_address": 15,
    "shared_phone_email": 10,
    "duplicate_vendor_cluster": 15,
    "vendor_vendor_payment": 10,
    "unusual_concentration": 10
  },
  "supporting_evidence": {
    "duplicate_vendor_cluster": {
      "clusters": [
        [
          "Northstar Consulting",
          "Northstr Consulting"
        ]
      ]
    },
    "unusual_concentration": {
      "concentration_alerts": [
        {
          "cluster": [
            "Northstar Consulting",
            "Northstr Consulting"
          ],
          "spend": 6000.0,
          "percentage": 100.0
        }
      ]
    }
  },
  "related_entities": [
    {
      "type": "duplicate_vendor_identity",
      "entities": [
        "Northstar Consulting",
        "Northstr Consulting"
      ],
      "description": "Vendors identified as part of a single identity cluster due to close spelling similarity."
    }
  ],
  "recommended_next_action": "Merge duplicate vendor records and trace the ultimate beneficial ownership of the related entity clusters."
}
```

---

## 4. LangGraph Agent Workflow Integration

The tool has been integrated into the existing Python LangGraph agent orchestrator as an optional deterministic tool. During graph execution, the `fraud_analyzer_node` queries this endpoint. If the company exhibits a high entity relationship risk (score $\ge 40$), the orchestrator automatically appends a detailed `AgentFinding` to the output state, providing users with a comprehensive, explainable entity warning alongside standard vendor and reconciliation anomalies.
