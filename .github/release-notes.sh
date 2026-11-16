#!/bin/bash
# Puts the CHANGELOG.md text of a release at the top of it on GitHub, between
# two <!-- changelog --> markers, and keeps the rest of the release text (e.g.
# GitHub's "Full Changelog" link) below it. A release gets every version
# since the release before it, so skipping a version loses nothing. Running
# it again only changes releases whose text changed.
#
#   bash .github/release-notes.sh          # every release
#   bash .github/release-notes.sh v1.5.3   # one release
#
# Needs gh with GH_TOKEN; run from the repository root.
set -euo pipefail

tags="$(gh release list --limit 200 --json tagName --jq '.[].tagName')"
released="$(printf '%s\n' "$tags" | sed 's/^[vV]//' | sort -V)"
# The release's own tag for a version: "v1.2.0", or "V1.2.0" when it was typed so.
tagof() { printf '%s\n' "$tags" | grep -ixF "v$1" | head -n 1 || true; }
newer() { [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" = "$1" ]; }

for wanted in ${1:-$(printf '%s\n' "$released" | sed 's/^/v/')}; do
  version="${wanted#[vV]}"
  tag="$(tagof "$version")"
  if [ -z "$tag" ]; then
    echo "$wanted: no such release, left alone"
    continue
  fi
  previous="$(printf '%s\n' "$released" | while read -r v; do if newer "$version" "$v"; then echo "$v"; fi; done | tail -n 1)"

  # The sections of the versions after $previous up to $version, newest first.
  notes=""
  count=0
  for v in $(grep -oP '^## \K[0-9][^ ]*' CHANGELOG.md); do
    if { [ "$v" = "$version" ] || newer "$version" "$v"; } && { [ -z "$previous" ] || newer "$v" "$previous"; }; then
      section="$(awk -v v="$v" '/^## /{p = ($2 == v); next} p' CHANGELOG.md | sed -e '/./,$!d')"
      notes="$notes"$'\n\n'"### $v"$'\n\n'"$section"
      count=$((count + 1))
    fi
  done
  if [ "$count" -eq 0 ]; then
    echo "$tag: no section in CHANGELOG.md, left as it is"
    continue
  fi
  notes="$(printf '%s' "$notes" | sed -e '/./,$!d')"
  # One version: no heading needed.
  [ "$count" -eq 1 ] && notes="$(printf '%s\n' "$notes" | tail -n +3)"

  body="$(gh release view "$tag" --json body --jq .body)"
  rest="$(printf '%s\n' "$body" | awk '/<!-- changelog -->/{skip = !skip; next} !skip' | sed -e '/./,$!d')"
  new="$(printf '<!-- changelog -->\n%s\n<!-- changelog -->\n\n%s' "$notes" "$rest")"
  if [ "$new" = "$body" ]; then
    echo "$tag: up to date"
  else
    gh release edit "$tag" --notes "$new" >/dev/null
    echo "$tag: notes updated"
  fi
done
