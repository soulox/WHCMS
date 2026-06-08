# Phase 3 Edge Topology

## Initial Topology (Recommended)

- **Single shared edge VM** on `pve26` for first rollout.
- Functions:
  - TLS termination
  - hostname-based routing to private service backends
  - optional WAF/rate limiting

## Traffic Model

1. Client resolves public DNS hostname.
2. Traffic reaches shared edge public IP.
3. Edge routes by `Host` header to private backend IP/port.
4. Backend stays on private network and is not directly exposed.

## Internal Naming Pattern

- Backend hostnames:
  - `<prefix>-<serviceid>.infra.local`
- Public hostnames:
  - `<service-subdomain>.<public-zone>`

## Operational Constraints

- Only edge listens on public ingress ports.
- Backends restricted to edge-subnet ingress.
- Edge and backend health checks required.

## HA Upgrade Path

When ready for high availability:

- Deploy second edge VM (`pve27` preferred for node diversity).
- Add failover front (floating IP or external LB).
- Keep config synced between edge nodes.

## Rollout Sequence

1. Bring up single edge with one pilot app product.
2. Validate routing, TLS, and lifecycle automation.
3. Expand to all `shared_edge` products.
4. Add second edge node for HA.
