#!/usr/bin/env bash
# Fails if a directory about to be published has anything that must stay private.
set -euo pipefail
dir="${1:?usage: scrub-check.sh <dir>}"
self="$(basename "$0")"
fail=0

bad_files=$(find "$dir" -path '*/.git' -prune -o \( -iname 'CLAUDE.md' -o -iname 'AGENTS.md' -o -name '.claude' -o -name '.cursor*' -o -name '.env*' \) -not -path '*/node_modules/*' -not -path '*/vendor/*' -print)
if [ -n "$bad_files" ]; then echo "Private files:"; echo "$bad_files"; fail=1; fi

# \xe2\x80\x94 is an em dash.
pattern=$'\xe2\x80\x94|claude|anthropic|co-authored-by|generated (with|by) (ai|an ai)|chatgpt|copilot|openai'
hits=$(grep -rniIE "$pattern" "$dir" --exclude="$self" --exclude-dir=.git --exclude-dir=node_modules --exclude-dir=vendor --exclude-dir=.venv --exclude-dir=bin --exclude-dir=obj --exclude-dir=target --exclude-dir=dist || true)
if [ -n "$hits" ]; then echo "Forbidden text:"; echo "$hits"; fail=1; fi

exit $fail
