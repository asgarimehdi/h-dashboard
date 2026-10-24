#!/usr/bin/env bash
# Coverage reporting entrypoint (issue #865).
# Usage: bash scripts/coverage-report.sh [--clover coverage.xml]
#
# Non-blocking by design: this script REPORTS, it never gates. It always
# exits 0 unless its own inputs are missing (wrong invocation), so the
# `coverage-report` CI job can never turn red on a measurement change.
#
# What it reports:
#   1. The app/ denominator number (lines covered / valid, from the Clover
#      file CI's `test` job already produces) — the baseline every later
#      decision depends on.
#   2. The Blade component executable-line count (static measure of the logic
#      that lives outside the coverage denominator), with the method the
#      #865 audit used: `return new class extends Component` range per file,
#      blank and comment-only lines stripped.
#   3. The attribution verdict: whether compiled-Blade coverage can be mapped
#      back to source with stock pcov/PHPUnit. (Verified 2026-10-08: NO —
#      Livewire test mode compiles views per-worker under hashed
#      storage/framework/views/test_N/ paths, so `--coverage-filter` on the
#      compiled dir reports 0.0% per file and inflates the denominator with
#      dead copies. See issue #865's expert review.)
#   4. The per-component table for resources/views/livewire (issue #1045):
#      public methods and test references per Blade file, worst first, with
#      the zero-reference list — the visibility the app/ denominator cannot
#      give. A component nobody tests moves no number today; this makes it a
#      line in the log instead.
#
# The hard `--min=80` gate is deliberately untouched: changing it before a
# real total exists is out of scope (locked decision in #865's git review).
set -uo pipefail
cd "$(dirname "$0")/.."

CLOVER="coverage.xml"
for arg in "$@"; do
    case "$arg" in
        --clover=*) CLOVER="${arg#--clover=}" ;;
        --clover) shift; CLOVER="${1:-coverage.xml}" ;;
    esac
done

echo "=== Coverage report (issue #865 — informational, non-blocking) ==="
echo ""

# 1. app/ denominator from the Clover report.
if [ -f "$CLOVER" ]; then
    METRICS=$(grep -o '<metrics files="[0-9]*"[^/]*/>' "$CLOVER" | head -1)
    if [ -n "$METRICS" ]; then
        STATEMENTS=$(echo "$METRICS" | grep -o 'statements="[0-9]*"' | head -1 | grep -o '[0-9]*')
        COVERED=$(echo "$METRICS" | grep -o 'coveredstatements="[0-9]*"' | head -1 | grep -o '[0-9]*')
        if [ -n "$STATEMENTS" ] && [ "$STATEMENTS" -gt 0 ] 2>/dev/null; then
            PCT=$(php -r "printf('%.2f', 100 * $COVERED / $STATEMENTS);")
            echo "app/ coverage: ${PCT}% (${COVERED}/${STATEMENTS} statements)"
        else
            echo "app/ coverage: could not parse statement counts from $CLOVER"
        fi
    else
        echo "app/ coverage: no project-level <metrics> in $CLOVER"
    fi
else
    echo "app/ coverage: no clover file at $CLOVER (run the suite with --coverage-clover first)"
fi
echo ""

# 2. Blade component executable-line count (static, same method as #865).
BLADE_TOTAL=0
BLADE_FILES=0
for f in $(grep -rl "return new class extends Component" resources/views/livewire/ 2>/dev/null); do
    COUNT=$(php -r '
        $src = file_get_contents($argv[1]);
        $start = strpos($src, "return new class extends Component");
        if ($start === false) { echo 0; exit; }
        $end = strpos($src, "};", $start);
        if ($end === false) { echo 0; exit; }
        $range = substr($src, $start, $end - $start);
        $n = 0;
        foreach (explode("\n", $range) as $line) {
            $t = trim($line);
            if ($t === "") continue;
            if (str_starts_with($t, "//") || str_starts_with($t, "*") || str_starts_with($t, "/*") || str_starts_with($t, "#")) continue;
            $n++;
        }
        echo $n;
    ' "$f")
    BLADE_TOTAL=$((BLADE_TOTAL + COUNT))
    BLADE_FILES=$((BLADE_FILES + 1))
done
echo "Blade component logic (outside coverage denominator): ${BLADE_TOTAL} executable lines across ${BLADE_FILES} files"
echo ""

# 3. Attribution verdict (verified, not re-derived per run).
echo "Blade attribution: NOT mappable with stock pcov/PHPUnit —"
echo "  Livewire test mode compiles views per-worker to hashed"
echo "  storage/framework/views/test_N/ paths; --coverage-filter on the"
echo "  compiled dir yields 0.0% per file (verified 2026-10-08)."
echo "  Component behaviour IS executed by Livewire::test(...) suites;"
echo "  only the measurement is blind."
echo ""
# 4. Per-component visibility (issue #1045): every Blade file the app/
#    denominator cannot see, with the public methods it declares and the
#    number of tests that name it. Informational like everything above — it
#    cannot fail the job (the script still ends in `exit 0`).
echo "=== Livewire component visibility (issue #1045 — informational) ==="
echo ""

ROWS=""
FILE_COUNT=0
METHOD_TOTAL=0
REF_TOTAL=0

for f in $(find resources/views/livewire -name '*.blade.php' | sort); do
    REL="${f#resources/views/livewire/}"
    REL="${REL%.blade.php}"
    # Livewire's Finder strips the ⚡ prefix (and its variation selectors)
    # from SFC names, and the suites spell a component with EITHER
    # separator — `tickets.inbox` and `tickets/inbox` name the same file.
    SLASH=$(printf '%s' "$REL" | sed $'s/\xe2\x9a\xa1[\xef\xb8\x8e\xef\xb8\x9f]\{0,1\}//g')
    DOT=$(printf '%s' "$SLASH" | tr '/' '.')

    METHODS=$(grep -o "public function" "$f" | wc -l)
    REFS=$(grep -rhoF -e "test('$DOT'" tests/ | wc -l)
    if [ "$SLASH" != "$DOT" ]; then
        # Only for nested files: for a root-level file the two patterns are
        # identical, and `grep -F -e p -e p` prints every match TWICE.
        REFS=$((REFS + $(grep -rhoF -e "test('$SLASH'" tests/ | wc -l)))
    fi

    # A Blade file with no anonymous component class is a partial view: it
    # runs inside its parent's tests, so it is never named by Livewire::test().
    if grep -q "new class extends" "$f"; then
        KIND="component-class"
    else
        KIND="partial-view"
    fi

    FILE_COUNT=$((FILE_COUNT + 1))
    METHOD_TOTAL=$((METHOD_TOTAL + METHODS))
    REF_TOTAL=$((REF_TOTAL + REFS))
    ROWS="${ROWS}${REFS} ${METHODS} ${KIND} ${DOT}"$'\n'
done

echo "Worst first (fewest test references, then most public methods):"
echo ""
printf '%-42s %8s %11s\n' "COMPONENT" "METHODS" "TEST-REFS"
printf '%s' "$ROWS" | sort -k1,1n -k2,2nr -k4,4 | while read -r refs methods kind name; do
    printf '%-42s %8s %11s\n' "$name" "$methods" "$refs"
done
echo ""

ZERO_COUNT=$(printf '%s' "$ROWS" | grep -c '^0 ')
echo "Zero test references: ${ZERO_COUNT} of ${FILE_COUNT} files"
printf '%s' "$ROWS" | sort -k1,1n -k2,2nr -k4,4 | awk '$1 == 0 { printf "  - %s (%s public methods; %s)\n", $4, $2, $3 }'
echo ""
echo "Totals: ${FILE_COUNT} files, ${METHOD_TOTAL} public methods, ${REF_TOTAL} test references."
echo "METHODS = \`public function\` occurrences in the file. TEST-REFS ="
echo "\`test('<name>'\` occurrences under tests/ where <name> is this file's"
echo "own name in dot OR slash form (Livewire accepts both, ⚡ stripped the"
echo "way its Finder derives SFC names). Dynamic Livewire::test(\$component)"
echo "calls name no file, so they cannot be attributed and are not counted."
echo ""

# Gate status (issue #865 — locked decision, untouched by #1045).
echo "Gate status: --min=80 on app/ unchanged (hard gate out of scope until a real total exists)."
echo "=== end coverage report ==="
exit 0
