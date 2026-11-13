#!/usr/bin/env bash
# Lists the split packages that changed, and whether each one's CHANGELOG.md
# changed too. Fails if a package changed without its CHANGELOG.md changing, or
# has no CHANGELOG.md.
# Arguments are passed to `git diff`. Without them, it checks everything since
# origin/main, including uncommitted and untracked files.
# Changes to Markdown files only don't need a changelog entry.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

if [[ $# -eq 0 ]]; then
  base="$(git merge-base origin/main HEAD)"
  echo "Changes since ${base:0:8} (merge base with origin/main), including uncommitted files:"
  changed="$(git diff --name-only "${base}"; git ls-files --others --exclude-standard)"
else
  echo "Changes in git diff $*:"
  changed="$(git diff --name-only "$@")"
fi
missing=0
no_changelog=false
found=false

while read -r prefix; do
  source_changed=false
  changelog_changed=false
  docs_changed=false
  while read -r file; do
    [[ "${file}" == "${prefix}/"* ]] || continue
    if [[ "${file}" == "${prefix}/CHANGELOG.md" ]]; then
      changelog_changed=true
    elif [[ "${file}" == *.md ]]; then
      docs_changed=true
    else
      source_changed=true
    fi
  done <<<"${changed}"

  if [[ "${source_changed}" == false && "${changelog_changed}" == false && "${docs_changed}" == false ]]; then
    continue
  fi
  found=true

  if [[ ! -f "${prefix}/CHANGELOG.md" ]]; then
    status="changed, has no CHANGELOG.md"
    missing=1
    no_changelog=true
  elif [[ "${source_changed}" == true && "${changelog_changed}" == true ]]; then
    status="changed, CHANGELOG.md updated"
  elif [[ "${source_changed}" == true ]]; then
    status="changed, CHANGELOG.md not updated"
    missing=1
  elif [[ "${changelog_changed}" == true ]]; then
    status="CHANGELOG.md updated"
  else
    status="Markdown only, no entry needed"
  fi
  printf '  %-44s %s\n' "${prefix}" "${status}"

  if [[ -n "${GITHUB_ACTIONS:-}" ]]; then
    case "${status}" in
      "changed, has no CHANGELOG.md")
        echo "::error::${prefix} has no CHANGELOG.md"
        ;;
      "changed, CHANGELOG.md not updated")
        echo "::error file=${prefix}/CHANGELOG.md::${prefix} changed without an entry in its CHANGELOG.md"
        ;;
    esac
  fi
done < <(sed -n 's/^ *- prefix: "\(.*\)"$/\1/p' .gitsplit.yml)

if [[ "${found}" == false ]]; then
  echo "  No package changed."
fi

if [[ "${missing}" -ne 0 ]]; then
  echo
  if [[ "${no_changelog}" == true ]]; then
    echo "Add a CHANGELOG.md to each package without one, following the existing ones."
  fi
  echo "Add an entry under Unreleased in each changelog not updated, if the change affects users."
  echo "If it doesn't, a maintainer can add the skip-changelog label."
fi

exit "${missing}"
