# Phase 3 Service Class Matrix

This matrix defines the target service class for each WHMCS product category.

## Service Classes

- `private_only`: private IP only, internal DNS only, no public edge/public DNS.
- `shared_edge`: private IP backend, client traffic through shared edge, public DNS enabled.
- `dedicated_public`: dedicated public IP per service, public DNS and stricter firewall profile.
- `hybrid`: private backend + optional dedicated public exposure depending on product options.

## Proposed Default Mapping

| Product Category | Example Product | Class | Notes |
|---|---|---|---|
| Internal app workers | queue/worker/db node | private_only | No direct client ingress. |
| Standard app hosting | n8n, web app VPS | shared_edge | Preferred default to conserve IPv4. |
| Generic managed VPS | Linux KVM VPS | shared_edge | Public via edge, optional upgrade path. |
| Mail hosting VPS | Postfix/Dovecot VPS | dedicated_public | PTR + mail DNS policy required. |
| Game server VPS | UDP-heavy game node | dedicated_public | Often requires direct public ports. |
| Compliance-bound workloads | custom enterprise VPS | dedicated_public | Isolation and deterministic addressing. |
| Mixed access products | custom hybrid plan | hybrid | Keep policy explicit per product. |

## Per-Product Decision Fields

For each product, capture these fields in policy:

- `service_class`
- `private_pool_id`
- `public_pool_id` (phase 3 table/logic)
- `firewall_profile_key`
- `strict_mode`
- `internal_dns_zone`
- `public_dns_zone`

## Rollout Order

1. `shared_edge` products (lowest risk, biggest IPv4 savings)
2. `private_only` products
3. `dedicated_public` products (after IPAM + firewall + public DNS adapter hardening)
4. `hybrid` products
