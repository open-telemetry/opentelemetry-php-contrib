# CI

The projects in `src/` are tested on several PHP versions, and some on
several framework versions. `matrix.php` decides which: each
combination it builds is a cell. This directory holds the script, its
tests, and the rules below for configuring a project's cells.

## What runs

`.github/workflows/ci.yml` runs on every pull request and push to
`main`, whatever the change touches. It:

1. validates the root `composer.json`
2. runs the PHPUnit tests in `tests/`. They check `matrix.php` on small
   repositories built in a temp directory, never on `src/`, so they fail
   only when `matrix.php` itself is broken.
3. runs `matrix.php --check` on the real repository. A contributor's
   mistake, such as a project missing from `.gitsplit.yml`, fails here.

`--check` fails naming the file at fault when:

- a directory under `src/` has a `composer.json` but is not inside a
  `.gitsplit.yml` prefix, so CI would never test it
- a `.gitsplit.yml` prefix has no `composer.json`
- `extra.ci` has an unknown key or a value of the wrong shape
- `extra.ci.services` names a service that isn't in
  `docker-compose.yaml`
- a project with a `phpunit.xml.dist` has no cell on the coverage
  version, or sets `extra.ci.skip`
- a project has more cells than the 256 jobs GitHub allows in one
  matrix

A project is a `prefix` in `.gitsplit.yml`, unless it sets
`extra.ci.skip`. It runs on every PHP version in `supported` and
`experimental` in `matrix.php` that its `composer.json` `require.php`
allows.

## Add a project

1. Create `src/<Category>/<Name>` with a `composer.json` that sets
   `require.php`. Copy `phpunit.xml.dist`, `.php-cs-fixer.php`,
   `psalm.xml.dist`, `phpstan.neon.dist` and `.phan/config.php` from a
   similar project.
2. Add it to `.gitsplit.yml`. This is what makes CI test it.
3. Add it to `autoload` and `replace` in the root `composer.json`.
4. Add its owners to `.github/component_owners.yml`.
5. If it needs more than the defaults, add `extra.ci` (below).

## Configure a project's CI

Projects that need more than the defaults say so in their own
`composer.json`, under `extra.ci`.

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

### Skip a project that isn't PHP code

`skip` leaves a project out of CI entirely. Use it for a prefix whose
output isn't a Composer package, and which has its own CI.

```json
"extra": {
  "ci": {
    "skip": true
  }
}
```

`OperatorPackages/autoinstrumentation-php` skips. It builds a Docker
image, and its `composer.json` only lists what goes in the image. Its
own `.github/workflows/build.yml` builds the image, in the split
repository.

A project with a `phpunit.xml.dist` can't skip, since its tests would
never run.

## Change PHP versions

The versions are at the top of `matrix.php`.

- `supported` must pass.
- `experimental` runs but may fail. Use it for a version that isn't
  released yet, or that projects don't support yet.
- `coverage` is the one version whose cells upload coverage. It must be
  in `supported`.

To add a version, add it to `experimental`. Once its cells pass, move
it to `supported`. Change `coverage` separately, when every project
passes on the new version.
