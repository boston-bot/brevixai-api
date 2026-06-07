# Brevix Fraud Testing Developer Build Plan (Revised)

## IMPORTANT ARCHITECTURAL CHANGE

The current implementation should NOT assume API-based AI access.

The Brevix founder currently has access to ChatGPT through the ChatGPT application with message/token allotments, but does not currently intend to build around OpenAI API usage.

Therefore V1 of this workflow must support a human-assisted AI generation process.

---

# V1 Workflow

1. Fraud contributor completes Excel workbook.
2. Workbook imported into brevixai-api.
3. Scenario stored in Postgres.
4. Scenario exported or displayed for AI processing.
5. Human submits scenario narrative to ChatGPT.
6. ChatGPT generates:
   - structured extraction JSON
   - expected findings JSON
   - mock company JSON
   - mock parties JSON
   - mock transaction JSON
7. Human downloads generated JSON files.
8. JSON files imported into brevixai-api.
9. Laravel validates and stores data.
10. Brevix uses imported mock data for testing.

---

# DO NOT BUILD

The following items are deferred:

- OpenAI API integration
- Anthropic API integration
- Automated LLM calls
- Agent-driven extraction requiring paid APIs
- Scheduled AI processing

These should be planned as future phases only.

---

# Laravel Responsibilities

brevixai-api remains the source of truth.

Build:

- Excel workbook importer
- Scenario storage
- Scenario review screens/endpoints
- JSON extraction importer
- JSON mock-data importer
- Validation framework
- Test data storage

The system should allow generated JSON to be uploaded manually.

Example:

POST /api/internal/fraud-testing/import-json

Accept:

- extraction.json
- mock_data.json

Store results in normalized tables.

---

# Python Responsibilities

brevixai-agents should focus on:

- schema definitions
- validators
- import/export tooling
- future extraction pipelines
- future mock-data generators

Do NOT require live AI access for V1.

---

# Manual AI Workflow

The founder should be able to:

1. Open scenario in Brevix.
2. Copy narrative.
3. Paste narrative into ChatGPT.
4. Use a standard prompt.
5. Receive structured JSON.
6. Download JSON.
7. Import JSON into Brevix.

This provides immediate value without API costs.

---

# JSON Formats

Codex should define:

extraction.json

Contains:

- fraud category
- actors
- concealment methods
- records needed
- investigation questions
- expected indicators
- expected findings

mock_data.json

Contains:

- company
- parties
- transactions

These files become the contract between ChatGPT and Brevix.

---

# Future Automation

When API access becomes available:

Excel
→ Laravel
→ Agents
→ AI API
→ Structured Output
→ Laravel

But this should NOT be required for initial implementation.

---

# Immediate Goal

Build:

Excel Narrative
→ Laravel Storage
→ Manual AI JSON Generation
→ JSON Import
→ Mock Data Storage

Once this is working, QuickBooks sandbox generation can be layered on later.

