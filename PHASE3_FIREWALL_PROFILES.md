# Phase 3 Firewall Profile Catalog

These profiles are policy keys first; enforcement starts as planned-event logging, then moves to real rule apply/remove.

## Profile: `web_edge`

- Intended for: `shared_edge` web/app products.
- Inbound:
  - allow `80,443` on edge only
  - backend service nodes allow ingress from edge subnet only
- Outbound:
  - allow required package update + DNS + app egress
  - deny high-risk unnecessary outbound ports by policy

## Profile: `general_vps`

- Intended for: general VPS products with client-managed services.
- Inbound:
  - default deny
  - explicitly opened ports only
- Outbound:
  - default allow with abuse controls (rate limits/alerts)

## Profile: `mail_vps`

- Intended for: mail-enabled products.
- Inbound:
  - allow `25,465,587,993,995`
  - optional `110,143` if explicitly needed
- Outbound:
  - SMTP egress monitored and rate-limited
- Additional requirements:
  - enforce PTR + SPF/DKIM/DMARC checks before go-live

## Profile: `custom`

- Intended for: exception products.
- Rules:
  - explicit allowlist only
  - change-controlled updates

## Enforcement Stages

1. Stage A: plan events only (`fw_*_planned`).
2. Stage B: dry-run simulation with diff output.
3. Stage C: active apply/remove on lifecycle hooks.

## Logging and Audit

- Every firewall action emits audit event rows with:
  - profile key
  - target service/node/vmid
  - status + error details
