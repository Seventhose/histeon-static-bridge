#!/usr/bin/env bash
set -euo pipefail

usage() {
  printf '%s\n' "Usage: $0 BASE_URL STATIC_ROOT [--strict]"
  exit 2
}

[[ $# -ge 2 ]] || usage

base_url="${1%/}"
static_root="$2"
strict=0
if [[ "${3:-}" == "--strict" ]]; then
  strict=1
elif [[ $# -ge 3 ]]; then
  usage
fi

curl_opts=(--fail --silent --show-error --location --insecure --max-time 60)
release="$(date -u +%Y%m%dT%H%M%SZ)"
release_dir="$static_root/releases/$release"
mkdir -p "$release_dir"

extract_locs() {
  sed -n 's:.*<loc>\([^<]*\)</loc>.*:\1:p' | sed 's/&amp;/\&/g'
}

index_xml="$(curl "${curl_opts[@]}" "$base_url/sitemap_index.xml")"
mapfile -t sitemap_urls < <(printf '%s' "$index_xml" | extract_locs)

if [[ ${#sitemap_urls[@]} -eq 0 ]]; then
  sitemap_urls=("$base_url/sitemap.xml")
fi

declare -A seen_urls=()
declare -a page_urls=()

for sitemap_url in "${sitemap_urls[@]}"; do
  sitemap_xml="$(curl "${curl_opts[@]}" "$sitemap_url")"
  while IFS= read -r page_url; do
    [[ -n "$page_url" ]] || continue
    [[ "$page_url" == "$base_url"* ]] || continue
    if [[ -z "${seen_urls[$page_url]+yes}" ]]; then
      seen_urls["$page_url"]=1
      page_urls+=("$page_url")
    fi
  done < <(printf '%s' "$sitemap_xml" | extract_locs)
done

if [[ ${#page_urls[@]} -eq 0 ]]; then
  page_urls=("$base_url/")
fi

manifest="$release_dir/manifest.tsv"
printf 'source_url\tpath\tsha256\tbytes\n' > "$manifest"
downloaded=0
skipped=0

for page_url in "${page_urls[@]}"; do
  relative="${page_url#"$base_url"}"
  relative="${relative%%\?*}"
  relative="${relative%%#*}"
  [[ "$relative" == /* ]] || continue

  # These pages contain request-specific nonces/forms and remain dynamic.
  case "$relative" in
    /iscriviti|/iscriviti/*|/contatti|/contatti/*)
      skipped=$((skipped + 1))
      continue
      ;;
  esac

  if [[ "$relative" == "/" ]]; then
    destination="$release_dir/index.html"
  else
    relative_no_slash="${relative#/}"
    relative_no_slash="${relative_no_slash%/}"
    [[ -n "$relative_no_slash" ]] || relative_no_slash="index"
    destination="$release_dir/$relative_no_slash/index.html"
  fi

  mkdir -p "$(dirname "$destination")"
  temporary="$destination.part"
  request_url="$page_url"
  if [[ "$request_url" == *\?* ]]; then
    request_url="${request_url}&static_bridge_bypass=1"
  else
    request_url="${request_url}?static_bridge_bypass=1"
  fi
  curl "${curl_opts[@]}" "$request_url" -o "$temporary"
  mv "$temporary" "$destination"

  checksum="$(sha256sum "$destination" | awk '{print $1}')"
  bytes="$(wc -c < "$destination")"
  printf '%s\t%s\t%s\t%s\n' "$page_url" "${destination#"$release_dir/"}" "$checksum" "$bytes" >> "$manifest"
  downloaded=$((downloaded + 1))
done

printf '%s\n' "release=$release" "base_url=$base_url" "downloaded=$downloaded" "skipped_dynamic=$skipped" > "$release_dir/release.txt"
touch "$release_dir/.ready"

current_link="$static_root/current.new.$$"
ln -s "$release_dir" "$current_link"
mv -Tf "$current_link" "$static_root/current"

if [[ "$strict" -eq 1 ]]; then
  touch "$static_root/.strict"
fi

printf '%s\n' "Published $downloaded static pages in $release_dir"
printf '%s\n' "Dynamic sitemap pages skipped: $skipped"
printf '%s\n' "Current release: $static_root/current"
