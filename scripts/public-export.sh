#!/usr/bin/env bash
# Makes the public copy of a repository (Histeon, Lingua Bridge, Static Bridge): the tracked files of a commit
# (default: HEAD) in a new git repository with one commit, without the private history (its author addresses, the
# clients' sites named in old commits), and without the paths listed in .public-exclude (one glob per line,
# relative to the root; a trailing / for a folder) when the repository has that file, and without those named in
# XB_PUBLIC_EXCLUDE (space-separated).
#
#   scripts/public-export.sh <empty folder> [commit]
#
# Checks before the commit, each one fatal:
# - scripts/secret-scan.sh on the copy;
# - no e-mail address but GitHub noreply ones and example domains;
# - no home folder path (/home/…, /Users/…);
# - none of the words in the deny list: one per line, kept outside the repository because it names the clients
#   (default ~/.config/histos/public-deny.txt, or XB_PUBLIC_DENY=<file>). Without the list the export stops.
# Nothing is pushed: the copy is ready to read, then to push where the owner decides.
set -euo pipefail

root=$(cd "$(dirname "$0")/.." && pwd)
dest=${1:?Uso: scripts/public-export.sh <cartella vuota> [commit]}
ref=${2:-HEAD}
deny=${XB_PUBLIC_DENY:-"$HOME/.config/histos/public-deny.txt"}
name=${XB_PUBLIC_NAME:-Seventhose}
email=${XB_PUBLIC_EMAIL:-540299+Seventhose@users.noreply.github.com}

[ -f "$deny" ] || { echo "Lista delle parole vietate mancante: $deny" >&2; exit 1; }
if [ -e "$dest" ] && [ -n "$(ls -A "$dest")" ]; then echo "La cartella $dest non è vuota" >&2; exit 1; fi
sha=$(git -C "$root" rev-parse --short=7 --verify "$ref^{commit}")
main=""
for candidate in plugin/xb.php lingua-bridge.php plugin/static-bridge.php; do
  if git -C "$root" cat-file -e "$sha:$candidate" 2>/dev/null; then main=$candidate; break; fi
done
[ -n "$main" ] || { echo "Non trovo il file principale del plugin" >&2; exit 1; }
product=$(git -C "$root" show "$sha:$main" | sed -n 's/^ \* Plugin Name: *//p' | head -1)
version=$(git -C "$root" show "$sha:$main" | sed -n 's/^ \* Version: *//p' | head -1)

mkdir -p "$dest"
git -C "$root" archive --format=tar "$sha" | tar -x -C "$dest"
if [ -f "$dest/.public-exclude" ]; then
  (
    cd "$dest"
    while IFS= read -r pattern; do
      case "$pattern" in ''|'#'*) continue ;; *..*|/*) echo "Percorso non ammesso in .public-exclude: $pattern" >&2; exit 1 ;; esac
      # An unmatched glob stays as written: removing a path that does not exist does nothing.
      for path in $pattern; do rm -rf -- "$path"; done
    done < .public-exclude
    rm -f .public-exclude # It may name what it leaves out.
  )
fi
# Paths the caller leaves out as well, before the checks (XB_PUBLIC_EXCLUDE, space-separated, relative): the
# release sources drop docs/ (npm run rilascio in Histeon Publish), so docs/ never stops their export.
for pattern in ${XB_PUBLIC_EXCLUDE:-}; do
  case "$pattern" in *..*|/*) echo "Percorso non ammesso in XB_PUBLIC_EXCLUDE: $pattern" >&2; exit 1 ;; esac
  (cd "$dest" && rm -rf -- $pattern)
done
git -C "$dest" init --quiet --initial-branch=main
git -C "$dest" add --all

echo "› Controlli sulla copia di $sha"
fail=0
(cd "$dest" && sh scripts/secret-scan.sh) || fail=1
# Third parties' own addresses (lockfiles, licence notices, the .pot header) and the bundled import script are public
# already; the tests' sample addresses are allowed by name.
mails=$(git -C "$dest" grep -nIoE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' -- . \
  ':!*.lock' ':!**/package-lock.json' ':!**/licenses/**' ':!**/THIRD-PARTY-LICENSES.md' ':!*.pot' ':!plugin/assets/html-import/histos-html-import.js' \
  | grep -viE '@users\.noreply\.github\.com|:a@b\.it$|@(example|([a-z0-9.-]+\.)?example\.(com|org|net)|example\.org\.uk|[a-z0-9.-]+\.example|[a-z0-9.-]+\.(test|local|localhost|invalid)|anthropic\.com|wordpress\.org|w\.org)$' \
  | grep -vE '\.(png|jpe?g|webp|avif|gif|svg|woff2?)@|@[0-9]+\.[0-9]+' || true)
if [ -n "$mails" ]; then echo "Indirizzi e-mail:" >&2; echo "$mails" | cut -c1-200 >&2; fail=1; fi
homes=$(git -C "$dest" grep -nIE '/home/[a-z][a-z0-9_-]+/|/Users/[A-Za-z][A-Za-z0-9_-]+/' | grep -vE '/home/(user|you|tu|nome|example)/' || true)
if [ -n "$homes" ]; then echo "Percorsi di una cartella home:" >&2; echo "$homes" | cut -c1-200 >&2; fail=1; fi
while IFS= read -r word; do
  case "$word" in ''|'#'*) continue ;; esac
  hits=$(git -C "$dest" grep -nIiF -e "$word" || true)
  if [ -n "$hits" ]; then echo "Parola vietata «$word»:" >&2; echo "$hits" | cut -c1-200 >&2; fail=1; fi
done < "$deny"
if [ "$fail" = 1 ]; then
  echo "Esportazione fermata: la copia resta in $dest per controllarla, senza commit." >&2
  exit 1
fi

git -C "$dest" -c user.name="$name" -c user.email="$email" commit --quiet \
  -m "$product $version" -m "Copia pubblica del commit $sha, senza la storia di sviluppo."
echo "› Pronta in $dest: un commit, $product $version (da $sha). Niente è stato inviato."
