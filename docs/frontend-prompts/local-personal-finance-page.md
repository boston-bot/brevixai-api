# Frontend Implementation Prompt: Admin Personal Finance Page

Use this prompt in the `brevixai` React frontend codebase.

Build an admin-only route for the Personal Finance tab. In local development it may remain at `/local/personal-finance`; in the deployed dev environment expose it only to signed-in users whose API user has admin access. Do not show this navigation item to non-admin users.

Use the existing app shell, auth/API client, routing conventions, and design system. In deployed environments the page must call only the Laravel backend APIs under `/api/admin/personal-finance`; local development may continue to call `/api/local/personal-finance`. It must not upload statement files or send transaction data to any external service.

Backend API:

- `GET /api/admin/personal-finance/status`
- `POST /api/admin/personal-finance/imports/run` with `{ "force": false, "reclassify": false }`
- `GET /api/admin/personal-finance/transactions`
- `PATCH /api/admin/personal-finance/transactions/{id}` for inline category/person edits
- `GET /api/admin/personal-finance/analysis/summary`
- `POST /api/admin/personal-finance/analysis/catch-up` with `{ "targetAmount": null, "months": 3|6|12 }`
- `GET /api/admin/personal-finance/rules`
- `PUT /api/admin/personal-finance/rules`
- `GET /api/admin/personal-finance/budgets`
- `PUT /api/admin/personal-finance/budgets`
- `POST /api/admin/personal-finance/exports` with `{ "format": "pdf"|"docx", "from": "YYYY-MM-DD", "to": "YYYY-MM-DD", "includeTransactions": false }`

Page requirements:

- Import/status panel with PDF count, imported statement count, date range, last import state, and buttons for `Scan statements` and `Reclassify`.
- KPI strip for monthly income, outflow, net cash flow, average deficit, cumulative deficit, and required catch-up.
- Cash-flow trend chart by month.
- Top spending categories and top merchants.
- Recurring payments/subscriptions table.
- Two-person allowance panel with monthly caps, actual average spend, remaining amount, and overage.
- Catch-up calculator with 3, 6, and 12 month scenarios.
- Transactions table with filters for date, category, person, merchant, and direction. Allow inline category/person reassignment via the PATCH endpoint.
- Rules and budgets tab for income-source, category, merchant, person, and exclusion rules.
- Export button for PDF and DOCX downloads.

Design direction:

- Make this an operational finance dashboard, not a landing page.
- Use dense but readable tables, restrained color, clear negative/positive cash-flow states, and compact controls.
- Keep cards only for individual widgets or tool panels; do not nest cards.
- Avoid marketing copy. The page is for private admin analysis.

Acceptance checks:

- The route is unreachable and unlisted for non-admin users.
- The page works with an existing auth token/session.
- Import and reclassification actions show loading/error/success states.
- Summary, catch-up, rules, budget, transaction edits, and exports all call the admin backend API in deployed environments.
- Tests cover route hiding, summary rendering, rule editing, transaction reassignment, catch-up calculation, and export action.
