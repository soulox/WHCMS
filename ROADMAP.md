# Proxmox Module Roadmap

This index tracks phased implementation, validation, and rollout documents.

## Current Status Snapshot

- Phase 1 (policy/IPAM baseline): implemented in module + addon code.
- Phase 1.5 (internal DNS cleanup + state/audit views): implemented.
- Phase 2 (strict mode, guardrails, class planning events): implemented in code; live WHMCS execution pending.
- Phase 3 (public DNS adapter, edge routing, firewall enforcement): design complete, implementation pending.

## Document Index

### Operational Handoff

- `SESSION_TRANSFER.md`
- `INFRASTRUCTURE.md`

### Phase 2

- `PHASE2_VALIDATION.md`
- `PHASE2_EXECUTION_LOG.md`

### Phase 3 Design

- `PHASE3_CLASS_MATRIX.md`
- `PHASE3_DNS_PROVIDER_DECISION.md`
- `PHASE3_FIREWALL_PROFILES.md`
- `PHASE3_EDGE_TOPOLOGY.md`

### Phase 3 Execution

- `PHASE3_IMPLEMENTATION_PLAN.md`
- `PHASE3_EXECUTION_LOG.md`

## Rollout Checklist

### Phase 2 Live Validation

- [ ] Reactivate addon on target WHMCS host
- [ ] Confirm schema and policy columns exist
- [ ] Configure/enable one pilot pool
- [ ] Configure one pilot product policy
- [ ] Enable policy engine on pilot product
- [ ] Execute Phase 2 tests A-F
- [ ] Capture evidence in `PHASE2_EXECUTION_LOG.md`

### Phase 3 Buildout

- [ ] Milestone 1: schema/policy extensions complete
- [ ] Milestone 2: public DNS adapter (Cloudflare v1) complete
- [ ] Milestone 3: shared edge routing pilot complete
- [ ] Milestone 4: firewall enforcement Stage B->C complete
- [ ] Milestone 5: dedicated public IP path pilot complete
- [ ] Milestone 6: HA edge upgrade complete
- [ ] Capture evidence in `PHASE3_EXECUTION_LOG.md`

## Decision Log

- Internal DNS remains authoritative for private service names (`infra.local`).
- Public DNS provider first adapter target: Cloudflare.
- Default exposure model target: shared edge for most app/VPS products.

## Notes

- Keep docs updated together when operational state changes.
- For emergency rollback, disable policy engine at product level first.
