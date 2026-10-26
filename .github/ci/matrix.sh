#!/usr/bin/env bash
# Prints the CI matrix for the projects touched by the paths on stdin,
# or for every project with --all, as a list of projects each with its
# own cells. Run from the repository root.
#
# Fails, printing each problem as a GitHub annotation, when the
# repository is inconsistent: a project CI would silently skip, or an
# extra.ci this script can't follow.
#
# php.yml's detect job runs this on the real repository, after
# matrix_test.sh has tested it on fixtures. Its output is the matrix
# for the php job. See "Pipeline" in README.md in this directory.
set -euo pipefail

# The environment overrides these only so matrix_test.sh is pinned.
php_versions=${PHP_VERSIONS:-'8.1 8.2 8.3 8.4'}
php_versions_experimental=${PHP_VERSIONS_EXPERIMENTAL:-'8.5'}
coverage_php_version=${COVERAGE_PHP_VERSION:-'8.4'}
# php.yml runs one matrix of projects, and each project its own matrix
# of cells. GitHub caps a matrix at this many jobs.
job_limit=${MATRIX_JOB_LIMIT:-256}

default_extensions='ast opentelemetry'

# A changed path matching this runs every project. Changes to
# docker-compose.yaml and docker/<service>/ run the projects using a
# service instead.
run_all='^(\.github/workflows/php(-project)?\.yml|\.github/ci/[^/]+\.sh)$'

if [ "${1:-}" = --all ]; then
  changed=null
else
  changed=$(jq -Rn '[inputs]')
fi

projects=$(
  sed -nE 's/^ *- prefix: "src\/(.*)"/\1/p' .gitsplit.yml | while IFS= read -r project; do
    if [ ! -f "src/$project/composer.json" ]; then
      jq -nc --arg project "$project" '{$project, missing: true}'
      continue
    fi
    tested=false
    [ -f "src/$project/phpunit.xml.dist" ] && tested=true
    jq -c --arg project "$project" --argjson tested "$tested" '{
      $project,
      $tested,
      minimum: ((.require.php // "") | [match("[0-9]+\\.[0-9]+").string][0] // ""),
      ci: (.extra.ci // {})
    }' "src/$project/composer.json"
  done | jq -s .
)

composer_dirs=$(find src -name vendor -prune -o -name composer.json -print | sed 's|/composer\.json$||')

# The keys of the top-level services: block, read without a YAML parser.
# Works while services are indented two spaces, as they are now.
compose_services=$(
  [ -f docker-compose.yaml ] || exit 0
  awk '/^[^ #]/ { in_services = ($0 ~ /^services:/) } in_services && /^  [A-Za-z0-9_.-]+:/ { sub(/^  /, ""); sub(/:.*/, ""); print }' docker-compose.yaml
)

result=$(jq -nc \
  --argjson projects "$projects" \
  --argjson changed "$changed" \
  --arg composer_dirs "$composer_dirs" \
  --arg compose_services "$compose_services" \
  --arg supported "$php_versions" \
  --arg experimental "$php_versions_experimental" \
  --arg coverage "$coverage_php_version" \
  --argjson job_limit "$job_limit" \
  --arg defaults "$default_extensions" \
  --arg run_all "$run_all" '
  def version: split(".") | map(tonumber);
  def at_least($minimum): $minimum == "" or (version >= ($minimum | version));
  def error($file; $message): "::error file=\($file)::\($message)";
  def words: split(" ") | map(select(. != ""));

  ($supported | words) as $supported
  | ($experimental | words) as $experimental
  | ($supported + $experimental) as $versions
  | ($defaults | words) as $defaults
  | ($compose_services | split("\n") | map(select(. != ""))) as $compose_services
  | ["additional-extensions", "composer-requires", "services"] as $keys
  | ($projects | map(.project)) as $names

  | [
      ( $composer_dirs | split("\n")[] | select(. != "")
        | select(. as $dir | $names | any(. as $name | $dir == "src/\($name)" or ($dir | startswith("src/\($name)/"))) | not)
        | error("\(.)/composer.json"; "\(.) has a composer.json but is not a prefix in .gitsplit.yml, so CI does not test it")
      ),
      ( $projects[] | select(.missing)
        | error(".gitsplit.yml"; "src/\(.project) is a prefix in .gitsplit.yml but has no composer.json")
      ),
      ( $projects[] | select(.missing | not)
        | "src/\(.project)/composer.json" as $file
        | .ci
        | if type != "object" then error($file; "extra.ci must be an object")
          else
            ( keys[] | select(IN($keys[]) | not)
              | error($file; "extra.ci.\(.) is not a known key (\($keys | join(", ")))")
            ),
            ( select(has("additional-extensions")) | ."additional-extensions"
              | if type == "array" and all(type == "string") then
                  .[] | select(IN($defaults[]))
                  | error($file; "extra.ci.additional-extensions lists \(.), which every cell already has")
                else error($file; "extra.ci.additional-extensions must be a list of strings")
                end
            ),
            ( select(has("composer-requires")) | ."composer-requires"
              | if type == "object" and all(.[]; type == "string") then
                  to_entries[] | select(.value | IN($versions[]) | not)
                  | error($file; "extra.ci.composer-requires gives \(.key) the minimum \(.value), which is not a PHP version CI runs (\($versions | join(" ")))")
                else error($file; "extra.ci.composer-requires must map each constraint to a minimum PHP version")
                end
            ),
            ( select(has("services")) | .services
              | if type == "array" and all(type == "string") then
                  .[] | select(IN($compose_services[]) | not)
                  | error($file; "extra.ci.services lists \(.), which is not a service in docker-compose.yaml")
                else error($file; "extra.ci.services must be a list of strings")
                end
            )
          end
      ),
      ( $projects | length | select(. > $job_limit)
        | error(".gitsplit.yml"; ".gitsplit.yml has \(.) projects, more than the \($job_limit) jobs GitHub allows in one matrix")
      ),
      ( select($coverage | IN($supported[]) | not)
        | error(".github/ci/matrix.sh"; "coverage_php_version \($coverage) is not in php_versions (\($supported | join(" ")))")
      )
    ] as $errors
  | if $errors != [] then {$errors} else

    [ $projects | sort_by(.project)[]
      | . as {$project, $tested, $minimum, $ci}
      | $versions[] as $php
      | select($php | at_least($minimum))
      | ($ci."composer-requires" // {"": ""}) | to_entries[] as {key: $require, value: $require_minimum}
      | select($php | at_least($require_minimum))
      | {
          $project,
          name: ("PHP \($php) \($require)" | rtrimstr(" ")),
          "php-version": $php,
          "composer-requires": $require,
          extensions: ($defaults + ($ci."additional-extensions" // []) | join(", ")),
          services: ($ci.services // []),
          experimental: ($php | IN($experimental[])),
          coverage: ($tested and $php == $coverage)
        }
    ] as $cells

    | {
        errors: [
          ( $projects[] | select(.tested) | .project as $project
            | select($cells | any(.project == $project and .coverage) | not)
            | error("src/\($project)/composer.json"; "src/\($project) has a phpunit.xml.dist but no cell on PHP \($coverage), the coverage version")
          ),
          ( $cells | group_by(.project)[] | select(length > $job_limit)
            | error("src/\(.[0].project)/composer.json"; "src/\(.[0].project) has \(length) cells, more than the \($job_limit) jobs GitHub allows in one matrix")
          )
        ],
        projects: (
          if $changed == null or ($changed | any(test($run_all))) then $cells
          else
            $cells | map(select(
              .project as $project | .services as $services
              | $changed | any(
                  startswith("src/\($project)/")
                  or (. == "docker-compose.yaml" and $services != [])
                  or (. as $path | $services | any(. as $service | $path | startswith("docker/\($service)/")))
                )
            ))
          end
          | group_by(.project) | map({project: .[0].project, cells: map(del(.project))})
        )
      }
    end
')

errors=$(jq -r '.errors[]' <<< "$result")
if [ -n "$errors" ]; then
  echo "$errors" >&2
  exit 1
fi
jq -c '.projects' <<< "$result"
