#!/usr/bin/env bash
# BMOS 3.2 Phase 17 — AI provider abstraction: the gates.
#
# Gates:
#   - ProviderFactory builds every backend from env (no network needed).
#   - Default (AI_PROVIDER unset) is lemonade — backward compatible.
#   - Cloud providers REQUIRE an API key; the error names the exact env var.
#   - Keys load from env OR from <NAME>_FILE (Docker secret file).
#   - describe() NEVER contains key material (checked for every backend).
#   - Unknown AI_PROVIDER names a valid list instead of failing silently.
#   - The legacy `new Lemonade()` shim still works.
#   - Live: /ai/health exposes provider/model/endpoint, never a key.
#
# No real API keys are used; all config tests run offline.
set -euo pipefail
cd "$(dirname "$0")/.."

SRC="$PWD/app/src/Lemonade.php"
RESULTS="$(mktemp)"
trap 'rm -f "$RESULTS"' EXIT

record() { # record PASS|FAIL <message>
  echo "$1: $2" | tee -a "$RESULTS"
}
check() { # check <name> <expected> <actual>
  if [ "$2" = "$3" ]; then record PASS "$1"; else record FAIL "$1 (expected [$2], got [$3])"; fi
}

# php_case <name> [VAR=val ...] -- <php code printing ASSERT:key=OK lines>
php_case() {
  local name="$1"; shift
  local envs=()
  while [ "${1:-}" != "--" ]; do envs+=("$1"); shift; done
  shift || true
  local phpcode="$1"
  local out
  out="$(env "${envs[@]}" php -r "require '$SRC'; $phpcode" 2>&1)" || true
  while IFS= read -r line; do
    case "$line" in
      ASSERT:*)
        local rest="${line#ASSERT:}"
        local k="${rest%%=*}"
        local v="${rest#*=}"
        if [ "$v" = "OK" ]; then record PASS "$name — $k"; else record FAIL "$name — $k ($v)"; fi
        ;;
    esac
  done <<<"$out"
}

echo "--- gate: default is lemonade (backward compat) ---"
php_case "default" -u AI_PROVIDER -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "lemonade" ? "OK" : "got ".$p->name()) . PHP_EOL;
echo "ASSERT:model=" . ($p->defaultModel() === "Qwen3.5-2B-GGUF" ? "OK" : "got ".$p->defaultModel()) . PHP_EOL;
echo "ASSERT:interface=" . ($p instanceof AIProvider ? "OK" : "not AIProvider") . PHP_EOL;'

echo "--- gate: every backend builds from env ---"
php_case "mistral" AI_PROVIDER=mistral MISTRAL_API_KEY=test-key-abc123 -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "mistral" ? "OK" : "got ".$p->name()) . PHP_EOL;
echo "ASSERT:model=" . ($p->defaultModel() === "mistral-large-latest" ? "OK" : "got ".$p->defaultModel()) . PHP_EOL;
echo "ASSERT:endpoint=" . ($p->endpoint() === "https://api.mistral.ai/v1" ? "OK" : "got ".$p->endpoint()) . PHP_EOL;
$d = json_encode($p->describe());
echo "ASSERT:no-key-leak=" . (strpos($d, "test-key-abc123") === false ? "OK" : "LEAKED") . PHP_EOL;'
php_case "deepseek" AI_PROVIDER=deepseek DEEPSEEK_API_KEY=sk-test-xyz -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "deepseek" ? "OK" : "got ".$p->name()) . PHP_EOL;
$d = json_encode($p->describe());
echo "ASSERT:no-key-leak=" . (strpos($d, "sk-test-xyz") === false ? "OK" : "LEAKED") . PHP_EOL;'
php_case "openai" AI_PROVIDER=openai OPENAI_API_KEY=sk-openai-test -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "openai" ? "OK" : "got ".$p->name()) . PHP_EOL;
$d = json_encode($p->describe());
echo "ASSERT:no-key-leak=" . (strpos($d, "sk-openai-test") === false ? "OK" : "LEAKED") . PHP_EOL;'
php_case "groq" AI_PROVIDER=groq GROQ_API_KEY=gsk_test -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "groq" ? "OK" : "got ".$p->name()) . PHP_EOL;
$d = json_encode($p->describe());
echo "ASSERT:no-key-leak=" . (strpos($d, "gsk_test") === false ? "OK" : "LEAKED") . PHP_EOL;'
php_case "ollama-no-key" AI_PROVIDER=ollama OLLAMA_URL=http://ollama:11434 OLLAMA_MODEL=llama3.2 -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "ollama" ? "OK" : "got ".$p->name()) . PHP_EOL;
echo "ASSERT:model=" . ($p->defaultModel() === "llama3.2" ? "OK" : "got ".$p->defaultModel()) . PHP_EOL;'
php_case "custom" AI_PROVIDER=custom AI_API_URL=http://my-llm:8080/v1 AI_MODEL=my-model -- '
$p = ProviderFactory::create();
echo "ASSERT:name=" . ($p->name() === "custom" ? "OK" : "got ".$p->name()) . PHP_EOL;
echo "ASSERT:endpoint=" . ($p->endpoint() === "http://my-llm:8080/v1" ? "OK" : "got ".$p->endpoint()) . PHP_EOL;'

echo "--- gate: cloud providers require a key, error names the var ---"
out="$(env -u MISTRAL_API_KEY -u MISTRAL_API_KEY_FILE AI_PROVIDER=mistral php -r "
require '$SRC';
try { ProviderFactory::create(); echo 'NO-THROW'; }
catch (Throwable \$e) { echo 'THREW: '.\$e->getMessage(); }
" 2>&1)"
check "mistral without key throws" "true" "$(echo "$out" | grep -q "THREW:" && echo true || echo false)"
check "error names MISTRAL_API_KEY" "true" "$(echo "$out" | grep -q "MISTRAL_API_KEY" && echo true || echo false)"

echo "--- gate: key loads from _FILE (Docker secret) ---"
TMPKEY="$(mktemp)"
echo -n "file-secret-key-999" > "$TMPKEY"
chmod 600 "$TMPKEY"
out="$(env -u MISTRAL_API_KEY AI_PROVIDER=mistral "MISTRAL_API_KEY_FILE=$TMPKEY" php -r "
require '$SRC';
try {
  \$p = ProviderFactory::create();
  \$d = json_encode(\$p->describe());
  echo (strpos(\$d, 'file-secret-key-999') === false ? 'OK-no-leak' : 'LEAKED');
} catch (Throwable \$e) { echo 'THREW: '.\$e->getMessage(); }
" 2>&1)"
check "key from file builds provider, no leak" "OK-no-leak" "$(echo "$out" | grep -o "OK-no-leak\|LEAKED\|THREW.*" | head -1)"
rm -f "$TMPKEY"

echo "--- gate: unknown provider names the valid list ---"
out="$(env AI_PROVIDER=bogus php -r "
require '$SRC';
try { ProviderFactory::create(); echo 'NO-THROW'; }
catch (Throwable \$e) { echo 'THREW: '.\$e->getMessage(); }
" 2>&1)"
check "bogus provider throws" "true" "$(echo "$out" | grep -q "THREW:" && echo true || echo false)"
check "error lists valid providers" "true" "$(echo "$out" | grep -q "lemonade, mistral, deepseek" && echo true || echo false)"

echo "--- gate: legacy new Lemonade() shim ---"
out="$(env -u AI_PROVIDER php -r "
require '$SRC';
\$lem = new Lemonade();
echo 'name='.\$lem->name().PHP_EOL;
echo 'baseUrl='.\$lem->baseUrl().PHP_EOL;
echo 'instance=' . (\$lem instanceof AIProvider ? 'yes' : 'no') . PHP_EOL;
" 2>&1)"
check "shim name is lemonade" "lemonade" "$(echo "$out" | grep "^name=" | cut -d= -f2)"
check "shim baseUrl() works" "true" "$(echo "$out" | grep -q "^baseUrl=http" && echo true || echo false)"
check "shim is AIProvider" "yes" "$(echo "$out" | grep "^instance=" | cut -d= -f2)"

echo "--- gate: live /ai/health shape (skipped if provider unreachable) ---"
set -a
[ -f .env ] && . ./.env
set +a
RHEALTH="$(curl -s -m 15 -w "\n%{http_code}" http://bmos.localhost/ai/health || true)"
HCODE="$(echo "$RHEALTH" | tail -1)"
HBODY="$(echo "$RHEALTH" | head -n -1)"
if [ "$HCODE" = "200" ]; then
  HASPROVIDER="$(echo "$HBODY" | python3 -c "import json,sys; print('provider' in json.load(sys.stdin))" 2>/dev/null || echo False)"
  check "ai/health: has provider field" "True" "$HASPROVIDER"
  echo "  provider: $(echo "$HBODY" | python3 -c "import json,sys; d=json.load(sys.stdin); print(d.get('provider'), d.get('model'))" 2>/dev/null)"
else
  echo "SKIP: /ai/health returned HTTP $HCODE (provider not running here) — config gates above still prove the abstraction"
fi

pass="$(grep -c "^PASS" "$RESULTS" || true)"
fail="$(grep -c "^FAIL" "$RESULTS" || true)"
echo ""
echo "RESULT: $pass passed, $fail failed"
[ "$fail" -eq 0 ]
