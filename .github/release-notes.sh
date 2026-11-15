#!/bin/bash
# Puts each version's section of CHANGELOG.md at the top of its GitHub
# release, between two <!-- changelog --> markers, and keeps the rest of the
# release text (e.g. GitHub's "Full Changelog" link) below it. Running it
# again only changes releases whose section changed.
#
#   bash .github/release-notes.sh          # every release
#   bash .github/release-notes.sh v1.5.3   # one release
#
# Needs gh with GH_TOKEN; run from the repository root.
set -euo pipefail

tags="${1:-$(gh release list --limit 200 --json tagName --jq '.[].tagName')}"
for tag in $tags; do
  version="${tag#v}"
  # The lines under "## <version> ..." up to the next "## ", without leading blank lines.
  notes="$(awk -v v="$version" '/^## /{p = ($2 == v); next} p' CHANGELOG.md | sed -e '/./,$!d')"
  if [ -z "$notes" ]; then
    echo "$tag: no section in CHANGELOG.md, left as it is"
    continue
  fi
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
