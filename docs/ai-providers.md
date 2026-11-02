# BMOS 3.2 — AI Providers (Phase 17)

One env var, `AI_PROVIDER`, selects the inference backend. Everything else —
auth, tenant scoping, persistence — stays in PHP. No provider ever receives
DB credentials or BMOS secrets; only the prompt text.

## Backends

| `AI_PROVIDER` | Backend | Needs | Default model |
|---|---|---|---|
| `lemonade` (default) | Lemonade Server on your Windows host (GPU) | `LEMONADE_URL`, `LEMONADE_MODEL` | `Qwen3.5-2B-GGUF` |
| `mistral` | Mistral La Plateforme API | `MISTRAL_API_KEY` | `mistral-large-latest` |
| `deepseek` | DeepSeek API | `DEEPSEEK_API_KEY` | `deepseek-flash` |
| `openai` | OpenAI API | `OPENAI_API_KEY` | `gpt-6-luna` |
| `groq` | Groq API (fast) | `GROQ_API_KEY` | `llama-3.3-70b-versatile` |
| `ollama` | Remote Ollama (LAN, Cloud Run GPU, …) | `OLLAMA_URL`, `OLLAMA_MODEL` | — (required) |
| `custom` | Any OpenAI-compatible endpoint | `AI_API_URL`, `AI_MODEL` | — (required) |

Override any default model: `MISTRAL_MODEL=…`, `DEEPSEEK_MODEL=…`,
`OPENAI_MODEL=…`, `GROQ_MODEL=…`. Model IDs change over time — if a default
stops working, check the provider's docs and override.

## API keys: env or Docker secret file

Preferred: a Docker secret file, so the key never sits in `env` output.

```bash
echo -n "sk-your-key" > secrets/deepseek_api_key.txt
chmod 600 secrets/deepseek_api_key.txt
```

Then in `compose.yaml` (php service), uncomment:

```yaml
secrets:
  - deepseek_api_key
environment:
  AI_PROVIDER: deepseek
  DEEPSEEK_API_KEY_FILE: /run/secrets/deepseek_api_key
```

The top-level `secrets:` block already has commented templates for
`mistral_api_key`, `deepseek_api_key`, `openai_api_key`, `groq_api_key`.
Quick local override without secrets: `DEEPSEEK_API_KEY=sk-…` in `.env`
(never commit `.env`).

## Cost reality (checked Nov 2026)

At portfolio/demo scale (1–2M tokens/month), pay-per-token costs **under
$1/month**. Paid tiers do **not** train on your data (that is a free-tier
term). Approximate list prices per million tokens, input / output:

- DeepSeek Flash (off-peak): $0.15 / $0.60 — cheapest capable option
- OpenAI gpt-6-luna: $0.10 / $0.50
- Groq gpt-oss-20B: $0.075 / $0.30
- Mistral Experiment free tier: ~1B tokens/month free, but **requires
  opting into data training** — fine for a demo, not for client data.

Self-hosted GPU only wins past ~100M+ tokens/month of sustained traffic.
Cloud Run L4 (~$1.05/hr when running) and serverless GPUs (RunPod/Modal)
are 10–100× more expensive at demo scale, plus cold starts.

## Switching

```bash
# .env
AI_PROVIDER=deepseek
# DEEPSEEK_API_KEY=sk-…   # or the secret-file route above
docker compose up -d php
curl http://bmos.localhost/ai/health   # → {"status":"ok","provider":"deepseek",...}
```

`/ai/health` shows `provider`, `model`, `endpoint` — never the key.
`GET /ai-ui` labels the chat page with the active provider.

## Security notes

- `ProviderFactory::describe()` is the only thing `/ai/health` exposes.
  It is asserted key-free by `scripts/test-ai-providers.sh` (the
  `no-key-leak` gate runs for every backend).
- The old Phase 8 gate still holds: the php container env must not contain
  `POSTGRES_PASSWORD`, and inference endpoints never get DB credentials.
- Tenant scoping is unchanged: `POST /ai/chat` requires a session and a
  verified tenant; the tenant id is never sent to the provider.

## Tests

```bash
bash scripts/test-ai-providers.sh   # 25 checks, all offline except the live /ai/health shape
bash scripts/test-lemonade.sh       # 6 checks — live Lemonade round-trip (default backend)
```
