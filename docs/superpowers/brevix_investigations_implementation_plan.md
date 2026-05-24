# Brevix AI — Investigations & Financial Intelligence Implementation Plan

## Purpose

This document defines the architectural direction, implementation strategy, and product vision for the next evolution of Brevix AI.

The goal is to guide Claude AI, Codex, and future engineering agents in implementing a structured financial intelligence and investigation platform.

This document should be treated as:
- a strategic blueprint
- an architectural guidance document
- a product direction specification
- a service-boundary enforcement document

---

# Core Philosophy

Brevix AI is NOT:
- bookkeeping software
- accounting software
- tax preparation software
- a QuickBooks replacement
- a generic AI chatbot
- a generalized financial assistant

Brevix AI IS:
- a financial intelligence platform
- a fraud detection platform
- a business risk analysis system
- an operational anomaly detection platform
- a guided investigation system
- a contextual financial intelligence engine

The platform exists to:
- identify unusual financial behavior
- detect operational inconsistencies
- identify fraud indicators
- organize investigations
- assist users in understanding financial risk
- coordinate evidence gathering
- surface explainable findings

The system must remain:
- evidence-based
- explainable
- context-aware
- workflow-oriented
- modular
- orchestration-driven

---

# Architectural Direction

## High-Level Structure

The system should evolve into three major layers:

## 1. Brevix AI Platform

The core financial intelligence infrastructure.

Responsible for:
- services
- workflows
- storage
- evidence
- risk engines
- anomaly detection
- investigations
- case management
- integrations

---

## 2. Rex AI

The conversational orchestration layer.

Rex AI is NOT the intelligence engine itself.

Rex AI is responsible for:
- orchestrating services
- collecting intake information
- routing workflows
- coordinating analysis
- synthesizing findings
- managing conversational investigation flows
- interacting with the user in plain English

Rex should function like:
> a financial operations command center

NOT:
> a generic AI chatbot

---

## 3. Specialized Services

The platform should contain independent services/modules.

Potential services include:
- Investigation Intake Service
- Transaction Analysis Service
- Fraud Indicator Service
- Vendor Risk Service
- Employee Risk Service
- Entity Graph Service
- Controls Health Service
- Reconciliation Detective Service
- OCR/Document Intelligence Service
- Check Analysis Service
- Behavioral Baseline Service
- Case Management Service
- Reporting Service
- IRS/Tax Notice Interpretation Service
- Workflow & Approval Service

All services should:
- remain modular
- expose clean APIs
- support asynchronous processing
- support orchestration
- generate explainable evidence
- be independently testable

Avoid monolithic “god-agent” architectures.

---

# Core Product Direction

## Brevix Investigations

This becomes a major platform feature.

The platform should support:
- guided investigations
- partial evidence analysis
- contextual intake
- anomaly detection
- fraud indicator analysis
- operational inconsistency analysis
- evidence gathering
- explainable findings

The platform should behave like:
> a structured investigative workflow system

NOT:
> a dashboard that produces random AI alerts
