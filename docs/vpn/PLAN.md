# VPN & Private Network — build plan

Source: the owner's "WorkSuite Cloud – VPN & Private Network Enhancement" spec (2026-11-27).

## What already exists (before phase 1)
- Server mesh: WireGuard 10.88.0.0/16 on UDP 51820 (`MeshService`, agent `executor/mesh.go`), CoreDNS `.internal` names, per-server SSH lock.
- Admin devices: `vpn_peers` on the mesh; private key shown once, never stored.
- Nothing for client VPN networks, OpenVPN, per-project/app access, policies or a user portal.

## Defaults taken (owner did not object, "continue", 2026-11-27)
- Client VPN ranges from 10.90.0.0/16, one /24 per network by default; the mesh and 10.89.0.0/16 container range stay reserved.
- Gateways: any server; DNS-and-proxy servers (frontdoor-01/02) are offered first. WireGuard default port UDP 51821 (51820 is the mesh), OpenVPN 1194.

## Phases (one PR each; merge and deploy only on the owner's OK)
1. **VPN networks** — `vpn_networks` table, API (`/api/v1/vpn/networks`, suggest), Create VPN network wizard, list. Records only. *(1.47.0)*
2. **Gateway deployment** — agent installs WireGuard on the gateway, keys, IP forwarding, nftables, DNS; validate/rollback/retry with deployment logs (`vpn_deployment_jobs`). Needs an agent release and rollout.
3. **Users & devices** — `vpn_users`, `vpn_devices`, `vpn_ip_allocations`; keys generated on the device where possible; expiry, revoke, device limits, connection log.
4. **Project & application access** — project Network & VPN tab, app Networking tab; inherit/VPN-only/hybrid; internal hostnames; databases never open to every VPN user by default.
5. **Access policies & monitoring** — policy engine enforced as gateway firewall rules and peer management; Overview dashboard (peers, handshakes, traffic).
6. **OpenVPN** — certificates (`vpn_certificates`), UDP/TCP.
7. **User portal** — auth.worksuitecloud.com sign-in + MFA enrolment, config download, WorkSuite Desktop connect.
