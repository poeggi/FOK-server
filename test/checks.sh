#!/usr/bin/env bash
# Single source of truth for all quality checks. Run by CI and by the
# pre-commit hook, so a commit that passes locally passes in CI.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
step() { echo; echo "== $1"; }

step "PHP syntax"
while IFS= read -r f; do
    php -l "$f" > /dev/null || fail=1
done < <(git ls-files '*.php')
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
while IFS= read -r f; do
    if ! head -n 3 "$f" | grep -q 'declare(strict_types=1)'; then
        echo "FAIL: $f is missing declare(strict_types=1)"
        fail=1
    fi
done < <(git ls-files 'public/*.php')
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
