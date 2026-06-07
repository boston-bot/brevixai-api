# Mock Data Generation Prompt v1

You are generating synthetic accounting data for a Brevix fraud detection test.

Use the structured fraud scenario below to create a realistic but entirely fictional mock company dataset.

## Rules

- All data must be **fictional**. Do not use real business names, real people, real taxpayer IDs, real bank account numbers, or real addresses.
- Use realistic-sounding fake names (e.g., "Meridian Construction LLC", "James Thornton").
- Create enough **normal activity** so the fraudulent activity is not trivially obvious.
- The fraudulent transactions must match the fraud scheme described in the scenario.
- Every party referenced in a transaction must exist in the parties list.

## Minimum Requirements

- 1 mock company
- 5–30 parties (employees, vendors, customers, etc.)
- 20–100 transactions (mix of normal and fraudulent)
- At least 1 fraudulent transaction with `is_fraudulent: true`
- At least 1 expected finding

## Output Format

Return a single JSON object with this structure exactly. No explanation outside the JSON.

```json
{
  "mock_company": {
    "company_name": "<fictional company name>",
    "industry": "<industry>",
    "entity_type": "<LLC, Corporation, S-Corp, Sole Proprietor, etc.>",
    "annual_revenue": <float>,
    "employee_count": <integer>,
    "vendor_count": <integer>,
    "customer_count": <integer>,
    "months_of_activity": <integer>,
    "normal_business_behavior": "<brief description of typical activity>"
  },
  "parties": [
    {
      "external_party_id": "<stable ID, e.g. EMP-001>",
      "party_type": "<Employee, Vendor, Customer, Owner, Bookkeeper, Payroll Manager, AP Clerk, Related Party, Unknown>",
      "party_name": "<fictional name>",
      "role": "<specific role or null>",
      "is_fraud_actor": <true or false>,
      "is_related_party": <true or false>,
      "attributes": {
        "<key>": "<value>"
      }
    }
  ],
  "transactions": [
    {
      "external_transaction_id": "<stable ID, e.g. TX-001>",
      "transaction_type": "<Bill, Bill Payment, Invoice, Payment, Journal Entry, Payroll Payment, Expense, Check, Credit Card Charge, Vendor Credit, Customer Refund, Deposit, Owner Draw>",
      "transaction_date": "<YYYY-MM-DD>",
      "amount": <float>,
      "external_party_id": "<matches a party external_party_id or null>",
      "account_category": "<category>",
      "description": "<transaction description>",
      "is_fraudulent": <true or false>,
      "fraud_pattern": "<snake_case pattern or null>",
      "expected_brevix_signal": "<signal key or null>",
      "payload": {}
    }
  ],
  "generation_metadata": {
    "model_name": "chatgpt-manual",
    "prompt_version": "mock-data-generation-v1"
  }
}
```

## Fraud Scenario

{{STRUCTURED_EXTRACTION_JSON}}
