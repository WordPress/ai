#!/usr/bin/env bash
#
# Local CI - mirrors .github/workflows/test.yml checks.
#
# Usage:
#   ./tools/local-ci.sh              # Run all checks (PHPCS, PHPStan, JS lint, TypeScript, PHPUnit)
#   ./tools/local-ci.sh --quick      # Skip PHPUnit (no Docker required)
#   ./tools/local-ci.sh phpcs        # Run a single check
#   ./tools/local-ci.sh phpunit      # Run PHPUnit only (requires wp-env)
#
# Available checks: phpcs, phpstan, jslint, typecheck, phpunit
#
# Prerequisites:
#   - composer install && npm ci
#   - For PHPUnit: Docker running + `npm run wp-env:test start`

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
BOLD='\033[1m'
NC='\033[0m'

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$PROJECT_DIR"

passed=()
failed=()
skipped=()

run_check() {
    local name="$1"
    local description="$2"
    shift 2

    printf "\n${BOLD}── %s ──${NC}\n" "$description"
    if "$@"; then
        passed+=("$name")
        printf "${GREEN}PASS${NC} %s\n" "$name"
    else
        failed+=("$name")
        printf "${RED}FAIL${NC} %s\n" "$name"
    fi
}

skip_check() {
    local name="$1"
    local reason="$2"
    skipped+=("$name")
    printf "${YELLOW}SKIP${NC} %s (%s)\n" "$name" "$reason"
}

# ── PHPCS ────────────────────────────────────────────────────────────────────
check_phpcs() {
    run_check "phpcs" "PHPCS coding standards" \
        vendor/bin/phpcs --standard=phpcs.xml.dist --report=full
}

# ── PHPStan ──────────────────────────────────────────────────────────────────
check_phpstan() {
    # PHPStan needs built JS assets (build/build.php, etc.)
    if [ ! -f build/build.php ]; then
        printf "  Building assets for PHPStan...\n"
        npm run build --silent 2>/dev/null || true
    fi
    vendor/bin/phpstan clear-result-cache >/dev/null 2>&1 || true
    run_check "phpstan" "PHPStan static analysis" \
        vendor/bin/phpstan analyse --memory-limit=1G
}

# ── JS lint ──────────────────────────────────────────────────────────────────
check_jslint() {
    # Only lint git-tracked files to match CI behaviour (untracked local
    # scripts would cause false failures).
    local tracked_files
    tracked_files=$(git ls-files 'src/**/*.js' 'src/**/*.ts' 'src/**/*.tsx' 'src/**/*.mjs')
    if [ -z "$tracked_files" ]; then
        skip_check "jslint" "no tracked JS/TS files"
        return
    fi
    # shellcheck disable=SC2086
    run_check "jslint" "JavaScript linting (ESLint + Prettier)" \
        npx wp-scripts lint-js --no-error-on-unmatched-pattern $tracked_files
}

# ── TypeScript ───────────────────────────────────────────────────────────────
check_typecheck() {
    run_check "typecheck" "TypeScript type checking" \
        npm run typecheck
}

# ── PHPUnit ──────────────────────────────────────────────────────────────────
check_phpunit() {
    if ! docker info &>/dev/null; then
        skip_check "phpunit" "Docker not running"
        return
    fi

    # Check if wp-env is running
    if ! npm run wp-env:test -- run cli wp core version &>/dev/null 2>&1; then
        printf "  Starting wp-env test environment...\n"
        npm run wp-env:test start 2>/dev/null || {
            skip_check "phpunit" "wp-env failed to start"
            return
        }
    fi

    run_check "phpunit" "PHPUnit tests" \
        npm run test:php
}

# ── Summary ──────────────────────────────────────────────────────────────────
print_summary() {
    printf "\n${BOLD}═══ Summary ═══${NC}\n"
    for name in "${passed[@]+"${passed[@]}"}"; do
        printf "  ${GREEN}✓${NC} %s\n" "$name"
    done
    for name in "${skipped[@]+"${skipped[@]}"}"; do
        printf "  ${YELLOW}○${NC} %s (skipped)\n" "$name"
    done
    for name in "${failed[@]+"${failed[@]}"}"; do
        printf "  ${RED}✗${NC} %s\n" "$name"
    done

    if [ ${#failed[@]} -gt 0 ]; then
        printf "\n${RED}${BOLD}%d check(s) failed.${NC} Fix before pushing.\n" "${#failed[@]}"
        return 1
    fi
    printf "\n${GREEN}${BOLD}All checks passed.${NC}\n"
}

# ── Main ─────────────────────────────────────────────────────────────────────
quick=false
checks=()

for arg in "$@"; do
    case "$arg" in
        --quick) quick=true ;;
        phpcs|phpstan|jslint|typecheck|phpunit) checks+=("$arg") ;;
        --help|-h)
            head -14 "$0" | tail -12
            exit 0
            ;;
        *)
            printf "Unknown argument: %s\n" "$arg"
            exit 1
            ;;
    esac
done

# Default: run all checks
if [ ${#checks[@]} -eq 0 ]; then
    if $quick; then
        checks=(phpcs phpstan jslint typecheck)
    else
        checks=(phpcs phpstan jslint typecheck phpunit)
    fi
fi

printf "${BOLD}Local CI - %d check(s)${NC}\n" "${#checks[@]}"

for check in "${checks[@]}"; do
    "check_$check" || true
done

print_summary
