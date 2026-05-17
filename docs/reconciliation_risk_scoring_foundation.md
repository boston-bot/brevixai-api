# Deterministic Reconciliation Risk Scoring Foundation

This document defines the architecture, rules, and scoring philosophy for Phase 3.1: **Deterministic Reconciliation Risk Scoring Foundation** in BrevixAI. This component detects bank-to-ledger anomalies, mismatches, and manual adjustments, returning explainable findings directly to the LangGraph agent orchestrator.

---

## 1. Scoring Philosophy

Like Phase 3.0, the Reconciliation Risk Scoring engine is **100% deterministic and rule-based**. It translates complex reconciliation status data into concrete quantitative risk ratings without relying on non-deterministic LLM reasoning or direct database connections from external agents.

### Risk Classifications
Risk scores range from `0` to `100` (capped), categorizing companies into four qualitative risk levels:
*   🔴 **Critical Risk** (Score $\ge 90$): High probability of bookkeeping manipulation, cash leakage, or cover-up adjustments. Requires immediate lock of manual journal posting and forensic audit.
*   🟡 **High Risk** (Score $70$ to $89$): Multiple unresolved cash differences or stale items requiring a formal monthly close review.
*   🟢 **Medium Risk** (Score $40$ to $69$): Single moderate-priority variance or duplicate bookkeeping entries requiring simple correction.
*   🔵 **Low Risk** (Score $< 40$): Minimal discrepancies. Standard operational B2B matching variances.

---

## 2. Deterministic Risk Rules & Casing Rules

The engine evaluates **seven distinct rule categories**, each associated with a risk weight:

| Rule Key | Rule Name | Weight | Triggering Logic & Calculation |
| :--- | :--- | :---: | :--- |
| `bank_ledger_mismatch` | Bank-to-Ledger Mismatches | **15** | Triggered if any unresolved discrepancy has category `missing_from_books` or reason code `bank_transaction_without_ledger_match` or `reconciliation_mismatch`. |
| `unmatched_deposits` | Unmatched Deposits | **20** | Triggered if any bank-to-ledger mismatch is a bank deposit (amount $> 0$ or type `deposit`). |
| `unmatched_withdrawals` | Unmatched Withdrawals | **20** | Triggered if any bank-to-ledger mismatch is a bank withdrawal (amount $\le 0$ or type `expense`/`withdrawal`/`payment`). |
| `duplicate_ledger` | Duplicate Ledger Entries | **15** | Triggered if any unresolved discrepancy has category `duplicate_ledger` or reason code `duplicate_ledger_entry`. |
| `stale_unreconciled` | Stale Unreconciled Items | **15** | Triggered if any unresolved discrepancy has category `stale_unreconciled` or reason `stale_unreconciled_item`, or has an item date older than **30 days** relative to the latest transaction in the ledger. |
| `amount_date_variance` | Amount/Date Variance | **10** | Triggered if any unresolved discrepancy has category `amount_mismatch` / `date_mismatch` or reason code `amount_variance` / `date_variance`. |
| `suspicious_manual_adjustment`| Suspicious Manual Adjustments | **15** | Triggered if any discrepancy category is `manual_adjustment` or reason is `suspicious_manual_adjustment`, or if any transaction's memo contains terms like `"adj"`, `"adjustment"`, `"force"`, or `"write-off"`. |

> [!NOTE]
> The final risk score is calculated as the sum of all triggered rule weights, capped at a maximum of `100`.

---

## 3. API Specification

The protected internal agent tool endpoint is:

```http
GET /api/internal/agent-tools/company/{companyId}/reconciliation-risk
```

### Headers Required
*   `Authorization: Bearer <agent_api_key>`
*   `X-Brevix-User-Id: <user_uuid>`

### Response Schema

```json
{
  "company_id": "11111111-1111-4111-8111-111111111111",
  "reconciliation_risk_score": 35,
  "risk_level": "low",
  "triggered_rules": [
    {
      "rule_key": "bank_ledger_mismatch",
      "name": "Bank-to-Ledger Mismatches",
      "weight": 15,
      "explanation": "Unmatched transactions present on bank statements but missing from the internal ledger."
    },
    {
      "rule_key": "unmatched_deposits",
      "name": "Unmatched Deposits",
      "weight": 20,
      "explanation": "Deposits received in the bank account that could not be matched to internal records."
    }
  ],
  "rule_weights": {
    "bank_ledger_mismatch": 15,
    "unmatched_deposits": 20,
    "unmatched_withdrawals": 20,
    "duplicate_ledger": 15,
    "stale_unreconciled": 15,
    "amount_date_variance": 10,
    "suspicious_manual_adjustment": 15
  },
  "supporting_evidence": {
    "bank_ledger_mismatch": {
      "discrepancies": [
        {
          "id": "88888888-3333-4888-8888-888888888883",
          "amount": 5000.00,
          "category": "missing_from_books",
          "reason_code": "bank_transaction_without_ledger_match"
        }
      ]
    },
    "unmatched_deposits": {
      "discrepancies": [
        {
          "id": "88888888-3333-4888-8888-888888888883",
          "amount": 5000.00,
          "category": "missing_from_books",
          "reason_code": "bank_transaction_without_ledger_match"
        }
      ]
    }
  },
  "recommended_next_action": "Investigate duplicate bookkeeping entries and match the identified deposits/withdrawals."
}
```

---

## 4. LangGraph Agent Workflow Integration

The tool has been integrated into the existing Python LangGraph agent orchestrator as an optional deterministic tool. During graph execution, the `fraud_analyzer_node` queries this endpoint. If the company exhibits a high reconciliation risk (score $\ge 40$), the orchestrator automatically appends a detailed `AgentFinding` to the output state, providing users with a comprehensive, explainable cash control warning alongside standard vendor anomalies.
