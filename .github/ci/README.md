# CI

`.github/workflows/php.yml` tests the projects in `src/`. Each commit runs
only the projects it changes. A weekly schedule runs every project.

## Pipeline

Each run of `php.yml` goes through these, in order. A failure in the
`detect` job stops the run before any project is tested. After that,
projects and cells run independently, so one failure doesn't cancel
the rest.

The `detect` job first validates the root `composer.json`, once per
run. Then:

1. `detect` job, "Test matrix script": runs `matrix_test.sh`, the tests
   for `matrix.sh`. They run `matrix.sh` against small fake repositories
   built in a temp directory, never against `src/`. They fail only when
   `matrix.sh` itself is broken.
2. `detect` job, "Build matrix": runs `matrix.sh` on the real
   repository. It checks the repository (see below), then prints the
   projects to test and their cells. A contributor's mistake, such as a
   project missing from `.gitsplit.yml`, fails here.
3. `php` job: one entry per project, each calling `php-project.yml`.
4. `php-project.yml`: one job per cell, which installs and runs the
   project's checks and tests.

Each cell installs fresh, with `composer update`, or `composer require`
for a framework version. Only Composer's downloads are cached, so a
cell always tests the newest releases its constraints allow.

Xdebug is loaded only in coverage cells. They run PHPUnit a second
time, on the `unit` and `integration` suites only, and upload the
result to Codecov. Codecov carries each project's last upload forward,
so a run that skips a project leaves its coverage as it was.

## How CI decides what to run

The `detect` job lists the changed files and pipes them to `matrix.sh`,
which prints the projects to test, each with one cell per PHP version and
framework version. The `php` job calls `php-project.yml` once per
project, and that runs the project's cells.

Grouping by project keeps each matrix small. GitHub allows 256 jobs in
one matrix, and a full run is more than that in total, but no project is
close to it on its own.

The changed files come from:

| Event | Changed files |
|---|---|
| pull request | the pull request's diff against `main` |
| push to `main` | the pushed commit, or every project if more than one commit was pushed |
| weekly schedule, manual run | none, every project runs |

A changed file selects:

| Changed file | Runs |
|---|---|
| `src/<project>/**` | that project |
| `.github/workflows/php.yml`, `.github/workflows/php-project.yml`, `.github/ci/*.sh` | every project |
| `docker-compose.yaml` | every project with `extra.ci.services` |
| `docker/<service>/**` | every project with that service in `extra.ci.services` |
| anything else | nothing |

Projects nobody touched are tested by the weekly run, against the newest
dependency releases. The job summary of `detect` lists the cells a run
chose.

A project is a `prefix` in `.gitsplit.yml`. Each runs on every PHP
version in `php_versions` and `php_versions_experimental` that its
`composer.json` `require.php` allows. Style, Phan, Psalm, PHPStan and
PHPUnit run only if the project has the config file for them.

`matrix.sh` also checks the repository, and fails the run naming the
file at fault when:

- a directory under `src/` has a `composer.json` but is not inside a
  `.gitsplit.yml` prefix, so CI would never test it
- a `.gitsplit.yml` prefix has no `composer.json`
- `extra.ci` has an unknown key or a value of the wrong shape
- `extra.ci.services` names a service that isn't in
  `docker-compose.yaml`
- a project with a `phpunit.xml.dist` has no cell on the coverage version
- there are more projects, or a project has more cells, than the 256
  jobs GitHub allows in one matrix

## Add a project

1. Create `src/<Category>/<Name>` with a `composer.json` that sets
   `require.php`. Copy `phpunit.xml.dist`, `.php-cs-fixer.php`,
   `psalm.xml.dist`, `phpstan.neon.dist` and `.phan/config.php` from a
   similar project.
2. Add it to `.gitsplit.yml`. This is what makes CI test it.
3. Add it to `autoload` and `replace` in the root `composer.json`.
4. Add its owners to `.github/component_owners.yml`.
5. If it needs more than the defaults, add `extra.ci` (below).

Run `bash .github/ci/matrix.sh --all < /dev/null > /dev/null` to check
the first two steps.

## Configure a project's CI

Projects that need more than the defaults say so in their own
`composer.json`, under `extra.ci`. Editing it runs only that project.

### Test against several framework versions

`composer-requires` maps each constraint to the lowest PHP version that
supports it. Each constraint gets its own cells, on PHP versions that
satisfy both this minimum and `require.php`. The cell installs with
`composer require <constraint>`.

```json
"extra": {
  "ci": {
    "composer-requires": {
      "laravel/framework:^10": "8.1",
      "laravel/framework:^11": "8.2"
    }
  }
}
```

### Install more PHP extensions

Every cell installs `ast` (for Phan) and `opentelemetry`.
`additional-extensions` adds to them. It can't remove them, and listing
them again is an error. Names are in the
[setup-php](https://github.com/shivammathur/setup-php#heavy_plus_sign-php-extension-support)
format, which is not always the `ext-*` name.

```json
"extra": {
  "ci": {
    "additional-extensions": ["phalcon5"]
  }
}
```

`ext-opentelemetry` doesn't need to be in `require`. Most projects leave
it out so they can be installed and worked on without the extension,
and `_register.php` checks for it at runtime instead.

### Start a service for integration tests

`services` lists the `docker-compose.yaml` services a project's tests
need. Each cell starts them with `docker compose up --wait` before
PHPUnit. Changing `docker-compose.yaml`, or a file under
`docker/<service>/`, runs every project that lists that service.

```json
"extra": {
  "ci": {
    "services": ["mysql"]
  }
}
```

The services in use are `mysql`, `postgresql`, `rabbitmq`, `kafka` and
`mongodb`. CI starts `mongodb` with
[supercharge/mongodb-github-action](https://github.com/supercharge/mongodb-github-action)
instead of compose, because it sets up a replica set and the compose
service doesn't.

To add a service:

1. Add it to `docker-compose.yaml` with a `healthcheck`. Without one,
   `--wait` returns as soon as the container starts, and the first
   tests can fail while the service is still starting.
2. Publish its port. In CI the tests run on the runner, not in the
   compose network, so they reach the service on `127.0.0.1`.
3. Put any init files under `docker/<service>/`, so changing them runs
   only the projects using the service.
4. Add a `<SERVICE>_HOST` variable to the `php` service's `environment`,
   defaulting to the service name. Tests read it, falling back to
   `127.0.0.1`, so the same test works under `make` and in CI.
5. List it in the project's `extra.ci.services`.

## Change PHP versions

The versions are at the top of `matrix.sh`.

- `php_versions` must pass.
- `php_versions_experimental` runs but may fail. Use it for a version
  that isn't released yet, or that projects don't support yet.
- `coverage_php_version` is the one version whose cells upload
  coverage. It must be in `php_versions`.

To add a version, add it to `php_versions_experimental`. Once its cells
pass, move it to `php_versions`. Change `coverage_php_version`
separately, when every project passes on the new version.

## Check changes locally

`matrix.sh` needs bash and `jq`. Run it from the repository root.

Run the tests after changing `matrix.sh`. They check its rules on fake
repositories, so they pass or fail the same whatever is in `src/`.

```bash
# Tests for matrix.sh, step 1 of the pipeline
bash .github/ci/matrix_test.sh

# The cells your branch would run, as step 2 builds them
git diff --name-only main... | bash .github/ci/matrix.sh | jq -r '.[] | .project as $p | .cells[] | "\($p) \(.name)"'

# How many cells a full run has
bash .github/ci/matrix.sh --all < /dev/null | jq '[.[].cells | length] | add'
```
