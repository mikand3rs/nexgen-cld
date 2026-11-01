# TLS / HTTPS (Stage B, opt-in)

BMOS ships HTTP-only by default. This guide enables HTTPS with local dev certs.

## Quick setup

```bash
# 1. Generate certs
bash scripts/generate-dev-certs.sh

# 2. Trust the CA (one-time per machine)
# Windows: import docker/traefik/certs/ca.crt via certmgr.msc
#   → Trusted Root Certification Authorities
# Linux: sudo cp docker/traefik/certs/ca.crt \
#   /usr/local/share/ca-certificates/bmos-ca.crt && sudo update-ca-certificates

# 3. Enable HTTPS in compose.yaml
#   - Add "443:443" to traefik ports
#   - Change nginx label entrypoints=web → websecure
#   - Add label: traefik.http.routers.bmos.tls=true

# 4. Enable HTTPS in docker/traefik/traefik.yaml
#   Add:
#     entryPoints:
#       web:
#         address: ":80"
#         http:
#           redirections:
#             entryPoint: {to: websecure, scheme: https, permanent: true}
#       websecure:
#         address: ":443"
#         http: {tls: {}}
#     tls:
#       stores:
#         default:
#           defaultCertificate:
#             certFile: /etc/traefik/certs/bmos.localhost.crt
#             keyFile: /etc/traefik/certs/bmos.localhost.key
#       options:
#         default:
#           minVersion: VersionTLS12

# 5. Restart
docker compose up -d --force-recreate traefik nginx
```

## Verify

```bash
bash scripts/test-tls.sh
```

## Production

Replace the self-signed certs with Let's Encrypt (Traefik ACME) or
proper certificates. Never commit `docker/traefik/certs/*.key`.
