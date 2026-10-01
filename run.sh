#!/usr/bin/env bash
# Runs the withDeferred benchmark on the checked-out branch and on its base.
#   STORM=/path/to/storm BASE=develop BENCH_DB_PASSWORD=... ./run.sh
# Writes fixed.json and develop.json next to this script.
set -euo pipefail

STORM="${STORM:-$(pwd)}"
BASE="${BASE:-HEAD~1}"
BENCH="$(cd "$(dirname "$0")" && pwd)"
export BENCH_OUT="$BENCH"
export BENCH_SIZES="${BENCH_SIZES:-1000,10000,50000,100000,250000,500000,1000000}"

cd "$STORM"
git diff --quiet -- src || { echo "src/ has uncommitted changes"; exit 1; }
trap 'git checkout -q HEAD -- src' EXIT

echo "== with fix ($(git rev-parse --short HEAD))"
BENCH_LABEL=fixed php vendor/bin/phpunit "$BENCH/WithDeferredBenchmark.php" 2>&1 | grep -E "rows:|OK|FAIL|Error"

git checkout -q "$BASE" -- src
echo "== base ($(git rev-parse --short "$BASE"))"
BENCH_LABEL=develop php vendor/bin/phpunit "$BENCH/WithDeferredBenchmark.php" 2>&1 | grep -E "rows:|OK|FAIL|Error"
