#!/usr/bin/env bash
# Single source of truth for all quality checks. Run by CI and by the
# pre-commit hook, so a commit that passes locally passes in CI.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
step() { echo; echo "== $1"; }

step "PHP syntax"
# One php process per file IS this step, and no file's lint depends on
# another's, so run them as wide as the machine is. A parse error still
# announces itself on stderr and fails xargs.
git ls-files '*.php' \
    | xargs -P "$(getconf _NPROCESSORS_ONLN 2> /dev/null || echo 4)" -n 1 php -l > /dev/null \
    || fail=1
[ "$fail" -eq 0 ] && echo "OK"

step "ASCII only (no smart quotes, dashes, arrows in sources)"
# Text sources must be pure ASCII; file:line reported for any offender.
if git ls-files '*.php' '*.js' '*.css' '*.md' '*.sh' '*.svg' '*.yml' '*.json' '*.ps1' '*.htaccess' \
        | xargs grep -nP '[^\x00-\x7F]' 2>/dev/null; then
    echo "FAIL: non-ASCII bytes found (see above)"
    fail=1
else
    echo "OK"
fi

step "No secrets in the repo"
# Credentials never belong in the repo: no userinfo URLs, no tokens,
# no literal password assignments outside of docs describing the rule.
if git ls-files -z | xargs -0 grep -nE '(ftps?|https?)://[^/ ]+:[^/ ]+@|gho_[A-Za-z0-9]{16}|github_pat_[A-Za-z0-9]' 2>/dev/null; then
    echo "FAIL: possible credential found (see above)"
    fail=1
else
    echo "OK"
fi
if git ls-files '*.db' | grep .; then
    echo "FAIL: database files must not be committed"
    fail=1
fi

step "PHP consistency (strict_types in every file)"
# One awk pass rather than a head and a grep per file. Same rule: only
# the first three lines count, so a mention further down still fails.
missing=$(git ls-files 'public/*.php' | xargs awk '
    FNR <= 3 && /declare\(strict_types=1\)/ { ok[FILENAME] = 1 }
    END { for (i = 1; i < ARGC; i++) if (!(ARGV[i] in ok)) print ARGV[i] }')
if [ -n "$missing" ]; then
    echo "$missing" | while IFS= read -r f; do
        echo "FAIL: $f is missing declare(strict_types=1)"
    done
    fail=1
fi
[ "$fail" -eq 0 ] && echo "OK"

step "Unit tests"
php test/unit.php || fail=1

step "Smoke test (real HTTP against php -S)"
bash test/smoke.sh || fail=1

echo
if [ "$fail" -ne 0 ]; then
    echo "CHECKS FAILED"
    exit 1
fi
echo "ALL CHECKS PASSED"
