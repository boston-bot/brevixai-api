# Brevix Fraud Testing Framework
## test-spec.md

---

# Purpose

This document defines the Brevix fraud-testing framework used to validate fraud detection, anomaly detection, bookkeeping analysis, and future AI-driven investigative workflows.

The goal is to create a repeatable, measurable, and scalable testing environment that uses realistic accounting fraud scenarios rather than ad hoc manually created examples.

---

# Objectives

Brevix must be capable of detecting:

- Vendor fraud
- Shell company fraud
- Payroll fraud
- Ghost employees
- Expense reimbursement fraud
- Revenue manipulation
- Inventory theft
- Tax-related fraud indicators
- Internal control weaknesses
- Suspicious bookkeeping activity

The testing framework should allow the team to:

1. Create realistic company books.
2. Inject known fraud patterns.
3. Run Brevix analysis.
4. Compare actual findings against expected findings.
5. Produce detection scores.
6. Track improvements over time.

---

# Team Responsibilities

## Developer A

Primary Responsibility:

Fraud Research & Scenario Design

Tasks:

- Research ACFE fraud schemes
- Research IRS Criminal Investigation cases
- Research DOJ fraud cases
- Research Inspector General reports
- Research SEC accounting enforcement cases

Deliverables:

- Scenario documentation
- Expected detection indicators
- Severity classifications
- Fraud category assignments

---

## Developer B

Primary Responsibility:

Scenario Implementation & Validation

Tasks:

- Build QuickBooks sandbox companies
- Import transactions
- Create vendors
- Create employees
- Create invoices
- Create journal entries
- Simulate fraud activity

Deliverables:

- Completed sandbox environments
- Test execution reports
- Detection validation results

---

# QuickBooks Sandbox Strategy

Current limitation:

- QuickBooks Developer accounts support 10 sandbox companies.

Initial allocation:

## Developer A Sandboxes

- Company 001 - Clean Books
- Company 002 - Payroll Fraud
- Company 003 - Expense Fraud
- Company 004 - Ghost Employee
- Company 005 - Tax Issues

## Developer B Sandboxes

- Company 006 - Vendor Fraud
- Company 007 - Shell Vendor
- Company 008 - Revenue Manipulation
- Company 009 - Inventory Theft
- Company 010 - Mixed Fraud

---

# Phase 1: Fraud Scenario Library

Create database table:

```sql
CREATE TABLE fraud_scenarios (
    id UUID PRIMARY KEY,
    category VARCHAR(100),
    name VARCHAR(255),
    severity VARCHAR(50),
    source VARCHAR(255),
    description TEXT,
    indicators JSONB,
    expected_findings JSONB,
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

# Fraud Categories

1. Clean Books
2. Vendor Fraud
3. Shell Vendor Fraud
4. Payroll Fraud
5. Ghost Employees
6. Expense Reimbursement Fraud
7. Revenue Manipulation
8. Inventory Theft
9. Tax Risk
10. Mixed Fraud

---

# Phase 2: Scenario Templates

Store YAML templates under:

/brevixai-agents/scenarios/

Example:

```yaml
scenario_id: ghost_employee_001
industry: construction
employees: 25
ghost_employees: 2
months_of_activity: 12
```
---

# Phase 3: Automated Scenario Generation

Generate:

- Vendors
- Customers
- Employees
- Bills
- Invoices
- Payroll transactions
- Journal entries

Targets:

- QuickBooks API
- CSV Imports
- Postgres Direct Loads

---

# Phase 4: Detection Scoring

```sql
CREATE TABLE fraud_test_results (
    id UUID PRIMARY KEY,
    scenario_id UUID,
    expected_findings JSONB,
    actual_findings JSONB,
    detection_score NUMERIC,
    created_at TIMESTAMP
);
```
---

# Long-Term Vision

The Brevix Fraud Scenario Library becomes a proprietary asset that supports:

- Product testing
- AI model evaluation
- MCP intelligence services
- Customer demonstrations
- Fraud benchmarking
- Regression testing
