#!/usr/bin/env bash
# Publication race under load (immutable served copies on Apache, 2.0.20). Anonymous readers
# (concurrent-readers.php) hammer the static routes while an editor pushes the same post over
# and over, with pages of varying size, and full exports swap the release. Every response must
# be a complete 200 equal to a release or served-copy file: Apache's stat-then-open race shows up
# as a page cut or padded to the size of another version (body_invalid / body_mismatch).
# Runs on the state run-e2e.sh leaves (plugin installed, release published, QA sessions); works on
# every stack, it is meant for SBQA_STACK=apache. Usage:
#   SBQA_STACK=apache bash tests/e2e/apache-race-e2e.sh [runs=5] [seconds=40] [exports-per-run=2]
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
RUNS=${1:-5}; SECS=${2:-40}; EXPORTS=${3:-2}
# Served copies are pruned 60 s after Apache stops serving them: a run must end before the first
# copy of the run can go, or a correct older body could no longer be found on disk.
[ "$SECS" -le 50 ] || { echo "seconds must be <= 50"; exit 2; }
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
S=$E/wordpress/wp-content/static-bridge
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
push_url() { curl -s -H @"$E/run/cookie-$1.txt" "$B/wp-admin/edit.php" | grep -oE "admin-post\.php\?action=static_bridge_push_post&(amp;|#038;)post_id=$2&(amp;|#038;)_wpnonce=[a-f0-9]+" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g'; }

[ "$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 $B/wp-login.php)" != "000" ] || { echo "Stack not running on $B"; exit 2; }
[ -L "$S/current" ] && [ -f "$S/current/hello-world/index.html" ] || { echo "No published release on $SBQA_STACK: run run-e2e.sh first"; exit 2; }
URL=$(push_url editor 1); [ -n "$URL" ] || { echo "No editor push link: run run-e2e.sh first"; exit 2; }
V=$(grep -m1 'Version:' "$E/wordpress/wp-content/plugins/static-bridge/static-bridge.php" | awk '{print $3}')
echo "== Publication race: $RUNS runs x ${SECS}s on $SBQA_STACK, Static Bridge $V"
TOTAL_REQ=0; TOTAL_PUB=0
for run in $(seq 1 "$RUNS"); do
    # Nginx serves current/ directly and a push replaces the page in place: an older complete
    # body may be gone by the end of the run. Apache serves immutable copies: strict check.
    MODE=""; [ "$SBQA_WEB" = "nginx" ] && MODE=replaced-ok
    $PHP84 "$H/concurrent-readers.php" "$SECS" $MODE > "$E/run/race-$run.json" & RD=$!
    # Full exports (atomic swap of current) spread over the run, in the background.
    ( for x in $(seq 1 "$EXPORTS"); do sleep $((SECS / (EXPORTS + 1))); php "$H/http-gui.php" administrator export > /dev/null 2>&1; done ) & EX=$!
    N=0; END=$((SECONDS + SECS - 2))
    while [ "$SECONDS" -lt "$END" ]; do
        # Alternate short and long pages so that consecutive versions differ in size.
        PAD=$(( N % 2 == 0 ? 200 + RANDOM % 400 : 6000 + RANDOM % 6000 ))
        $PHP84 "$H/wp69-harness.php" remark 1 "SBQA_RACE_${run}_${N}_$(head -c "$PAD" /dev/zero | tr '\0' x)" > "$E/run/race-remark.json" \
            || { echo "ABORT: could not edit the post: $(cat "$E/run/race-remark.json")"; kill "$RD" "$EX" 2> /dev/null; exit 2; }
        curl -s -o /dev/null -H @"$E/run/cookie-editor.txt" "$B/wp-admin/$URL"
        N=$((N + 1))
    done
    wait "$EX"; wait "$RD"; RC=$?
    # The pushes must really have published new versions of the page.
    grep -q "SBQA_RACE_${run}_" "$S/current/hello-world/index.html"; ok $? "run $run: the last pushes reached the published release"
    R=$(cat "$E/run/race-$run.json")
    REQ=$(echo "$R" | php -r '$j=json_decode(stream_get_contents(STDIN),true); echo (int)($j["requests"]??0);')
    TOTAL_REQ=$((TOTAL_REQ + REQ)); TOTAL_PUB=$((TOTAL_PUB + N + EXPORTS))
    ok "$RC" "run $run: $N pushes + $EXPORTS full exports while anonymous readers ran: $R"
    rm -f "$E/run/race-$run.json"
done
echo "APACHE RACE E2E RESULT: $PASS passed, $FAIL failed — stack $SBQA_STACK, $TOTAL_REQ reads during $TOTAL_PUB publications"
[ "$FAIL" = "0" ]
