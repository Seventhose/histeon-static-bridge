#!/usr/bin/env bash
# Fails when a tracked file looks like it holds a secret (CI and before every push): private keys, GitHub, AWS,
# Cloudflare and OpenAI-style tokens, and a password or secret written as a literal. Test fixtures use names that
# say so (example, test, dummy, fake) and stay out.
set -euo pipefail
cd "$(dirname "$0")/.."
pattern='-----BEGIN [A-Z ]*PRIVATE KEY-----|gh[pousr]_[A-Za-z0-9]{36,}|github_pat_[A-Za-z0-9_]{50,}|AKIA[0-9A-Z]{16}|sk-[A-Za-z0-9]{32,}|(password|passwd|secret|api[_-]?key|token)["'"'"']?\s*[:=]\s*["'"'"'][A-Za-z0-9+/_=-]{20,}["'"'"']'
hits=$(git ls-files -z | xargs -0 grep -nIiE -e "$pattern" -- | grep -viE 'example|dummy|fake|test-key|placeholder|xxxxx' | grep -vE '([a-z0-9])\1{7}-' || true)
if [ -n "$hits" ]; then
  echo "Possibili segreti nei file del repository:" >&2
  echo "$hits" | cut -c1-200 >&2
  exit 1
fi
echo "Nessun segreto trovato."
