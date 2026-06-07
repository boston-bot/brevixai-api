# Brevix Fraud Scenario Workflow — Developer Build Plan

## File Purpose

This document gives Codex / Claude Code / engineering agents the implementation plan for building the Brevix fraud scenario workflow across two repositories:

- `brevixai-api` — Laravel/PHP backend
- `brevixai-agents` — Python agents, scenario extraction, mock-data generation, and future QuickBooks sandbox population

The business goal is to allow a non-technical fraud/intelligence contributor to write natural-language fraud scenarios in Excel. Brevix will then convert those scenarios into structured records, synthetic mock accounting data, expected findings, and testable fraud detection fixtures.

---

# 1. Product Goal

Brevix needs a repeatable fraud testing pipeline.

The pipeline should:

1. Accept an Excel workbook containing fraud scenario narratives.
2. Import those rows into the Laravel backend.
3. Store the scenario submissions in Postgres.
4. Send unprocessed scenarios to the Python agents repo.
5. Use an LLM extraction workflow to turn narratives into structured fraud scenario objects.
6. Generate mock company data from the structured scenario.
7. Store the generated mock data and expected findings.
8. Optionally export generated data for:
   - QuickBooks sandbox creation
   - CSV import
   - direct Postgres seeding
   - Brevix regression testing
9. Compare Brevix findings against expected findings later.

This is not intended to be a perfect fraud simulation engine on day one. The first working version should create a durable foundation.

---

# 2. Repository Responsibilities

## 2.1 `brevixai-api` — Laravel Backend

The Laravel backend should own:

- File upload endpoint for Excel scenario workbook
- Import validation
- Scenario submission persistence
- Processing status tracking
- API endpoints for agents to fetch pending scenarios
- API endpoints for agents to write extracted scenario data
- API endpoints for generated mock data
- Admin/review endpoints for human review
- Database migrations
- Models
- Jobs
- Tests

Laravel should be the source of truth for scenario records.

## 2.2 `brevixai-agents` — Python Agents Repo

The Python repo should own:

- Scenario narrative extraction
- Fraud pattern classification
- Structured scenario generation
- Expected indicator generation
- Expected finding generation
- Mock accounting data generation
- Optional QuickBooks payload generation
- Validation of generated mock data
- Sending structured output back to Laravel

The agents repo should not be the source of truth. It should process scenarios and return structured outputs.

---

# 3. End-to-End Workflow

## Step 1 — Human Contributor Creates Excel Workbook

The non-technical contributor fills in the Excel workbook with columns such as:

- Scenario ID
- Title
- Narrative
- Source
- Severity
- Status

The most important field is `Narrative`.

Example:

```text
A payroll manager created a fictitious employee and added the employee to payroll. The employee received direct deposits for approximately 18 months. The fraud was concealed by creating minimal employee records and routing payments through a bank account associated with a relative. The issue was discovered during a review of payroll records and personnel files. Important records included payroll registers, personnel files, direct deposit authorizations, and bank statements. Investigators asked who approved the employee and whether employment could be verified.
```

## Step 2 — Laravel Imports Workbook

Laravel imports the Excel file and creates one `fraud_scenario_submission` row per scenario.

## Step 3 — Laravel Marks Scenario as Pending Extraction

Imported rows should begin with:

```text
status = imported
extraction_status = pending
mock_data_status = pending
```

## Step 4 — Agents Fetch Pending Scenarios

The Python agents repo calls Laravel:

```http
GET /api/internal/fraud-scenarios/pending
```

Laravel returns unprocessed scenarios.

## Step 5 — Agents Extract Structured Scenario

The Python workflow converts the natural language narrative into structured JSON.

## Step 6 — Agents Generate Mock Data

The Python workflow generates synthetic but realistic business data:

- company profile
- employees
- vendors
- customers
- accounts
- transactions
- documents needed
- expected indicators
- expected findings

## Step 7 — Agents Save Output Back To Laravel

The Python repo posts structured results back:

```http
POST /api/internal/fraud-scenarios/{id}/extraction
POST /api/internal/fraud-scenarios/{id}/mock-data
```

## Step 8 — Laravel Stores Output

Laravel stores extracted scenario structure and mock data in normalized tables and JSON columns.

## Step 9 — Future Test Runner Uses Data

Later workflows can use these records to:

- generate QuickBooks sandbox data
- seed local Postgres test datasets
- run Brevix analysis
- compare actual findings to expected findings
- produce detection scores

---

# 4. Laravel Backend Build Plan: `brevixai-api`

## 4.1 Suggested Namespace

Use a dedicated namespace/module:

```text
app/
  Models/FraudTesting/
  Http/Controllers/FraudTesting/
  Jobs/FraudTesting/
  Services/FraudTesting/
  Imports/FraudTesting/
  DTOs/FraudTesting/
```

Routes:

```text
routes/api.php
```

Use route prefix:

```text
/api/internal/fraud-testing
```

or:

```text
/api/internal/fraud-scenarios
```

---

# 5. Laravel Database Migrations

Create a dedicated schema if the project is already using schemas.

Recommended schema:

```text
fraud_testing
```

If schemas are not currently used in the Laravel app, table prefixes are acceptable:

```text
fraud_scenario_submissions
fraud_scenario_extractions
fraud_mock_companies
```

## 5.1 Table: `fraud_scenario_imports`

Tracks each uploaded Excel import.

Columns:

```text
id UUID primary key
original_filename string nullable
storage_path string nullable
uploaded_by_id UUID nullable
status string default 'uploaded'
total_rows integer default 0
successful_rows integer default 0
failed_rows integer default 0
validation_errors jsonb nullable
started_at timestamp nullable
completed_at timestamp nullable
created_at timestamp
updated_at timestamp
```

Allowed statuses:

```text
uploaded
processing
completed
completed_with_errors
failed
```

## 5.2 Table: `fraud_scenario_submissions`

Stores the raw human-authored scenario.

Columns:

```text
id UUID primary key
import_id UUID nullable foreign key
external_scenario_id string nullable
title string
narrative text
source string nullable
severity string nullable
status string default 'imported'
extraction_status string default 'pending'
mock_data_status string default 'pending'
review_status string default 'unreviewed'
row_number integer nullable
raw_row jsonb nullable
created_at timestamp
updated_at timestamp
```

Important notes:

- `external_scenario_id` is the ID from the Excel sheet, such as `PAYROLL-001`.
- `id` is the internal UUID.
- Preserve the raw Excel row in `raw_row`.
- Do not discard incomplete rows without logging import errors.

Allowed `status` values:

```text
imported
queued
processing
processed
failed
archived
```

Allowed `extraction_status` values:

```text
pending
processing
completed
failed
needs_review
```

Allowed `mock_data_status` values:

```text
pending
processing
completed
failed
needs_review
```

Allowed `review_status` values:

```text
unreviewed
approved
rejected
needs_revision
```

## 5.3 Table: `fraud_scenario_extractions`

Stores structured extraction from the narrative.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
fraud_category string nullable
industry string nullable
actor_type string nullable
concealment_method string nullable
summary text nullable
structured_payload jsonb not null
confidence_score numeric nullable
model_name string nullable
prompt_version string nullable
extraction_errors jsonb nullable
created_at timestamp
updated_at timestamp
```

`structured_payload` should contain the full extracted object.

Example:

```json
{
  "scenario_title": "Ghost Employee Paid Through Relative Bank Account",
  "fraud_category": "Payroll Fraud",
  "industry": "Construction",
  "primary_actor": "Payroll Manager",
  "concealment_methods": [
    "Minimal employee file",
    "Relative bank account",
    "Lack of independent payroll review"
  ],
  "red_flags": [
    "Employee lacks personnel file",
    "Direct deposit account linked to related party",
    "No verifiable employment activity"
  ],
  "records_needed": [
    "Payroll register",
    "Personnel file",
    "Direct deposit authorization",
    "Bank statements"
  ],
  "investigation_questions": [
    "Who approved the employee?",
    "Can employment be verified?",
    "Who controls the direct deposit account?"
  ]
}
```

## 5.4 Table: `fraud_expected_indicators`

One row per expected red flag / signal.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
indicator_key string
indicator_name string
indicator_category string nullable
description text nullable
severity string nullable
data_needed jsonb nullable
should_detect boolean default true
created_at timestamp
updated_at timestamp
```

Examples:

```text
duplicate_bank_account
missing_personnel_file
unusual_payroll_growth
vendor_address_matches_employee
payments_below_approval_threshold
```

## 5.5 Table: `fraud_expected_findings`

One row per expected Brevix finding.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
finding_key string
finding_title string
finding_description text
expected_risk_score integer nullable
expected_confidence string nullable
recommended_action text nullable
expected_user_message text nullable
created_at timestamp
updated_at timestamp
```

Example:

```text
finding_key: potential_ghost_employee
finding_title: Potential Ghost Employee
finding_description: Employee receives payroll but lacks supporting employment records and may be connected to another party.
expected_risk_score: 90
expected_confidence: High
recommended_action: Request personnel file, direct deposit authorization, payroll register, and hiring approval.
```

## 5.6 Table: `fraud_mock_companies`

Stores generated synthetic company profile.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
company_name string
industry string nullable
entity_type string nullable
annual_revenue numeric nullable
employee_count integer nullable
vendor_count integer nullable
customer_count integer nullable
months_of_activity integer nullable
profile_payload jsonb not null
created_at timestamp
updated_at timestamp
```

## 5.7 Table: `fraud_mock_parties`

Stores generated parties.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
mock_company_id UUID foreign key nullable
external_party_id string nullable
party_type string
party_name string
role string nullable
is_fraud_actor boolean default false
is_related_party boolean default false
attributes jsonb nullable
created_at timestamp
updated_at timestamp
```

Party types:

```text
Employee
Vendor
Customer
Owner
Bookkeeper
Payroll Manager
AP Clerk
Related Party
Unknown
```

## 5.8 Table: `fraud_mock_transactions`

Stores generated mock accounting transactions.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
mock_company_id UUID foreign key nullable
external_transaction_id string nullable
transaction_type string
transaction_date date nullable
amount numeric nullable
party_id UUID nullable
account_category string nullable
description text nullable
is_fraudulent boolean default false
fraud_pattern string nullable
expected_brevix_signal string nullable
payload jsonb nullable
created_at timestamp
updated_at timestamp
```

Transaction types:

```text
Bill
Bill Payment
Invoice
Payment
Journal Entry
Payroll Payment
Expense
Check
Credit Card Charge
Vendor Credit
Customer Refund
Deposit
Owner Draw
```

## 5.9 Table: `fraud_document_requests`

Documents a real investigator would request.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
document_name string
why_needed text nullable
priority string nullable
expected_issue_found text nullable
created_at timestamp
updated_at timestamp
```

## 5.10 Table: `fraud_investigation_questions`

Questions Brevix should ask.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
question text
asked_to string nullable
why_question_matters text nullable
priority string nullable
created_at timestamp
updated_at timestamp
```

## 5.11 Table: `fraud_generation_runs`

Tracks agent runs.

Columns:

```text
id UUID primary key
scenario_submission_id UUID foreign key
run_type string
status string
started_at timestamp nullable
completed_at timestamp nullable
input_payload jsonb nullable
output_payload jsonb nullable
errors jsonb nullable
created_at timestamp
updated_at timestamp
```

Run types:

```text
extraction
mock_data_generation
quickbooks_payload_generation
regression_test
```

Statuses:

```text
pending
running
completed
failed
needs_review
```

---

# 6. Laravel Models

Create models:

```text
App\Models\FraudTesting\FraudScenarioImport
App\Models\FraudTesting\FraudScenarioSubmission
App\Models\FraudTesting\FraudScenarioExtraction
App\Models\FraudTesting\FraudExpectedIndicator
App\Models\FraudTesting\FraudExpectedFinding
App\Models\FraudTesting\FraudMockCompany
App\Models\FraudTesting\FraudMockParty
App\Models\FraudTesting\FraudMockTransaction
App\Models\FraudTesting\FraudDocumentRequest
App\Models\FraudTesting\FraudInvestigationQuestion
App\Models\FraudTesting\FraudGenerationRun
```

Relationships:

```text
FraudScenarioImport hasMany FraudScenarioSubmission
FraudScenarioSubmission belongsTo FraudScenarioImport
FraudScenarioSubmission hasOne FraudScenarioExtraction
FraudScenarioSubmission hasMany FraudExpectedIndicator
FraudScenarioSubmission hasMany FraudExpectedFinding
FraudScenarioSubmission hasOne FraudMockCompany
FraudScenarioSubmission hasMany FraudMockParty
FraudScenarioSubmission hasMany FraudMockTransaction
FraudScenarioSubmission hasMany FraudDocumentRequest
FraudScenarioSubmission hasMany FraudInvestigationQuestion
FraudScenarioSubmission hasMany FraudGenerationRun
```

Use UUIDs.

---

# 7. Laravel Excel Import

## 7.1 Dependency

If not already installed, use:

```bash
composer require maatwebsite/excel
```

If the project avoids this package, use `phpoffice/phpspreadsheet` directly.

## 7.2 Expected Workbook

Workbook name example:

```text
brevix-fraud-scenario-template.xlsx
```

Expected sheet:

```text
Scenario_Submissions
```

Expected columns:

```text
Scenario ID
Title
Narrative
Source
Severity
Status
```

## 7.3 Validation Rules

Required:

```text
Scenario ID
Title
Narrative
```

Optional:

```text
Source
Severity
Status
```

Severity allowed values:

```text
Low
Medium
High
Critical
```

Status allowed values:

```text
Draft
Ready
Sample
Imported
Needs Review
```

Validation rules:

- Reject row if `Narrative` is empty.
- Reject row if `Title` is empty.
- Warn if `Source` is empty.
- Warn if `Severity` is empty.
- Deduplicate by `external_scenario_id`.
- Preserve raw row in `raw_row`.
- Produce import summary.

## 7.4 Laravel Endpoint: Upload Workbook

Create:

```http
POST /api/internal/fraud-testing/imports
```

Request:

```text
multipart/form-data
file: .xlsx
```

Response:

```json
{
  "import_id": "uuid",
  "status": "completed",
  "total_rows": 10,
  "successful_rows": 9,
  "failed_rows": 1,
  "validation_errors": []
}
```

## 7.5 Laravel Endpoint: List Imports

```http
GET /api/internal/fraud-testing/imports
```

## 7.6 Laravel Endpoint: Get Import

```http
GET /api/internal/fraud-testing/imports/{id}
```

---

# 8. Laravel Internal Agent API

These endpoints are for `brevixai-agents`.

Protect them with an internal token.

Recommended env var:

```text
BREVIX_INTERNAL_AGENT_TOKEN=
```

Require header:

```http
Authorization: Bearer <token>
```

or:

```http
X-Brevix-Agent-Token: <token>
```

## 8.1 Get Pending Scenarios

```http
GET /api/internal/fraud-scenarios/pending?limit=10
```

Return scenarios where:

```text
extraction_status = pending
```

Response:

```json
{
  "data": [
    {
      "id": "uuid",
      "external_scenario_id": "PAYROLL-001",
      "title": "Ghost Employee Paid Through Relative's Bank Account",
      "narrative": "...",
      "source": "Personal Experience / IRS Case",
      "severity": "High"
    }
  ]
}
```

When returned, either:

- leave status as pending and let agent claim later, or
- mark as processing with lease timeout.

Preferred: implement claim endpoint.

## 8.2 Claim Scenario

```http
POST /api/internal/fraud-scenarios/{id}/claim
```

Action:

```text
extraction_status = processing
```

Create `fraud_generation_runs` row:

```text
run_type = extraction
status = running
```

## 8.3 Save Extraction

```http
POST /api/internal/fraud-scenarios/{id}/extraction
```

Request:

```json
{
  "fraud_category": "Payroll Fraud",
  "industry": "Construction",
  "actor_type": "Payroll Manager",
  "concealment_method": "Minimal employee records and relative bank account",
  "summary": "Payroll manager created a fictitious employee and routed payroll to a related bank account.",
  "confidence_score": 0.86,
  "model_name": "gpt-...",
  "prompt_version": "fraud-extraction-v1",
  "structured_payload": {},
  "expected_indicators": [],
  "expected_findings": [],
  "document_requests": [],
  "investigation_questions": []
}
```

Action:

- Create/update `fraud_scenario_extractions`.
- Upsert expected indicators.
- Upsert expected findings.
- Upsert document requests.
- Upsert investigation questions.
- Mark `extraction_status = completed`.
- Mark extraction run complete.

## 8.4 Save Mock Data

```http
POST /api/internal/fraud-scenarios/{id}/mock-data
```

Request:

```json
{
  "mock_company": {},
  "parties": [],
  "transactions": [],
  "generation_metadata": {}
}
```

Action:

- Create/update mock company.
- Replace mock parties for scenario.
- Replace mock transactions for scenario.
- Mark `mock_data_status = completed`.

## 8.5 Mark Failure

```http
POST /api/internal/fraud-scenarios/{id}/fail
```

Request:

```json
{
  "stage": "extraction",
  "error_message": "Unable to parse scenario",
  "errors": {}
}
```

---

# 9. Laravel Admin/Review Endpoints

These can be simple JSON endpoints first. UI can come later.

## 9.1 List Scenarios

```http
GET /api/internal/fraud-testing/scenarios
```

Filters:

```text
status
extraction_status
mock_data_status
review_status
fraud_category
severity
```

## 9.2 Get Scenario Detail

```http
GET /api/internal/fraud-testing/scenarios/{id}
```

Return:

- raw submission
- extraction
- indicators
- findings
- document requests
- investigation questions
- mock company
- mock parties
- mock transactions

## 9.3 Approve Scenario

```http
POST /api/internal/fraud-testing/scenarios/{id}/approve
```

Action:

```text
review_status = approved
```

## 9.4 Reject Scenario

```http
POST /api/internal/fraud-testing/scenarios/{id}/reject
```

Action:

```text
review_status = rejected
```

---

# 10. Laravel Commands and Jobs

## 10.1 Import Command

Create artisan command:

```bash
php artisan fraud-testing:import-scenarios /path/to/workbook.xlsx
```

Options:

```text
--dry-run
--import-id=
--fail-on-error
```

This allows local import without UI.

## 10.2 Queue Job: Import Workbook

```text
App\Jobs\FraudTesting\ImportFraudScenarioWorkbook
```

Responsibilities:

- Read workbook
- Validate rows
- Create scenario submissions
- Update import summary

## 10.3 Queue Job: Dispatch Pending To Agents

Optional for first version.

Could call the agents service directly, or let agents poll Laravel.

Preferred early version:

- Agents poll Laravel.
- Avoid tight coupling.

---

# 11. Python Agents Build Plan: `brevixai-agents`

## 11.1 Suggested Module Layout

```text
brevixai_agents/
  fraud_testing/
    __init__.py
    client.py
    schemas.py
    extraction.py
    mock_data.py
    quickbooks_payloads.py
    runner.py
    validators.py
    prompts/
      fraud_extraction_v1.md
      mock_data_generation_v1.md
    tests/
      test_extraction.py
      test_mock_data.py
```

If repo structure differs, adapt to existing conventions.

---

# 12. Python Dependencies

Suggested packages:

```bash
pip install pydantic pandas openpyxl requests python-dotenv
```

If using LangChain/LangGraph already, integrate into existing graph structure.

---

# 13. Python Environment Variables

```text
BREVIX_API_BASE_URL=
BREVIX_INTERNAL_AGENT_TOKEN=
OPENAI_API_KEY=
FRAUD_TESTING_MODEL=
```

Example:

```text
BREVIX_API_BASE_URL=https://api.brevixai.com
FRAUD_TESTING_MODEL=gpt-4.1
```

Use the repo's existing model/provider setup if already available.

---

# 14. Python API Client

Create `client.py`.

Responsibilities:

```text
get_pending_scenarios(limit: int)
claim_scenario(scenario_id: str)
save_extraction(scenario_id: str, payload: dict)
save_mock_data(scenario_id: str, payload: dict)
mark_failure(scenario_id: str, stage: str, error: Exception)
```

All requests must include internal auth token.

---

# 15. Python Pydantic Schemas

Create strict schemas so LLM output can be validated.

## 15.1 `FraudScenarioExtraction`

Fields:

```python
scenario_title: str
fraud_category: str
industry: str | None
primary_actor: str | None
secondary_actors: list[str]
victim_entity: str | None
concealment_methods: list[str]
red_flags: list[str]
records_needed: list[str]
investigation_questions: list[str]
estimated_loss: float | None
severity: str | None
summary: str
confidence_score: float
```

Allowed fraud categories:

```text
Payroll Fraud
Vendor Fraud
Shell Vendor Fraud
Expense Reimbursement Fraud
Revenue Manipulation
Inventory Theft
Tax Risk
Internal Control Failure
Waste or Abuse
Bookkeeping Error
Mixed Fraud
Unknown
```

## 15.2 `ExpectedIndicator`

Fields:

```python
indicator_key: str
indicator_name: str
indicator_category: str
description: str
severity: str
data_needed: list[str]
should_detect: bool
```

## 15.3 `ExpectedFinding`

Fields:

```python
finding_key: str
finding_title: str
finding_description: str
expected_risk_score: int
expected_confidence: str
recommended_action: str
expected_user_message: str
```

## 15.4 `DocumentRequest`

Fields:

```python
document_name: str
why_needed: str
priority: str
expected_issue_found: str | None
```

## 15.5 `InvestigationQuestion`

Fields:

```python
question: str
asked_to: str
why_question_matters: str
priority: str
```

## 15.6 `MockCompany`

Fields:

```python
company_name: str
industry: str
entity_type: str
annual_revenue: float
employee_count: int
vendor_count: int
customer_count: int
months_of_activity: int
normal_business_behavior: str
```

## 15.7 `MockParty`

Fields:

```python
external_party_id: str
party_type: str
party_name: str
role: str | None
is_fraud_actor: bool
is_related_party: bool
attributes: dict
```

## 15.8 `MockTransaction`

Fields:

```python
external_transaction_id: str
transaction_type: str
transaction_date: str
amount: float
external_party_id: str | None
account_category: str
description: str
is_fraudulent: bool
fraud_pattern: str | None
expected_brevix_signal: str | None
payload: dict
```

---

# 16. LLM Extraction Prompt

Create:

```text
brevixai_agents/fraud_testing/prompts/fraud_extraction_v1.md
```

Prompt requirements:

```text
You are a fraud intelligence analyst for Brevix.

Convert the user's natural-language fraud scenario into structured JSON.

Do not invent facts that conflict with the narrative.

You may infer reasonable business context only when necessary and must mark inferred fields.

Extract:
- fraud category
- industry if present or inferable
- actors
- concealment methods
- red flags
- records needed
- investigation questions
- expected indicators
- expected findings
- document requests

Return valid JSON only.
```

The output must conform to Pydantic schemas.

If output is invalid:

- retry once with validation error included
- if still invalid, mark scenario failed or needs review

---

# 17. Mock Data Generation Prompt

Create:

```text
brevixai_agents/fraud_testing/prompts/mock_data_generation_v1.md
```

Prompt requirements:

```text
You are generating synthetic accounting data for a fraud detection test.

Use the structured fraud scenario to create a realistic mock company.

Generate:
- company profile
- parties
- transactions
- fraudulent and non-fraudulent activity

The data must be fictional.
Do not use real taxpayer, customer, or business information.
Use realistic but fake names.
Create enough normal activity so the fraudulent activity is not trivially obvious.
```

Minimum output for first version:

```text
1 mock company
5-30 parties
20-100 transactions
3-10 expected indicators
1-5 expected findings
3-10 document requests
3-10 investigation questions
```

---

# 18. Mock Data Generation Rules

The generated data must include both normal and suspicious activity.

## 18.1 Payroll Fraud Example

For ghost employee scenarios, generate:

- Real employees
- One ghost employee
- Payroll transactions for real employees
- Payroll transactions for ghost employee
- Missing or suspicious attributes for ghost employee
- Optional related bank/account metadata in party attributes

Expected signals:

```text
missing_personnel_file
duplicate_bank_account
no_timesheets
payroll_amount_without_work_activity
```

## 18.2 Vendor Fraud Example

For shell vendor scenarios, generate:

- Real vendors
- One suspicious vendor
- Repeated payments to suspicious vendor
- Vague invoice descriptions
- Payments just below threshold
- Related-party attributes

Expected signals:

```text
vendor_address_matches_employee
payments_below_approval_threshold
new_vendor_high_payment_volume
vague_invoice_descriptions
```

## 18.3 Expense Fraud Example

Generate:

- Reimbursements
- Duplicate expenses
- Weekend transactions
- Personal-looking purchases
- Round-dollar expenses

Expected signals:

```text
duplicate_receipt
weekend_expense
personal_purchase_pattern
excessive_reimbursement_frequency
```

## 18.4 Tax Risk Example

Generate:

- Payroll activity
- Payroll tax liability entries
- Missing or reduced tax payments
- Owner draws or transfers
- Notices or document requests as metadata

Expected signals:

```text
payroll_tax_underpayment
liability_not_remitted
owner_draws_during_tax_delinquency
missing_filing_records
```

---

# 19. Python Runner

Create a CLI runner.

Example:

```bash
python -m brevixai_agents.fraud_testing.runner --limit 5
```

Options:

```text
--limit
--scenario-id
--extract-only
--mock-data-only
--dry-run
--save-local
```

Flow:

```text
1. Fetch pending scenarios
2. Claim scenario
3. Run extraction
4. Save extraction to Laravel
5. Generate mock data
6. Save mock data to Laravel
7. Mark failures if needed
```

Local output option:

```text
/output/fraud_testing/{scenario_id}/extraction.json
/output/fraud_testing/{scenario_id}/mock_data.json
```

---

# 20. QuickBooks Payload Generation — Future Phase

Do not block V1 on QuickBooks API integration.

For V1, generate neutral mock accounting transactions.

For V2, add:

```text
quickbooks_payloads.py
```

Convert mock data into QuickBooks-compatible payloads:

- Vendor
- Customer
- Employee if supported
- Account
- Bill
- BillPayment
- Invoice
- Payment
- Purchase
- JournalEntry

Output should be JSON files first.

Do not push to QuickBooks until review and sandbox configuration are complete.

Suggested local output:

```text
/output/fraud_testing/{scenario_id}/quickbooks/
  vendors.json
  customers.json
  accounts.json
  bills.json
  bill_payments.json
  invoices.json
  payments.json
  purchases.json
  journal_entries.json
```

---

# 21. API-to-Agent Contract

Laravel sends:

```json
{
  "id": "internal uuid",
  "external_scenario_id": "PAYROLL-001",
  "title": "Ghost Employee Paid Through Relative Bank Account",
  "narrative": "A payroll manager created...",
  "source": "Personal Experience / IRS Case",
  "severity": "High"
}
```

Python returns extraction:

```json
{
  "fraud_category": "Payroll Fraud",
  "industry": "Construction",
  "actor_type": "Payroll Manager",
  "concealment_method": "Minimal employee records and relative bank account",
  "summary": "Payroll manager created a fictitious employee and routed payroll to a related account.",
  "confidence_score": 0.86,
  "model_name": "configured model",
  "prompt_version": "fraud-extraction-v1",
  "structured_payload": {
    "scenario_title": "Ghost Employee Paid Through Relative Bank Account",
    "fraud_category": "Payroll Fraud",
    "primary_actor": "Payroll Manager",
    "concealment_methods": [],
    "red_flags": [],
    "records_needed": [],
    "investigation_questions": []
  },
  "expected_indicators": [],
  "expected_findings": [],
  "document_requests": [],
  "investigation_questions": []
}
```

Python returns mock data:

```json
{
  "mock_company": {},
  "parties": [],
  "transactions": [],
  "generation_metadata": {
    "model_name": "configured model",
    "prompt_version": "mock-data-generation-v1"
  }
}
```

---

# 22. Laravel Test Requirements

Create tests for:

## Import Tests

- Can upload valid workbook
- Rejects missing file
- Rejects wrong file type
- Imports valid rows
- Skips invalid rows
- Records validation errors
- Deduplicates scenario IDs

## API Tests

- Internal token required
- Pending scenarios endpoint works
- Claim endpoint updates status
- Save extraction endpoint persists extraction
- Save extraction creates indicators/findings/document requests/questions
- Save mock data creates company/parties/transactions
- Failure endpoint marks scenario failed

## Model Tests

- Relationships work
- UUIDs generated
- JSON casts work
- Status values are handled

---

# 23. Python Test Requirements

Create tests for:

## Schema Tests

- Valid extraction passes
- Missing required fields fail
- Invalid severity fails
- Invalid category handled as Unknown

## Client Tests

- Auth header included
- Pending scenarios parsed
- Save extraction payload formatted correctly
- Failures are posted correctly

## Extraction Tests

Use sample narrative:

```text
A payroll manager created a fictitious employee and added the employee to payroll. The employee received direct deposits for approximately 18 months. The fraud was concealed by creating minimal employee records and routing payments through a bank account associated with a relative.
```

Expected extraction should include:

```text
Payroll Fraud
Payroll Manager
Ghost employee
Direct deposit
Personnel file
Payroll register
```

## Mock Data Tests

Expected mock data should include:

- at least one company
- at least five parties
- at least twenty transactions
- at least one fraudulent transaction
- at least one expected finding

---

# 24. Security and Privacy Rules

This workflow must not store confidential taxpayer information, real client data, or real personally identifiable information from actual cases.

Requirements:

- All generated data must be fictional.
- Contributor instructions must tell users to anonymize real cases.
- Import process should not require SSNs, EINs, real addresses, real bank accounts, or protected taxpayer details.
- If the narrative contains sensitive identifiers, future V2 should detect and redact them.

V1 minimum:

- Add a warning in upload/import documentation.
- Add a boolean field later if needed:

```text
contains_sensitive_info
```

---

# 25. Review Workflow

No generated scenario should be treated as approved automatically.

Initial statuses:

```text
review_status = unreviewed
```

Human review should be required before:

- using scenario in demos
- using scenario in benchmark reports
- pushing generated data to QuickBooks sandbox
- training models on it

Approval endpoint:

```http
POST /api/internal/fraud-testing/scenarios/{id}/approve
```

---

# 26. Implementation Phases

## Phase 1 — Laravel Foundation

Build:

- migrations
- models
- workbook import command
- workbook upload endpoint
- scenario list/detail endpoints
- internal pending/claim/save APIs
- tests

Done when:

- Excel workbook can be imported
- rows are stored as fraud scenario submissions
- scenarios can be fetched by agents
- extraction and mock data can be saved

## Phase 2 — Python Extraction

Build:

- client
- schemas
- extraction prompt
- extraction runner
- save extraction to Laravel
- tests

Done when:

- Python can fetch one scenario
- produce structured extraction
- save extraction back to Laravel

## Phase 3 — Python Mock Data Generation

Build:

- mock data schemas
- mock data prompt
- generator
- validator
- save mock data to Laravel
- local JSON export
- tests

Done when:

- one narrative creates one complete mock company dataset
- Laravel stores mock company, parties, and transactions

## Phase 4 — Review and Regression Prep

Build:

- scenario review endpoints
- expected findings comparison structure
- import/export commands
- seed local test data from generated mock data

Done when:

- approved scenarios can be used in automated Brevix test runs

## Phase 5 — QuickBooks Sandbox Export

Build:

- QuickBooks payload converter
- sandbox company mapping
- dry-run payload review
- optional push to QuickBooks API

Done when:

- approved mock data can be converted into QuickBooks-compatible JSON
- no live push occurs without explicit command/configuration

---

# 27. Acceptance Criteria

The workflow is acceptable when:

1. A user can upload the Excel workbook to Laravel.
2. Laravel creates scenario submission records.
3. Python agents can fetch pending submissions.
4. Python agents can extract structured fraud scenarios.
5. Python agents can generate mock accounting data.
6. Laravel stores extracted scenario data and mock data.
7. Expected indicators and findings are stored.
8. The system tracks status and failures.
9. Tests cover the import and agent API contract.
10. No real sensitive data is required or generated.

---

# 28. Suggested First Scenario for Testing

Use this sample narrative:

```text
A payroll manager created a fictitious employee and added the employee to payroll. The employee received direct deposits for approximately 18 months. The fraud was concealed by creating minimal employee records and routing payments through a bank account associated with a relative. The issue was discovered during a review of payroll records and personnel files. Important records included payroll registers, personnel files, direct deposit authorizations, and bank statements. Investigators asked who approved the employee and whether employment could be verified.
```

Expected extraction:

```text
Fraud Category: Payroll Fraud
Actor: Payroll Manager
Scheme: Ghost Employee
Concealment: Minimal employee records and related bank account
Records Needed: Payroll register, personnel file, direct deposit authorization, bank statements
Expected Finding: Potential Ghost Employee
```

Expected mock data:

```text
Company: fictional construction or services company
Employees: 15-30
Ghost Employees: 1
Payroll Transactions: multiple months
Fraudulent Transactions: payroll payments to ghost employee
Normal Transactions: payroll payments to legitimate employees
Expected Indicators: missing personnel file, related bank account, unverifiable employment
```

---

# 29. Notes for Codex

Prioritize working software over perfect architecture.

Do not build QuickBooks integration first.

Build the durable scenario ingestion and mock data foundation first.

The most important handoff is:

```text
Excel narrative
  -> Laravel scenario submission
  -> Python structured extraction
  -> Python mock data generation
  -> Laravel persisted mock dataset
```

Once that works, QuickBooks sandbox creation can be layered on top.
