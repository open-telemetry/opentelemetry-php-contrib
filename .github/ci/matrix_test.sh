#!/usr/bin/env bash
# Tests for matrix.sh. Each test runs matrix.sh inside a fixture: a
# fake repository in a temp directory, with only the .gitsplit.yml and
# composer.json files matrix.sh reads. Nothing here touches the real
# repository, so adding a real project never changes these tests.
#
# CI runs this first, in php.yml's detect job, and stops if it fails.
# The next step runs matrix.sh on the real repository. See "Pipeline"
# in README.md in this directory.
#
# Run from anywhere: bash .github/ci/matrix_test.sh
set -euo pipefail

script=$(cd "$(dirname "$0")" && pwd)/matrix.sh
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
failures=0

# Pinned so these tests don't change when the versions in matrix.sh do.
export PHP_VERSIONS='8.1 8.2 8.3 8.4'
export PHP_VERSIONS_EXPERIMENTAL='8.5'
export COVERAGE_PHP_VERSION='8.4'

# Fixture builders. A project is a .gitsplit.yml prefix with a
# composer.json. phpunit.xml.dist is what makes matrix.sh treat it as
# tested, and so require a coverage cell.

add_untested() { # fixture name [php constraint]
  mkdir -p "$1/src/$2"
  if [ -n "${3:-}" ]; then
    printf '{"require": {"php": "%s"}}\n' "$3"
  else
    echo '{}'
  fi > "$1/src/$2/composer.json"
  printf '  - prefix: "src/%s"\n    target: "https://example.com/%s.git"\n' "$2" "$2" >> "$1/.gitsplit.yml"
}

add_tested() { # fixture name [php constraint]
  add_untested "$@"
  touch "$1/src/$2/phpunit.xml.dist"
}

set_ci() { # fixture name json
  local file=$1/src/$2/composer.json
  jq --argjson ci "$3" '.extra.ci = $ci' "$file" > "$file.tmp"
  mv "$file.tmp" "$file"
}

# The base fixture, shared by tests that only read it. Its projects
# are chosen for the rules they exercise, not to mirror the real repo.
base=$scratch/base
mkdir -p "$base"
echo 'splits:' > "$base/.gitsplit.yml"
# Psr1 is a prefix of Psr14's name, and their minimums differ.
add_tested "$base" Instrumentation/Psr1 '^8.1'
add_tested "$base" Instrumentation/Psr14 '^8.2'
# Same name in two categories. The root one has no PHP constraint.
add_tested "$base" Instrumentation/Symfony '>=8.3'
add_tested "$base" Symfony
# Requires with minimums below and above require.php, and extensions.
add_tested "$base" Extras '^8.3'
set_ci "$base" Extras '{"composer-requires": {"a/b:^1": "8.1", "a/b:^2": "8.4"}, "additional-extensions": ["amqp", "grpc"]}'
# No phpunit.xml.dist, so no coverage cell and no error for lacking one.
add_untested "$base" MetaPackage '^8.1'
# A nested composer.json, part of Symfony and not a project of its own.
mkdir -p "$base/src/Symfony/src/OtelBundle"
echo '{}' > "$base/src/Symfony/src/OtelBundle/composer.json"

# A copy of the base fixture, for a test that breaks it.
new_fixture() {
  local dir
  dir=$(mktemp -d "$scratch/fixture.XXXXXX")
  cp -R "$base/." "$dir"
  echo "$dir"
}

# Test helpers, which run matrix.sh in a fixture and compare output.

matrix() { # fixture [args]
  local dir=$1
  shift
  (cd "$dir" && bash "$script" "$@")
}

errors() { # fixture [args]: stderr of a run that should fail
  local dir=$1
  shift
  # shellcheck disable=SC2069 # keeps stderr, drops stdout
  if (cd "$dir" && bash "$script" "$@" 2>&1 > /dev/null); then
    echo 'exited 0'
  fi
}

# One line per project, its PHP versions in order.
summarise() {
  jq -r '.[] | "\(.project):\(.cells | map(."php-version") | join(","))"'
}

expect() { # name actual expected
  if [ "$2" = "$3" ]; then
    echo "ok   $1"
  else
    echo "FAIL $1"
    printf '  expected:\n%s\n  actual:\n%s\n' "$3" "$2" | sed 's/^/    /'
    failures=$((failures + 1))
  fi
}

# The base fixture's full matrix, as summarised.
all='Extras:8.3,8.4,8.4,8.5,8.5
Instrumentation/Psr1:8.1,8.2,8.3,8.4,8.5
Instrumentation/Psr14:8.2,8.3,8.4,8.5
Instrumentation/Symfony:8.3,8.4,8.5
MetaPackage:8.1,8.2,8.3,8.4,8.5
Symfony:8.1,8.2,8.3,8.4,8.5'

# Selecting projects

expect 'maps a file to its project' \
  "$(echo src/Instrumentation/Psr14/src/A.php | matrix "$base" | summarise)" \
  'Instrumentation/Psr14:8.2,8.3,8.4,8.5'

expect 'does not match a prefix of the project name' \
  "$(echo src/Instrumentation/Psr1/src/A.php | matrix "$base" | summarise)" \
  'Instrumentation/Psr1:8.1,8.2,8.3,8.4,8.5'

expect 'maps a nested composer dir to its gitsplit prefix' \
  "$(echo src/Symfony/src/OtelBundle/A.php | matrix "$base" | summarise)" \
  'Symfony:8.1,8.2,8.3,8.4,8.5'

expect 'keeps same-named projects in other categories apart' \
  "$(echo src/Instrumentation/Symfony/A.php | matrix "$base" | summarise)" \
  'Instrumentation/Symfony:8.3,8.4,8.5'

expect 'lists a project once for several changed files' \
  "$(printf 'src/Symfony/a\nsrc/Symfony/b\nsrc/Instrumentation/Psr1/c\n' | matrix "$base" | summarise)" \
  'Instrumentation/Psr1:8.1,8.2,8.3,8.4,8.5
Symfony:8.1,8.2,8.3,8.4,8.5'

expect 'runs nothing for files outside projects' \
  "$(printf 'README.md\n.gitsplit.yml\ncomposer.json\n.github/ci/README.md\n.github/workflows/other.yml\n' | matrix "$base")" \
  '[]'

expect 'runs every project when php.yml changed' \
  "$(printf 'src/Symfony/a\n.github/workflows/php.yml\n' | matrix "$base" | summarise)" \
  "$all"

expect 'runs every project when php-project.yml changed' \
  "$(echo .github/workflows/php-project.yml | matrix "$base" | summarise)" \
  "$all"

expect 'runs every project when a CI script changed' \
  "$(echo .github/ci/matrix.sh | matrix "$base" | summarise)" \
  "$all"

expect 'runs every project when a service changed' \
  "$(printf 'docker-compose.yaml\ndocker/mysql/init.sql\n' | matrix "$base" | summarise)" \
  "$all"

expect 'runs every project with --all' \
  "$(matrix "$base" --all < /dev/null | summarise)" \
  "$all"

# Building cells

expect 'groups cells by project, without repeating the project in each cell' \
  "$(echo src/Symfony/a | matrix "$base" | jq -c '.[] | keys, (.cells[] | keys)' | sort -u)" \
  '["cells","project"]
["composer-requires","coverage","experimental","extensions","name","php-version"]'

expect 'names each cell by PHP version and require' \
  "$(echo src/Extras/a | matrix "$base" | jq -r '.[].cells[].name')" \
  'PHP 8.3 a/b:^1
PHP 8.4 a/b:^1
PHP 8.4 a/b:^2
PHP 8.5 a/b:^1
PHP 8.5 a/b:^2'

expect 'names a cell without a require by PHP version alone' \
  "$(echo src/Symfony/a | matrix "$base" | jq -r '.[].cells[0].name')" \
  'PHP 8.1'

expect 'runs a project without a PHP constraint on every version' \
  "$(echo src/Symfony/a | matrix "$base" | jq -r '.[].cells | map(."php-version") | join(",")')" \
  '8.1,8.2,8.3,8.4,8.5'

expect 'adds cells per composer-requires, from the higher minimum' \
  "$(echo src/Extras/A.php | matrix "$base" | jq -r '.[].cells[] | "\(."php-version") \(."composer-requires")"')" \
  '8.3 a/b:^1
8.4 a/b:^1
8.4 a/b:^2
8.5 a/b:^1
8.5 a/b:^2'

expect 'installs ast and opentelemetry by default' \
  "$(echo src/Instrumentation/Psr1/a | matrix "$base" | jq -r '[.[].cells[].extensions] | unique[]')" \
  'ast, opentelemetry'

expect 'adds additional-extensions to the defaults' \
  "$(echo src/Extras/a | matrix "$base" | jq -r '[.[].cells[].extensions] | unique[]')" \
  'ast, opentelemetry, amqp, grpc'

expect 'marks experimental cells, and coverage on the coverage version' \
  "$(echo src/Instrumentation/Psr14/a | matrix "$base" | jq -r '.[].cells[] | "\(."php-version") \(.experimental) \(.coverage)"')" \
  '8.2 false false
8.3 false false
8.4 false true
8.5 true false'

expect 'takes coverage from every composer-requires on the coverage version' \
  "$(echo src/Extras/a | matrix "$base" | jq -r '.[].cells[] | select(.coverage) | ."composer-requires"')" \
  'a/b:^1
a/b:^2'

expect 'gives a package without phpunit.xml.dist no coverage cell' \
  "$(echo src/MetaPackage/a | matrix "$base" | jq '[.[].cells[].coverage] | any')" \
  'false'

# Validation. Each test breaks a copy of the base fixture in one way.

f=$(new_fixture)
mkdir -p "$f/src/Orphan"
echo '{}' > "$f/src/Orphan/composer.json"
expect 'fails for a composer.json outside every prefix' \
  "$(errors "$f" --all)" \
  '::error file=src/Orphan/composer.json::src/Orphan has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it'

expect 'validates even when no project changed' \
  "$(echo README.md | errors "$f")" \
  '::error file=src/Orphan/composer.json::src/Orphan has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it'

f=$(new_fixture)
printf '  - prefix: "src/Gone"\n    target: "https://example.com/Gone.git"\n' >> "$f/.gitsplit.yml"
expect 'fails for a prefix without a composer.json' \
  "$(errors "$f" --all)" \
  '::error file=.gitsplit.yml::src/Gone is a prefix in .gitsplit.yml but has no composer.json'

f=$(new_fixture)
set_ci "$f" Instrumentation/Psr1 '["amqp"]'
expect 'fails when extra.ci is not an object' \
  "$(errors "$f" --all)" \
  '::error file=src/Instrumentation/Psr1/composer.json::extra.ci must be an object'

f=$(new_fixture)
set_ci "$f" Instrumentation/Psr1 '{"extensions": ["amqp"]}'
expect 'fails for an unknown extra.ci key' \
  "$(errors "$f" --all)" \
  '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.extensions is not a known key (additional-extensions, composer-requires)'

f=$(new_fixture)
set_ci "$f" Instrumentation/Psr1 '{"additional-extensions": "amqp"}'
expect 'fails when additional-extensions is not a list' \
  "$(errors "$f" --all)" \
  '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.additional-extensions must be a list of strings'

f=$(new_fixture)
set_ci "$f" Instrumentation/Psr1 '{"additional-extensions": ["amqp", "opentelemetry"]}'
expect 'fails when additional-extensions repeats a default' \
  "$(errors "$f" --all)" \
  '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.additional-extensions lists opentelemetry, which every cell already has'

f=$(new_fixture)
set_ci "$f" Instrumentation/Psr1 '{"composer-requires": ["a/b:^1"]}'
expect 'fails when composer-requires is not a map' \
  "$(errors "$f" --all)" \
  '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.composer-requires must map each constraint to a minimum PHP version'

f=$(new_fixture)
set_ci "$f" Instrumentation/Psr1 '{"composer-requires": {"a/b:^1": "8.1", "a/b:^0": "7.4"}}'
expect 'fails for a composer-requires minimum that CI does not run' \
  "$(errors "$f" --all)" \
  '::error file=src/Instrumentation/Psr1/composer.json::extra.ci.composer-requires gives a/b:^0 the minimum 7.4, which is not a PHP version CI runs (8.1 8.2 8.3 8.4 8.5)'

expect 'fails when the coverage version is not a supported version' \
  "$(COVERAGE_PHP_VERSION=8.5 errors "$base" --all)" \
  '::error file=.github/ci/matrix.sh::coverage_php_version 8.5 is not in php_versions (8.1 8.2 8.3 8.4)'

f=$(new_fixture)
add_tested "$f" Future '^8.5'
expect 'fails for a tested project with no coverage cell' \
  "$(errors "$f" --all)" \
  '::error file=src/Future/composer.json::src/Future has a phpunit.xml.dist but no cell on PHP 8.4, the coverage version'

f=$(new_fixture)
set_ci "$f" Extras '{"composer-requires": {"a/b:^1": "8.1", "a/b:^2": "8.4", "a/b:^3": "8.1"}}'
expect 'fails for a project with more cells than a matrix allows' \
  "$(MATRIX_JOB_LIMIT=6 errors "$f" --all)" \
  '::error file=src/Extras/composer.json::src/Extras has 8 cells, more than the 6 jobs GitHub allows in one matrix'

f=$(new_fixture)
add_tested "$f" Seventh
expect 'fails for more projects than a matrix allows' \
  "$(MATRIX_JOB_LIMIT=6 errors "$f" --all)" \
  '::error file=.gitsplit.yml::.gitsplit.yml has 7 projects, more than the 6 jobs GitHub allows in one matrix'

[ "$failures" -eq 0 ] || { echo "$failures failed"; exit 1; }
