# OpenTelemetry PHP Autoinstrumentation

Builds the OpenTelemetry PHP autoinstrumentation image published to GitHub Container Registry.

## What it builds

- PHP 8.1, 8.2, 8.3, 8.4, and 8.5
- glibc and musl variants
- ZTS and non-ZTS variants

## Build locally

The `final` target is configured as a multi-platform build (see `docker-bake.hcl`), so depending on your Buildx driver you may need to select a single platform and/or set an output (e.g., `--load`) to get a runnable local image.

    docker buildx bake

Build and push:

```bash
docker buildx bake --push
```

Build extension artifacts for a single variant (intermediate build target; does not build/tag the final image):

    docker buildx bake build-non-zts-81

Override the release version and tag:

```bash
OPENTELEMETRY_VERSION=1.4.0 TAG=1.4.0 docker buildx bake 
```

## Release flow

Create and push a version tag:

```bash
git tag 1.0.0
git push origin 1.0.0
```

GitHub Actions builds the image and pushes it to GHCR.

## Files

- `Dockerfile` builds each PHP/platform variant
- `Dockerfile.final` assembles the release image
- `docker-bake.hcl` defines the matrix and image tags
- `.github/workflows/build.yml` runs CI builds and pushes
- `.github/dependabot.yml` keeps dependencies updated
