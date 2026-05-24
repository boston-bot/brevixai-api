# Brevix AI Codebase Positioning Audit Spec

## Purpose

This document instructs AI coding agents, including Codex and Claude, to inspect the current Brevix AI codebase and verify that product, architecture, naming, UI copy, workflows, and technical implementation align with Brevix AI’s strategic positioning.

Brevix AI should **not** present itself as another accounting platform, bookkeeping tool, tax-preparation product, or AI accountant. The platform should be positioned as a **financial intelligence, fraud detection, risk monitoring, and operational trust layer** that works alongside accounting systems such as QuickBooks.

The goal is to ensure the codebase supports the following strategic identity:

> Brevix AI continuously analyzes business financial behavior to detect fraud, operational risk, control breakdowns, and financial anomalies before they become catastrophic.

---

## Core Positioning Rule

Brevix AI is not a replacement for QuickBooks, TurboTax, bookkeepers, accountants, or accounting systems.

Brevix AI is a layer above accounting systems.

Accounting systems record transactions.

Brevix AI interprets financial behavior.

The codebase should reinforce this distinction in:

- UI copy
- dashboard language
- route names
- component names
- API naming
- database concepts
- agent prompts
- marketing content
- onboarding language
- alerts and case management flows
- documentation
- tests

---

## Required Strategic Positioning

The codebase should support positioning Brevix AI as one or more of the following:

- Financial intelligence infrastructure
- Continuous financial risk monitoring
- Fraud and operational intelligence for SMBs
- A financial sentry for business owners
- A risk detection and investigation platform
- The security layer for business finances
- A behavioral anomaly detection system for financial operations

The codebase should avoid positioning Brevix AI as:

- AI bookkeeping
- AI accountant
- Accounting automation software
- QuickBooks replacement
- Tax-preparation software
- Payroll software
- Invoice-management software as the primary product identity
- A general accounting suite
- A “forensic accountant” replacement

---

## Agent Instructions

Inspect the repository as it exists today. Do not assume the intended architecture from this document alone. Verify the current implementation, naming, copy, routes, services, prompts, and tests.

For every issue found, provide:

1. File path
2. Line number or approximate location
3. Current text, name, route, service, or behavior
4. Why it conflicts with Brevix AI positioning
5. Recommended replacement
6. Risk level: `high`, `medium`, or `low`
7. Whether the change is copy-only, naming-only, logic-related, database-related, or architecture-related

Do not make destructive changes automatically unless explicitly instructed. Prefer generating a proposed patch or task list first.

---

# Audit Areas

## 1. Product Copy Audit

Search the entire codebase for product language that suggests Brevix AI is an accounting platform or accounting replacement.

### Search terms

Search for:

- accountant
- accounting platform
- bookkeeping
- bookkeeper
- tax prep
- tax preparation
- payroll
- invoice automation
- QuickBooks replacement
- TurboTax replacement
- forensic accountant
- AI accountant
- automated accountant
- accounting suite
- general ledger replacement
- manage your books
- do your books
- file your taxes
- tax advice

### Required outcome

Flag any language that implies Brevix AI performs accounting, bookkeeping, tax filing, payroll, or professional accounting services.

### Preferred replacement language

Use language such as:

- financial intelligence
- fraud detection
- risk monitoring
- anomaly detection
- controls monitoring
- operational trust
- suspicious pattern detection
- transaction risk analysis
- financial behavior analysis
- evidence-backed alerts
- investigation workflows
- business financial sentry

---

## 2. Homepage and Marketing Page Audit

Inspect landing pages, pricing pages, hero sections, feature sections, onboarding screens, and public-facing components.

### Check for alignment

The homepage should answer:

> “Why does this exist if I already use QuickBooks?”

The answer should be:

> “QuickBooks records your financial activity. Brevix AI watches for risk, fraud, anomalies, and control breakdowns across that activity.”

### Hero copy should emphasize

- Detection
- Monitoring
- Risk intelligence
- Financial behavior analysis
- Plain-English alerts
- SMB protection
- Evidence-based findings

### Hero copy should not emphasize

- Bookkeeping automation
- Accounting replacement
- Tax filing
- Payroll
- Invoice generation as core identity
- Being an accountant

### Suggested hero direction

Use copy similar to:

> Brevix AI is a financial intelligence layer that monitors business transactions for fraud, operational risk, and unusual behavior before small problems become expensive ones.

---

## 3. Dashboard and Feature Naming Audit

Inspect dashboard cards, navigation items, feature names, route labels, menu labels, and module names.

### Strong feature names

Prefer names such as:

- Risk Monitor
- Financial Sentry
- Transaction Intelligence
- Fraud Signals
- Controls Health
- Entity Graph
- Investigation Cases
- Vendor Risk
- Anomaly Feed
- Exposure Summary
- Evidence Timeline
- Reconciliation Detective

### Weak or conflicting feature names

Flag names such as:

- Bookkeeping Dashboard
- Accounting Dashboard
- AI Accountant
- Tax Center
- Payroll Manager
- Invoice Manager, if presented as the core product
- Ledger Manager, if presented as accounting replacement

### Important distinction

It is acceptable for Brevix AI to read accounting data, display transactions, and compare ledgers. It should not imply that Brevix AI is the primary accounting system of record.

---

## 4. QuickBooks Integration Positioning Audit

Inspect any QuickBooks-related code, copy, OAuth flow, import labels, API routes, and documentation.

### Correct positioning

QuickBooks should be framed as a data source.

Acceptable language:

- Connect QuickBooks to monitor transaction risk
- Import financial activity for analysis
- Analyze QuickBooks transactions for unusual patterns
- Use QuickBooks data as evidence for risk detection

Problematic language:

- Replace QuickBooks
- Move your accounting into Brevix
- Manage your books in Brevix
- Brevix does your bookkeeping
- Brevix is better accounting software

### Agent task

Find every QuickBooks reference and classify it as:

- Proper data-source positioning
- Ambiguous positioning
- Competitive/replacement positioning

Recommend corrections for ambiguous or competitive wording.

---

## 5. AI Agent Prompt Audit

Inspect system prompts, LangGraph prompts, Laravel prompt templates, orchestration prompts, explanation prompts, fraud analyzer prompts, approval-gate prompts, and any agent instruction files.

### Prompts should instruct AI to behave as

- Financial risk analyst
- Fraud detection assistant
- Internal controls reviewer
- Transaction intelligence assistant
- Evidence summarizer
- Investigation support assistant

### Prompts should not instruct AI to behave as

- Accountant
- CPA
- Tax attorney
- Bookkeeper
- Tax preparer
- Payroll specialist
- Professional legal advisor

### Required guardrail

All AI outputs involving fraud, tax, finance, or legal risk should be informational, evidence-based, and non-final. The platform may flag risks and recommend review, but should not claim to provide professional accounting, legal, or tax services.

Suggested guardrail language:

> Brevix AI provides informational financial risk analysis and anomaly detection. It does not provide accounting, legal, tax, or professional advisory services.

---

## 6. Alert and Detection Logic Audit

Inspect fraud detection services, transaction scoring, analytics jobs, anomaly detectors, rules engines, and alert generation logic.

### Detection logic should support the strategic moat

Look for evidence of:

- Vendor behavior changes
- Payment spikes
- Threshold splitting
- Duplicate payments
- Vendor concentration risk
- Unusual approval activity
- Missing documentation
- A/R aging risk
- Unusual transaction timing
- Shared bank accounts or payment methods
- Employee/vendor relationship risk
- Entity graph relationships
- Case escalation workflows
- Evidence-backed alert explanations

### Flag gaps

Flag any implementation that only summarizes transactions without detecting risk.

The product should not be limited to:

- “Here are your expenses”
- “Here is your cash flow”
- “Here are your vendors”

Those are useful, but they are not enough. Brevix AI must explain what changed, why it matters, and what evidence supports the concern.

---

## 7. Explainability and Evidence Audit

Every risk alert should be explainable.

Inspect whether alerts include:

- Reason code
- Triggering transaction IDs
- Supporting evidence
- Confidence or severity
- Plain-English explanation
- Recommended next review step
- Timeline or comparison period
- Source system
- Company/account context

### Required standard

An alert should not simply say:

> Suspicious transaction detected.

It should say something closer to:

> Vendor payments increased 340% compared with the prior 90-day average, and two payments were submitted just below the approval threshold within a 48-hour period.

---

## 8. Case Management Audit

Inspect case-related models, routes, controllers, UI pages, services, exports, and tests.

### Case management should support

- Creating investigation cases from alerts
- Linking alerts to transactions
- Evidence timelines
- Status tracking
- Notes or reviewer comments
- Audit trail
- Exportable reports
- Resolution tracking

### Strategic purpose

Case management turns Brevix AI from a dashboard into investigation infrastructure.

Flag any case implementation that is too generic, disconnected from alerts, or not tied to evidence.

---

## 9. Controls Health Audit

Inspect any controls, policy, rules, thresholds, or governance-related code.

### Controls Health should focus on

- Approval thresholds
- Segregation of duties
- Documentation completeness
- Vendor onboarding controls
- Payment authority limits
- Reconciliation gaps
- Duplicate payment controls
- Unusual vendor changes

### Required positioning

Controls Health should be framed as internal control monitoring, not accounting compliance certification.

Avoid language that implies Brevix officially certifies compliance or provides an audit opinion.

---

## 10. Entity Graph Audit

Inspect graph-related models, endpoints, UI components, and services.

### Entity Graph should detect relationships among

- Vendors
- Employees
- Bank accounts
- Companies
- Transactions
- Invoices
- Payment methods
- Approvers

### Look for relationship intelligence

Flag whether the graph can identify:

- Shared bank accounts
- Shared addresses
- Shared payment methods
- Employee/vendor overlap
- Vendor concentration
- Ghost vendors
- Circular payment patterns
- Related-party risk indicators

The graph should be positioned as financial relationship intelligence, not a generic visualization.

---

## 11. Data Model Audit

Inspect database migrations, models, factories, seeders, and API resources.

### Ensure the data model supports

- Transactions
- Vendors
- Alerts
- Cases
- Evidence
- Risk scores
- Controls
- Source systems
- Audit trail
- Entity relationships
- Company scoping
- User scoping

### Watch for missing concepts

Flag if the data model lacks durable support for:

- Alert evidence
- Case linkage
- Risk reason codes
- Source attribution
- Historical comparisons
- Investigation status

These concepts are important for becoming an intelligence layer rather than a dashboard.

---

## 12. API Naming and Route Audit

Inspect API routes and controller names.

### Prefer route concepts such as

- `/risk`
- `/alerts`
- `/investigations`
- `/cases`
- `/controls`
- `/entity-graph`
- `/transaction-intelligence`
- `/anomalies`
- `/evidence`

### Be cautious with route concepts such as

- `/bookkeeping`
- `/accounting`
- `/tax-prep`
- `/payroll`
- `/ledger-management`

Routes may still reference accounting data where technically necessary, but public API naming should support the platform’s intelligence-layer identity.

---

## 13. Pricing and Plan Audit

Inspect pricing page, feature gates, subscription plans, and plan descriptions.

### Pricing should sell outcomes such as

- Monitor financial risk
- Detect suspicious transactions
- Protect business funds
- Review vendor risk
- Track internal control health
- Investigate anomalies
- Export evidence reports

### Pricing should not sell

- Do your books
- Replace your accountant
- File taxes
- Run payroll
- Full accounting suite

### High-value features to gate

Consider whether premium tiers should emphasize:

- Advanced risk analytics
- Entity graph
- Case management
- Evidence exports
- Custom controls
- Multi-company monitoring
- Advanced anomaly detection
- Historical trend comparison

---

## 14. Legal and Professional Services Disclaimer Audit

Inspect footers, terms, onboarding, AI responses, alert screens, and report exports.

### Required disclaimer themes

Brevix AI should clearly state that it provides informational analysis and does not provide:

- Legal advice
- Tax advice
- Accounting services
- Audit opinions
- CPA services
- Attorney-client relationship

### Suggested disclaimer

> Brevix AI provides informational financial risk analysis and fraud detection support. It does not provide legal, tax, accounting, audit, or professional advisory services. Users should consult qualified professionals before taking formal action.

---

## 15. Acquisition Readiness Audit

Inspect the codebase for signs that Brevix AI is building a defensible acquisition-worthy capability.

### Strong acquisition signals

The codebase should increasingly show:

- Proprietary risk scoring
- Explainable anomaly detection
- Cross-system financial intelligence
- Entity relationship mapping
- Durable investigation workflows
- Evidence-backed alerts
- SMB fraud-specific use cases
- Strong data provenance
- Multi-tenant security
- Company-level isolation
- Repeatable detection rules
- Extensible integrations

### Weak acquisition signals

Flag if the product appears to be mostly:

- AI summaries
- Generic dashboards
- Basic transaction lists
- Chat-only experiences
- Thin wrappers around QuickBooks data
- Non-specific accounting helper tools

The moat is not AI chat. The moat is structured financial behavior intelligence.

---

# Required Output Format for Agents

After inspection, produce a report named:

`BREVIX_POSITIONING_AUDIT_REPORT.md`

The report should include:

## 1. Executive Summary

Briefly answer:

- Does the codebase currently position Brevix AI correctly?
- Is it more like an accounting tool, a dashboard, or a financial intelligence layer?
- What are the top three risks to fix?

## 2. Pass/Fail Checklist

Use this table:

| Area | Status | Notes |
|---|---|---|
| Product copy avoids accounting replacement language | PASS/FAIL/PARTIAL |  |
| QuickBooks is framed as data source | PASS/FAIL/PARTIAL |  |
| AI prompts avoid professional-services claims | PASS/FAIL/PARTIAL |  |
| Alerts are evidence-backed | PASS/FAIL/PARTIAL |  |
| Detection logic goes beyond summaries | PASS/FAIL/PARTIAL |  |
| Case management supports investigations | PASS/FAIL/PARTIAL |  |
| Entity graph supports relationship intelligence | PASS/FAIL/PARTIAL |  |
| Controls Health supports internal control monitoring | PASS/FAIL/PARTIAL |  |
| Pricing sells risk outcomes, not accounting replacement | PASS/FAIL/PARTIAL |  |
| Legal disclaimers are present | PASS/FAIL/PARTIAL |  |
| Acquisition moat is visible in codebase | PASS/FAIL/PARTIAL |  |

## 3. Findings

For each finding, use this format:

```md
### Finding: [Short title]

- Risk level: high | medium | low
- Category: copy | naming | logic | database | architecture | prompt | test
- File: path/to/file
- Location: line number or approximate section
- Current state:
- Why this is a problem:
- Recommended change:
- Suggested replacement text or patch:
```

## 4. Recommended Code Changes

Group recommended changes into:

- Immediate copy fixes
- Naming and route cleanup
- Prompt guardrail updates
- Detection logic improvements
- Data model improvements
- UI/UX improvements
- Tests to add or update

## 5. Suggested Tests

Add or recommend tests that verify:

- Marketing copy does not use prohibited positioning terms
- AI prompts include professional-services disclaimers
- Alerts include evidence and reason codes
- Case creation links to alerts and transactions
- Company scoping prevents cross-company access
- QuickBooks is treated as an external data source
- Risk summaries include evidence, not unsupported conclusions

## 6. Strategic Assessment

End with a direct assessment:

> Based on the current codebase, Brevix AI is closest to: accounting helper / dashboard / financial intelligence layer / investigation infrastructure.

Then explain what must change to move it closer to acquisition-ready financial intelligence infrastructure.

---

# Priority Fix Order

Agents should prioritize issues in this order:

1. Remove or rewrite language that implies Brevix AI is an accountant, bookkeeper, tax preparer, or accounting replacement.
2. Ensure QuickBooks is consistently framed as a source of financial data, not as a competitor being replaced.
3. Add or strengthen disclaimers around legal, tax, accounting, and professional advice.
4. Ensure alerts are evidence-backed and explainable.
5. Strengthen case management and evidence workflows.
6. Improve naming around risk, controls, intelligence, and investigations.
7. Expand detection logic beyond summaries and dashboard metrics.
8. Add tests that enforce positioning and safety rules.

---

# Final Strategic Standard

The codebase is aligned when a reviewer can inspect the app and clearly understand this:

> Brevix AI does not do the books. Brevix AI watches the books for risk.



---

# Rex AI Orchestration Layer

## Purpose

Rex AI is the primary conversational interface for the Brevix AI platform.

Rex is NOT:
- a generic chatbot
- a general-purpose LLM assistant
- an accounting AI
- a replacement for bookkeeping systems

Rex IS:
- an orchestration interface
- a financial intelligence coordinator
- a service router
- an investigative workflow assistant
- an explanation and action layer

---

## Architectural Principle

Users communicate with Rex.

Rex determines:
- which internal services should execute
- execution order
- required evidence
- approval requirements
- response synthesis
- escalation paths

Rex should orchestrate specialized services such as:
- Transaction Analysis Service
- Fraud Detection Service
- Vendor Risk Service
- Entity Graph Service
- Controls Health Monitor
- Reconciliation Detective
- Case Management Service
- Reporting Service
- IRS/IRM Intelligence Layer
- Human Approval Gateways

---

## Critical Constraint

Rex must NEVER behave as:
- an unrestricted chatbot
- an internet assistant
- a creative-writing assistant
- a replacement for human legal/accounting advice

All responses should remain:
- evidence-backed
- scoped to company data
- operationally explainable
- risk-oriented
- action-oriented

---

## UX Principle

The user experience should feel like:

> “Talking to an intelligent financial operations center.”

NOT:

> “Using ChatGPT with accounting plugins.”

---

## Codebase Audit Requirements

AI agents inspecting the codebase should verify:

1. Business logic exists in specialized services, NOT directly inside chat handlers.
2. Rex primarily routes/orchestrates rather than computes everything itself.
3. Services remain modular and independently callable.
4. Prompt logic does not bypass approval workflows.
5. Case management and evidence persistence exist independently of chat sessions.
6. Long-running analysis tasks are service-based and queue-compatible.
7. Agent outputs remain explainable and deterministic where possible.
8. No single “god-agent” architecture exists.

---

## Long-Term Product Direction

The long-term architecture of Brevix AI should evolve into:

### Brevix AI
The financial intelligence platform.

### Rex AI
The conversational orchestration layer.

### Services
Independent intelligence and operational modules that can:
- execute individually
- run asynchronously
- participate in workflows
- expose APIs
- generate evidence
- feed case management systems
- integrate into external systems

The ideal architecture is service-oriented and orchestration-driven rather than monolithic.

---

## Strategic Positioning Reminder

QuickBooks records transactions.

Brevix interprets organizational financial behavior.

Rex should continuously reinforce this distinction through:
- workflow design
- UI structure
- service boundaries
- language choices
- response patterns
- investigation tooling
- risk scoring systems

The platform should feel like:
- operational intelligence
- financial security infrastructure
- fraud detection infrastructure
- organizational risk monitoring

NOT:
- bookkeeping software
- AI accounting
- a general AI assistant
