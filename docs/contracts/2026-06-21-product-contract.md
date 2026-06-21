# Brevix Product Contract
**Date:** 2026-06-21
**Status:** Approved First Release Baseline

This document is the shared source of truth across `brevixai`, `brevixai-agents`, and `brevixai-api` for the initial product scope. New repository work must align with this contract.

## 1. Chosen Vertical
**Evidence-backed Vendor & Payment Review**
The first release provides a bounded investigation workflow for unusual vendors, duplicate payments, concentration risk, and relationship indicators. It anchors every claim to deterministic rules and source records.

## 2. Target Audiences and Jobs-to-be-Done
*   **Business Owners:** Need a plain-language answer to *what requires attention, why, and what to review next*.
*   **CPAs / Advisors:** Need inspectable evidence, explicit limitations, reviewer controls, and defensible handoff/exports.

## 3. Tenancy Scope & Advisor Model
The initial release assumes a CPA/advisor operates as an **invited reviewer inside a single client workspace**.
*   **Explicit Non-Goal:** Multi-client portfolio workspaces or practice-management features. This capability is deferred and must not be implied by navigation or copy.

## 4. Supported Inputs
*   **Launch-Critical:** CSV/XLSX imports and QuickBooks Online (QBO) synchronous ledger connections.
*   **Explicit Non-Goal:** Extraneous ledger types (e.g., Xero, Sage) or arbitrary document dumps without structured extraction pipelines.

## 5. Permitted Claims
Product language and agent answers must be grounded in evidence and source records.
*   **Permitted:** "Review this signal," "Supporting evidence indicates X," "Scope limitation: Y is missing."
*   **Explicitly Banned:** "Fraud occurred," "This is professional/legal/tax advice," or confident generation of false facts.

## 6. First-Value Moment & Release Success
Release success is defined by a complete, observable user outcome, not a count of features:
1. User connects data.
2. System produces bounded vendor/payment findings with cited source records.
3. Owner or advisor reviews, dismisses, requests evidence, or opens an investigation.
4. User preserves the review trail and exports a package.
*(Success requires the workflow to complete without memory failures, sync hangs, or tenancy authorization leaks.)*

## 7. Backend Surface Classification
To prevent work on conflicting verticals, the Laravel backend surfaces are officially classified as follows:

| Classification | Surfaces |
| :--- | :--- |
| **Launch-critical** | Auth, Workspace, Business Profiles, Findings, Investigations, Evidence, Packages, Agent Approvals, Reviews |
| **Supporting** | Uploads, Integrations (QBO), Stripe |
| **Compatibility-only** | Legacy Alerts & Cases |
| **Experimental (Paused)** | Reconciliation, Tax Notices / IRS Workflows, Fraud Testing Scenarios, Controls, AR Aging, Entity Graph |
| **Retired Candidate** | Personal Finance / Admin Local |
