#!/usr/bin/env bash
# Release-retention and «Versioni» scenarios (Static Bridge 2.0.13, 2.4.0) on the disposable stack selected by SBQA_STACK.
# Run AFTER run-e2e.sh on the same ZIP: it reuses the releases and the QA sessions that run created. A site activated
# by run-e2e is a new site (2.4.0: retention on, one previous release); the upgrade path is simulated with settings
# saved by an older version (QA legacy-settings), where retention stays off and Versioni only offers it.
# Touches only $SBQA_HOME/<env>/wordpress/wp-content/static-bridge and the stack DB.
# QA-only manipulations are labelled "(QA)": backdating folder mtimes, pointing the imported-DB
# release record at an old release, adding a dependency-index row, moving the cron event to now.
# Usage: [SBQA_STACK=apache] bash retention-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
sbqa_require_stack; E=$SBQA_E
PHP84="env LD_LIBRARY_PATH=$P/shared-libs $P/bin/php -n -c $E/conf/php.ini"
WP=$E/wordpress
S=$WP/wp-content/static-bridge
PASS=0; FAIL=0
ok() { if [ "$1" = "0" ]; then PASS=$((PASS+1)); echo "[PASS] $2"; else FAIL=$((FAIL+1)); echo "[FAIL] $2${3:+ :: $3}"; fi; }
jget() { php -r '$j=json_decode(stream_get_contents(STDIN),true); $v=$j; foreach(explode(".",$argv[1]) as $k){ $v=is_array($v)&&array_key_exists($k,$v)?$v[$k]:null; } echo is_scalar($v)?$v:json_encode($v);' "$1"; }
st() { $PHP84 "$H/retention-qa.php" state; }
qa() { $PHP84 "$H/retention-qa.php" "$@" > /dev/null; }
gui() { $PHP84 "$H/retention-gui.php" "$@"; }
export_final() { php "$H/http-gui.php" administrator export | jget final; }
listing() { ls -A "$S/releases" | sort; }
plugin_link() { curl -s -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/plugins.php" | grep -oE "plugins\.php\?action=$1&(amp;|#038;)plugin=static-bridge%2Fstatic-bridge\.php[^\"']*" | head -1 | sed 's/&amp;/\&/g; s/&#038;/\&/g'; }
# Expected retention decisions, computed independently of the plugin from a state snapshot:
# keep current, protected ids (the job's folder only while it runs or is paused), the <keep> newest
# unprotected ready releases and staging younger than an hour (2.4.0); delete the rest of the ready
# releases and older staging; ignore anything else.
expected_delete() {
    php -r '
        $s = json_decode(stream_get_contents(STDIN), true); $keep = (int) $argv[1];
        $job = in_array($s["job"]["status"] ?? "", array("running", "paused"), true) ? ($s["job"]["release_id"] ?? "") : "";
        $protected = array_filter(array_merge(array($s["current"], $s["last_release"], $job), array_slice($argv, 2)));
        $ready = array(); $delete = array();
        foreach ($s["releases"] as $name => $r) {
            if ($r["link"] || !$r["dir"] || !preg_match("/\\A[A-Za-z0-9_-]{1,100}\\z/", $name) || in_array($name, $protected, true)) { continue; }
            if ($r["ready"]) { $ready[$name] = $r["mtime"]; }
            elseif ($s["now"] - $r["mtime"] >= 3600) { $delete[] = $name; }
        }
        uksort($ready, function ($a, $b) use ($ready) { return $ready[$a] === $ready[$b] ? strcmp($b, $a) : $ready[$b] <=> $ready[$a]; });
        $delete = array_merge($delete, array_slice(array_keys($ready), $keep));
        sort($delete); echo implode(" ", $delete);
    ' "$@"
}

[ "$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 $B/wp-login.php)" != "000" ] || { echo "Stack not running on $B"; exit 2; }
case "$S" in "$SBQA_HOME/$SBQA_ENV_NAME/wordpress/wp-content/static-bridge") ;; *) echo "REFUSED: unexpected static root $S"; exit 2 ;; esac
# Retention exists from 2.0.13 on; refuse older builds.
IV=$(grep -m1 -oE "Version: [0-9][0-9.a-z-]*" "$WP/wp-content/plugins/static-bridge/static-bridge.php" | cut -d" " -f2)
php -r 'exit(version_compare($argv[1], "2.0.13", ">=") ? 0 : 1);' "$IV" || { echo "REFUSED: installed plugin $IV predates retention (2.0.13); run run-e2e.sh with a 2.0.13+ ZIP first"; exit 2; }
[ -L "$S/current" ] || { echo "REFUSED: no current release (run run-e2e.sh first)"; exit 2; }
: > "$E/logs/php-errors.log"; rm -f "$E/run/textdomain-trace.log" "$E/run/fault.json"
SENT="$E/run/retention-sentinel"; rm -rf "$SENT"; mkdir -p "$SENT/sub"
echo "outside the release root" > "$SENT/keep.txt"; echo "nested outside" > "$SENT/sub/keep.txt"
SENT_SUM=$(cd "$SENT" && find . -type f -exec sha256sum {} + | sort | sha256sum)

echo "== A new site (activated by run-e2e): retention on, one previous release"
V=$(gui settings-view)
[ "$(echo "$V" | jget checked)" = "1" ] && [ "$(echo "$V" | jget keep)" = "1" ] && [ "$(st | jget retention_enabled)" = "1" ]
ok $? "Settings tab: retention switched on with keep=1 on a new site" "$V"

echo "== An upgraded site (settings saved before 2.4.0): retention stays off, Versioni offers it"
qa legacy-settings
V=$(gui settings-view)
[ "$(echo "$V" | jget checked)" = "" ] && [ "$(echo "$V" | jget keep)" = "3" ]; ok $? "Settings tab: switch unchecked, keep=3 as before" "$V"
for i in 1 2 3 4 5 6; do
    PREV=$(st | php -r '$s=json_decode(stream_get_contents(STDIN),true); echo count(array_filter($s["releases"], fn($v,$k)=>$v["ready"] && $k!==$s["current"], ARRAY_FILTER_USE_BOTH));')
    [ "$PREV" -ge 5 ] && break
    BEFORE=$(listing); F=$(export_final); AFTER=$(listing)
    [ "$(echo "$F" | jget status)" = "complete" ] && [ -z "$(comm -23 <(echo "$BEFORE") <(echo "$AFTER"))" ] && [ "$(st | jget next_retention)" = "0" ] || { ok 1 "an export with retention off deleted or scheduled something" "$F"; break; }
done
ok 0 "exports with retention off delete and schedule nothing ($PREV previous ready releases)"
A=$(gui versions)
[ "$(echo "$A" | jget http)" = "200" ] && [ "$(echo "$A" | jget offer)" = "1" ] && [ "$(echo "$A" | jget cleanup_form)" = "1" ] && [ "$(echo "$A" | jget confirm)" -ge 2 ]
ok $? "Versioni: offer to switch retention on, a bulk cleanup and deletions that ask first" "$(echo "$A" | jget text | head -c 300)"
A=$(gui activity); [ "$(echo "$A" | jget apply_form)" = "" ] && echo "$A" | jget text | grep -q "Versioni"; ok $? "Attività points to Versioni"

echo "== Capability, nonce and names"
BEFORE=$(listing)
R=$(gui versions-post static_bridge_cleanup_releases "" editor); [ "$(echo "$R" | jget http)" = "403" ]; ok $? "editor cannot run the cleanup (403)" "$R"
R=$(gui versions-post static_bridge_cleanup_releases "" administrator bad-nonce); [ "$(echo "$R" | jget http)" = "403" ]; ok $? "a bad nonce is refused (403)" "$R"
ONE=$(gui versions | php -r '$a=json_decode(stream_get_contents(STDIN),true); foreach($a["blocks"] as $n=>$b){ if($b["deletable"]){ echo $n; break; } }')
R=$(gui versions-post static_bridge_delete_release "$ONE" editor); [ "$(echo "$R" | jget http)" = "403" ]; ok $? "editor cannot delete a version (403)"
R=$(gui versions-post static_bridge_delete_release "$ONE" administrator bad-nonce); [ "$(echo "$R" | jget http)" = "403" ]; ok $? "a deletion with a bad nonce is refused (403)"
R=$(gui versions-post static_bridge_enable_retention "" editor); [ "$(echo "$R" | jget http)" = "403" ]; ok $? "editor cannot switch retention on (403)"
BAD=0; for name in '../current' 'x/../../wp-config.php' '..' '.' "$ONE/index.html" '/etc' "$ONE%2F.." "$ONE "; do
    [ "$(gui versions-name "$name" | jget http)" = "400" ] || BAD=$((BAD+1)); done
[ "$BAD" = "0" ]; ok $? "names that are not a folder of releases/ are refused (400) before the nonce"
[ "$BEFORE" = "$(listing)" ] && [ "$(st | jget retention_enabled)" = "0" ]; ok $? "refused requests deleted and changed nothing"

echo "== Protections and fixtures (QA)"
CUR=$(basename "$(readlink "$S/current")")
mapfile -t OLDREADY < <(st | php -r '$s=json_decode(stream_get_contents(STDIN),true); $r=array_filter($s["releases"], fn($v,$k)=>$v["ready"] && $k!==$s["current"], ARRAY_FILTER_USE_BOTH); asort($r); echo implode("\n", array_keys($r));')
[ "${#OLDREADY[@]}" -ge 5 ]; ok $? "at least 5 previous ready releases available (${#OLDREADY[@]})"
AIO=${OLDREADY[0]}; IDX=${OLDREADY[1]}; ESC=${OLDREADY[2]}; NEWEST=${OLDREADY[$((${#OLDREADY[@]}-1))]}
qa last-release "$AIO"
IR=$($PHP84 "$H/retention-qa.php" index-ref "$IDX"); [ "$(echo "$IR" | jget result)" = "PASS" ]; ok $? "(QA) dependency-index row created for $IDX in the real MySQL tables" "$IR"
ln -s "$SENT" "$S/releases/$ESC/qa-escape-link"; ln -s "$SENT/keep.txt" "$S/releases/$ESC/qa-file-link.html"
ln -s "$SENT" "$S/releases/qa-symlink-entry"
ABANDONED=$(st | php -r '$s=json_decode(stream_get_contents(STDIN),true); foreach($s["releases"] as $k=>$v){ if($v["dir"] && !$v["ready"] && $k!==($s["job"]["release_id"]??"")) { echo $k; break; } }')
if [ -z "$ABANDONED" ]; then ABANDONED=20261001T000000Z-qaold; mkdir -p "$S/releases/$ABANDONED/news"; echo x > "$S/releases/$ABANDONED/index.html"; fi
qa backdate "$ABANDONED" 7200
FRESH=20261015T000001Z-qafresh; mkdir -p "$S/releases/$FRESH"; echo x > "$S/releases/$FRESH/index.html"
echo "   current=$CUR aio=$AIO index=$IDX symlinks-inside=$ESC newest-previous=$NEWEST abandoned-staging=$ABANDONED fresh-staging=$FRESH"

echo "== Versioni shows every folder, locks the protected ones"
A=$(gui versions)
echo "$A" | php -r '$a=json_decode(stream_get_contents(STDIN),true); $b=$a["blocks"]; [$cur,$aio,$idx,$fresh,$newest]=array_slice($argv,1);
    $locked = fn($n) => isset($b[$n]) && !$b[$n]["deletable"] && "" !== $b[$n]["reason"];
    exit($locked($cur) && "Online"===$b[$cur]["state"] && $locked($aio) && false!==strpos($b[$aio]["reason"],"ripristino") && $locked($idx)
        && !empty($b[$fresh]["deletable"]) && !empty($b[$newest]["deletable"]) && isset($b["qa-symlink-entry"]) && !$b["qa-symlink-entry"]["deletable"] ? 0 : 1);' "$CUR" "$AIO" "$IDX" "$FRESH" "$NEWEST"
ok $? "online, AIO-recovery and index-referenced versions are locked with their reason; staging and previous ones can go; the symlinked entry cannot"

echo "== Delete one version"
BEFORE=$(listing)
R=$(gui versions-post static_bridge_delete_release "$NEWEST")
[ "$(echo "$R" | jget http)" = "302" ] && [ "$(echo "$R" | jget run)" = "complete" ]; ok $? "administrator deletes $NEWEST: 302 back to Versioni" "$R"
GONE=$(comm -23 <(echo "$BEFORE") <(listing) | tr '\n' ' ' | sed 's/ $//')
[ "$GONE" = "$NEWEST" ]; ok $? "exactly that folder is gone" "deleted: $GONE"
gui versions complete | jget notice | grep -q "$NEWEST"; ok $? "the notice names it"

echo "== Bulk cleanup from Versioni (retention still off: one previous release stays)"
SNAP=$(st); EXP=$(echo "$SNAP" | expected_delete 1 "$IDX")
DU0=$(du -sb "$S/releases" | cut -f1); BEFORE=$(listing)
R=$(gui versions-post static_bridge_cleanup_releases)
[ "$(echo "$R" | jget http)" = "302" ] && [ "$(echo "$R" | jget run)" = "complete" ]; ok $? "administrator cleanup: 302 back to Versioni with versions-run=complete" "$R"
GONE=$(comm -23 <(echo "$BEFORE") <(listing) | tr '\n' ' ' | sed 's/ $//')
[ -n "$EXP" ] && [ "$GONE" = "$EXP" ]; ok $? "deleted exactly the expected set" "deleted: $GONE expected: $EXP"
[ "$(basename "$(readlink "$S/current")")" = "$CUR" ] && [ -d "$S/releases/$AIO" ] && [ -d "$S/releases/$IDX" ] && [ -d "$S/releases/$FRESH" ] && [ -L "$S/releases/qa-symlink-entry" ]
ok $? "current, AIO, index-referenced, fresh staging and the symlinked entry are all still there"
[ "$(cd "$SENT" && find . -type f -exec sha256sum {} + | sort | sha256sum)" = "$SENT_SUM" ] && [ ! -e "$S/releases/$ESC" ]; ok $? "release with symlinks inside removed without touching the link targets"
[ -z "$(ls -A "$S/releases" | grep -F '.sb-retention-')" ]; ok $? "no trash folder left behind"
OUT=$(php "$H/routing-check.php" expect-static | tail -1); RC=$(echo "$OUT" | grep -oE '^[0-9]+/[0-9]+'); [ -n "$RC" ] && [ "${RC%/*}" = "${RC#*/}" ]; ok $? "routing still static after cleanup ($OUT)"
DU1=$(du -sb "$S/releases" | cut -f1)
gui versions complete | jget notice | grep -q "Eliminate" && st | jget activity_tail | grep -q "Pulizia release completata"; ok $? "notice shown and run logged in Attività ($(( (DU0-DU1)/1024 )) KiB freed)"
[ "$(st | jget retention_enabled)" = "0" ] && [ "$(st | jget next_retention)" = "0" ]; ok $? "the cleanup did not switch retention on"

echo "== Switch retention on from the offer"
R=$(gui versions-post static_bridge_enable_retention); SNAP=$(st)
[ "$(echo "$R" | jget http)" = "302" ] && [ "$(echo "$SNAP" | jget retention_enabled)" = "1" ] && [ "$(echo "$SNAP" | jget retention_keep)" = "1" ] \
    && [ "$(echo "$SNAP" | jget next_retention)" -gt 0 ] && echo "$SNAP" | jget activity_tail | grep -q "attivata da Versioni"
ok $? "on with keep=1, a run scheduled soon, logged" "$R"
[ "$(gui versions | jget offer)" = "" ]; ok $? "the offer is gone"

echo "== Refused while an export is running"
BUSY=20261002T000000Z-qabusy; mkdir -p "$S/releases/$BUSY"; echo x > "$S/releases/$BUSY/index.html"; qa backdate "$BUSY" 7200   # (QA) something to clean
echo '{"/hello-world/": {"mode": "slow", "seconds": 5}}' > "$E/run/fault.json"; rm -f "$E/run/fault-counter-"*
php "$H/http-gui.php" administrator export > "$E/run/ret-export.json" & G=$!
for i in $(seq 1 60); do [ "$(st | jget job.status)" = "running" ] && break; sleep 0.2; done
BEFORE=$(listing); R=$(gui versions-post static_bridge_cleanup_releases); AFTER=$(listing)
[ "$(echo "$R" | jget run)" = "busy" ] && [ -z "$(comm -23 <(echo "$BEFORE") <(echo "$AFTER"))" ]; ok $? "apply during an export: busy, nothing deleted" "$R"
wait $G; rm -f "$E/run/fault.json"
[ "$(jget final.status < "$E/run/ret-export.json")" = "complete" ]; ok $? "the export itself completed normally"; rm -f "$E/run/ret-export.json"
SNAP=$(st); NEXT=$(echo "$SNAP" | jget next_retention); NOW=$(echo "$SNAP" | jget now)
[ "$NEXT" -ge $((NOW+200)) ] && [ "$NEXT" -le $((NOW+310)) ]; ok $? "completed publication scheduled retention about 5 minutes later (in $((NEXT-NOW)) s)"

echo "== Deferred run through wp-cron.php"
qa last-release "$AIO"   # (QA) the export above recorded its own release; keep simulating the imported-DB record
SNAP=$(st); EXP=$(echo "$SNAP" | expected_delete 1 "$IDX"); BEFORE=$(listing); T0=$(date +%s)
qa cron-now; curl -s -o /dev/null "$B/wp-cron.php"
for i in $(seq 1 40); do L=$(st | jget last_retention); [ "$(echo "$L" | jget trigger)" = "cron" ] && [ "$(echo "$L" | jget time)" -ge "$T0" ] && break; sleep 0.5; done
GONE=$(comm -23 <(echo "$BEFORE") <(listing) | tr '\n' ' ' | sed 's/ $//')
[ "$(echo "$L" | jget status)" = "complete" ] && [ "$GONE" = "$EXP" ] && [ -d "$S/releases/$AIO" ] && [ -d "$S/releases/$IDX" ]; ok $? "cron run deleted exactly the expected set (${GONE:-nothing}); protected releases kept" "$L"
OUT=$(php "$H/routing-check.php" expect-static | tail -1); RC=$(echo "$OUT" | grep -oE '^[0-9]+/[0-9]+'); [ -n "$RC" ] && [ "${RC%/*}" = "${RC#*/}" ]; ok $? "routing still static after the cron run ($OUT)"

echo "== AIO import recovery uses the release retention protected"
rm "$S/current"   # (QA) simulate a migration that restored files without the excluded current pointer
curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/admin.php?page=static-bridge"
[ "$(basename "$(readlink "$S/current")")" = "$AIO" ] && [ -f "$S/.enabled" ]; ok $? "admin recovery re-activated the protected imported release $AIO"
OUT=$(php "$H/routing-check.php" expect-static | tail -1); RC=$(echo "$OUT" | grep -oE '^[0-9]+/[0-9]+'); [ -n "$RC" ] && [ "${RC%/*}" = "${RC#*/}" ]; ok $? "recovered release serves statically ($OUT)"
F=$(export_final); [ "$(echo "$F" | jget status)" = "complete" ] && [ "$(st | jget last_release)" = "$(basename "$(readlink "$S/current")")" ]; ok $? "a new export takes over again and becomes the recorded release"

echo "== Disable"
R=$(gui settings off 2)
[ "$(echo "$R" | jget http)" = "302" ] && [ "$(st | jget next_retention)" = "0" ] && st | jget activity_tail | grep -q "Conservazione delle release disattivata"; ok $? "disabling clears the pending event and is logged"
BEFORE=$(listing); F=$(export_final); AFTER=$(listing)
[ "$(echo "$F" | jget status)" = "complete" ] && [ -z "$(comm -23 <(echo "$BEFORE") <(echo "$AFTER"))" ] && [ "$(st | jget next_retention)" = "0" ]; ok $? "export while disabled deletes and schedules nothing"

echo "== Deactivation clears a pending retention event"
gui settings on 2 > /dev/null; export_final > /dev/null
[ "$(st | jget next_retention)" != "0" ]; ok $? "event pending before deactivation"
curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/$(plugin_link deactivate)"
[ "$(st | jget next_retention)" = "0" ]; ok $? "deactivation removed the retention event"
curl -s -o /dev/null -H @"$E/run/cookie-administrator.txt" "$B/wp-admin/$(plugin_link activate)"
gui settings off 3 > /dev/null; qa index-ref clear
[ -f "$S/.enabled" ] && [ "$(st | jget retention_enabled)" = "0" ]; ok $? "reactivated with its settings kept (retention off, as saved)"

echo "== Logs"
N=$(grep -cE 'wp-content/plugins/static-bridge/|<code>static-bridge</code>' "$E/logs/php-errors.log"); [ "$N" = "0" ]; ok $? "no PHP notice/warning/error from the plugin ($N lines)"
[ ! -s "$E/run/textdomain-trace.log" ]; ok $? "no early textdomain loading"
rm -rf "$SENT"

echo
echo "RETENTION E2E RESULT: $PASS passed, $FAIL failed — stack $SBQA_STACK, $(listing | wc -l) folders left in releases/"
exit $([ "$FAIL" = "0" ] && echo 0 || echo 1)
