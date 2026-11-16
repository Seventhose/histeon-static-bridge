#!/usr/bin/env bash
# Hand-copied Nginx block gone wrong: for each typical mistake, breaks the Static Bridge include
# of the disposable Nginx stack, measures the real exposure with direct requests, runs the
# plugin's Diagnostica and reports whether it caught the mistake. Restores the include after
# every scenario. Run AFTER run-e2e.sh (plugin active, release published).
# Usage: [SBQA_STACK=nginx|nginx71] bash nginx-misconfig-e2e.sh
set -u
H=$(cd "$(dirname "$0")" && pwd)
. "$H/profile.sh"
[ "$SBQA_WEB" = "nginx" ] || { echo "Nginx stacks only"; exit 2; }
E="$SBQA_HOME/$SBQA_ENV_NAME"
INC="$E/conf/static-bridge.conf"
[ -f "$INC" ] || { echo "Missing $INC (run run-e2e.sh first)"; exit 2; }
ORIG=$(mktemp); cp "$INC" "$ORIG"
reload() { "$NGX" -t -p "$E/run" -c "$E/conf/nginx.conf" > /dev/null 2>&1 && "$NGX" -s reload -p "$E/run" -c "$E/conf/nginx.conf" && sleep 0.7; }
restore() { cp "$ORIG" "$INC"; reload; }
trap 'restore; rm -f "$ORIG"' EXIT
route() { curl -s -o /dev/null -D - "$@" | tr -d '\r' | awk '/^HTTP\//{s=$2} tolower($1)=="x-static-bridge-route:"{r=$2} END{print (r=="" ? "WordPress" : r), "HTTP", s}'; }
diag() { php "$H/http-gui.php" administrator diagnose | php -r '$j=json_decode(stream_get_contents(STDIN),true); $f=array(); foreach($j["results"] as $r){ if(in_array($r["status"],array("fail","warning"),true) && strpos($r["id"],"dynamic-")!==0) $f[]=$r["id"]."=".$r["status"]; } echo $f ? implode(" ",$f) : "nothing flagged";'; }
PASS=0; FAIL=0
scenario() { # name, python transform, ground-truth label, ground-truth command, expected diagnostic id
    local name=$1 transform=$2 truth_label=$3 truth_cmd=$4 expect=$5
    python3 -c "import sys,re; s=open(sys.argv[1]).read(); $transform; open(sys.argv[1],'w').write(s)" "$INC"
    if ! reload; then echo "[SKIP] $name: Nginx rejected the modified block"; restore; return; fi
    local truth; truth=$(eval "$truth_cmd")
    local found; found=$(diag)
    if echo "$found" | grep -q "$expect"; then PASS=$((PASS+1)); echo "[PASS] $name — exposure: $truth_label → $truth — Diagnostica: $found";
    else FAIL=$((FAIL+1)); echo "[FAIL] $name — exposure: $truth_label → $truth — Diagnostica did not flag $expect (flagged: $found)"; fi
    restore
}
B=http://127.0.0.1:$SBQA_PORT
echo "== correct block — Diagnostica: $(diag)"
scenario "private folder not denied" "s=re.sub(r'location \^~ /wp-content/static-bridge/ \{[^}]*\}\n?', '', s, count=1)" \
    "GET /wp-content/static-bridge/current/index.html" "route '$B/wp-content/static-bridge/current/index.html'" "security-release-access=fail"
scenario "block not installed" "s='location / { try_files \$uri \$uri/ /index.php\$is_args\$args; }\n'" \
    "GET /hello-world/" "route '$B/hello-world/'" "static-.*=fail"
scenario "logged-in cookie guard missing" "s='\n'.join(l for l in s.split('\n') if 'http_cookie' not in l)" \
    "GET / with a wordpress_logged_in_ cookie" "route -H 'Cookie: wordpress_logged_in_qa=1' '$B/'" "logged-in-bypass=fail"
scenario "query-string guard missing" "s='\n'.join(l for l in s.split('\n') if '\$args != \"\"' not in l)" \
    "GET /?x=1" "route '$B/?x=1'" "query-bypass=fail"
scenario "method guard missing" "s='\n'.join(l for l in s.split('\n') if 'request_method' not in l)" \
    "POST /" "route -X POST --data qa=1 '$B/'" "post-bypass=fail"
echo
echo "NGINX MISCONFIG E2E ($SBQA_STACK): $PASS caught, $FAIL not caught by Diagnostica"
[ "$FAIL" = "0" ]
