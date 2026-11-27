# docker-bake.hcl — drives all PHP extension builds + final image assembly.
#
# Build the final image:        docker buildx bake
# Build and push:               docker buildx bake --push
# Build a single variant:       docker buildx bake build-non-zts-82
# Override opentelemetry version or tag:      OPENTELEMETRY_VERSION=1.5.0 TAG=1.5.0 docker buildx bake

variable "OPENTELEMETRY_VERSION" {
  # Override at build time: OPENTELEMETRY_VERSION=1.5.0 docker buildx bake
  default = "1.5.0"
}

variable "IMAGE_NAME" {
  default = "autoinstrumentation-php"
}

variable "TAG" {
  default = "latest"
}

# ---------------------------------------------------------------------------
# Pinned PHP base images — update SHAs here when upgrading PHP versions.
# To add a new PHP version, add four variables below and four targets further
# down, then add four COPY lines to Dockerfile.final.
# ---------------------------------------------------------------------------

# PHP 8.2
variable "PHP_82_NON_ZTS"      { default = "php:8.2@sha256:9277667c0fc298de473509dfed37adf969c97a0372338de990491b39bacf99a5" }
variable "PHP_82_ZTS"          { default = "php:8.2-zts@sha256:f1ea309343a079d12536654de261e96e8b33d293270000fbbdf1cc799afebb12" }
variable "PHP_82_MUSL_NON_ZTS" { default = "php:8.2-alpine@sha256:2b1502df0ae31b813e58b8eef346c48ec21d743e8d0e42abc40331aa7783778e" }
variable "PHP_82_MUSL_ZTS"     { default = "php:8.2-zts-alpine@sha256:2a085af54283e68ebb87fb01ebf97b4d30b8fe5b3cdefabdf7e51e67217e74ed" }

# PHP 8.3
variable "PHP_83_NON_ZTS"      { default = "php:8.3@sha256:22f6151b15f7845352b6e08b85c602f7ea5ac0e52dc8462f2bd69b4d39d587e9" }
variable "PHP_83_ZTS"          { default = "php:8.3-zts@sha256:38e3fd85f4551dfa79807f91655d13cc33ac059bcc4141010cc14de1253b7c84" }
variable "PHP_83_MUSL_NON_ZTS" { default = "php:8.3-alpine@sha256:a1986e9f5180ee8f1bf96aebbf832fa5fe5f077bdc9176c2a2365e32243118f0" }
variable "PHP_83_MUSL_ZTS"     { default = "php:8.3-zts-alpine@sha256:6aa7082189947f11cb065bee7eee19d9eee0371eb3f357d3fe92d5b3db1f5c94" }

# PHP 8.4
variable "PHP_84_NON_ZTS"      { default = "php:8.4@sha256:966621a53c8e75f062fad4e043ffec507cb793822aee3422110e0127fe53952d" }
variable "PHP_84_ZTS"          { default = "php:8.4-zts@sha256:c3b3f84aa03f720545cd56741c76b908b5b73392531697676c202bc9fb6540b1" }
variable "PHP_84_MUSL_NON_ZTS" { default = "php:8.4.1-alpine@sha256:fbaae9d17cbcb784a92be5e6de7b39848e306b221ef0edb218c832418797c8f7" }
variable "PHP_84_MUSL_ZTS"     { default = "php:8.4.1-zts-alpine@sha256:b3b4e31d0301d1dbb8c920f4efe3cd48df2f0a07cbc532c43c6635fb5ad76c24" }

# PHP 8.5
variable "PHP_85_NON_ZTS"      { default = "php:8.5@sha256:1954ff5cd21f222c992b79d25e403b2600cec829678d5bb7076883f3a44c0d6e" }
variable "PHP_85_ZTS"          { default = "php:8.5-zts@sha256:53967f4bcf17cb33d82c594dec23e1edb0fd9ed8d3e0fcca10906c170f1ab0ee" }
variable "PHP_85_MUSL_NON_ZTS" { default = "php:8.5-alpine@sha256:f6975f0b54f3138826ec673961f44375d54f448d3bbfc8a2a8c58228aeeaaba1" }
variable "PHP_85_MUSL_ZTS"     { default = "php:8.5-zts-alpine@sha256:6b2a31dfcf302b0b5fc2f6150671579400dccb21906eb09535251c02fb33d19e" }

# PHP 8.6
variable "PHP_86_NON_ZTS"      { default = "php:8.6-nts@sha256:0b502ac17c9d274b746e843e1c7e84bef755cf1b5bf6cefb84e475f3e4be27d0" }
variable "PHP_86_ZTS"          { default = "php:8.6@sha256:9bc69093cdff73d2dcf12bc34f4c308b8496a27dc3e6a4d0c332877289c6bf13" }
variable "PHP_86_MUSL_NON_ZTS" { default = "php:8.6-nts-alpine@sha256:483e1f3bd03a7c10afe749527053e81ca668966606bdf7c1a7cc906dd20b5ca6" }
variable "PHP_86_MUSL_ZTS"     { default = "php:8.6-alpine@sha256:19c9ad299d11db9b15a2198c1e5aa8345b1907f0ac48731f50cf024d2b64221e" }

# ---------------------------------------------------------------------------
# Shared platform configuration — keep build and final targets aligned.
# ---------------------------------------------------------------------------
target "_platform-base" {
  platforms = ["linux/amd64","linux/arm64","linux/s390x","linux/ppc64le"]
}

# ---------------------------------------------------------------------------
# Shared base target — all build targets inherit from this.
# ---------------------------------------------------------------------------
target "_build-base" {
  inherits   = ["_platform-base"]
  dockerfile = "Dockerfile"
  target     = "builder"
  context    = "."
  args       = { OPENTELEMETRY_VERSION = OPENTELEMETRY_VERSION }
  output     = []
}

# ---------------------------------------------------------------------------
# Per-variant build targets
# ---------------------------------------------------------------------------

# PHP 8.2
target "build-non-zts-82" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_82_NON_ZTS, LIBC = "glibc", THREAD = "non-zts" }
}
target "build-zts-82" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_82_ZTS, LIBC = "glibc", THREAD = "zts" }
}
target "build-non-zts-musl-82" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_82_MUSL_NON_ZTS, LIBC = "musl", THREAD = "non-zts" }
}
target "build-zts-musl-82" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_82_MUSL_ZTS, LIBC = "musl", THREAD = "zts" }
}

# PHP 8.3
target "build-non-zts-83" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_83_NON_ZTS, LIBC = "glibc", THREAD = "non-zts" }
}
target "build-zts-83" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_83_ZTS, LIBC = "glibc", THREAD = "zts" }
}
target "build-non-zts-musl-83" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_83_MUSL_NON_ZTS, LIBC = "musl", THREAD = "non-zts" }
}
target "build-zts-musl-83" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_83_MUSL_ZTS, LIBC = "musl", THREAD = "zts" }
}

# PHP 8.4
target "build-non-zts-84" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_84_NON_ZTS, LIBC = "glibc", THREAD = "non-zts" }
}
target "build-zts-84" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_84_ZTS, LIBC = "glibc", THREAD = "zts" }
}
target "build-non-zts-musl-84" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_84_MUSL_NON_ZTS, LIBC = "musl", THREAD = "non-zts" }
}
target "build-zts-musl-84" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_84_MUSL_ZTS, LIBC = "musl", THREAD = "zts" }
}

# PHP 8.5
target "build-non-zts-85" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_85_NON_ZTS, LIBC = "glibc", THREAD = "non-zts" }
}
target "build-zts-85" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_85_ZTS, LIBC = "glibc", THREAD = "zts" }
}
target "build-non-zts-musl-85" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_85_MUSL_NON_ZTS, LIBC = "musl", THREAD = "non-zts" }
}
target "build-zts-musl-85" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_85_MUSL_ZTS, LIBC = "musl", THREAD = "zts" }
}

# PHP 8.6
target "build-non-zts-86" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_86_NON_ZTS, LIBC = "glibc", THREAD = "non-zts" }
}
target "build-zts-86" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_86_ZTS, LIBC = "glibc", THREAD = "zts" }
}
target "build-non-zts-musl-86" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_86_MUSL_NON_ZTS, LIBC = "musl", THREAD = "non-zts" }
}
target "build-zts-musl-86" {
  inherits = ["_build-base"]
  args = { PHP_IMAGE = PHP_86_MUSL_ZTS, LIBC = "musl", THREAD = "zts" }
}

# ---------------------------------------------------------------------------
# Final image — assembles all build artifacts into a single busybox image.
# ---------------------------------------------------------------------------
target "final" {
  inherits   = ["_platform-base"]
  dockerfile = "Dockerfile.final"
  context    = "."
  tags       = ["${IMAGE_NAME}:${TAG}"]
  contexts = {
    build-non-zts-82      = "target:build-non-zts-82"
    build-zts-82          = "target:build-zts-82"
    build-non-zts-musl-82 = "target:build-non-zts-musl-82"
    build-zts-musl-82     = "target:build-zts-musl-82"
    build-non-zts-83      = "target:build-non-zts-83"
    build-zts-83          = "target:build-zts-83"
    build-non-zts-musl-83 = "target:build-non-zts-musl-83"
    build-zts-musl-83     = "target:build-zts-musl-83"
    build-non-zts-84      = "target:build-non-zts-84"
    build-zts-84          = "target:build-zts-84"
    build-non-zts-musl-84 = "target:build-non-zts-musl-84"
    build-zts-musl-84     = "target:build-zts-musl-84"
    build-non-zts-85      = "target:build-non-zts-85"
    build-zts-85          = "target:build-zts-85"
    build-non-zts-musl-85 = "target:build-non-zts-musl-85"
    build-zts-musl-85     = "target:build-zts-musl-85"
    build-non-zts-86      = "target:build-non-zts-86"
    build-zts-86          = "target:build-zts-86"
    build-non-zts-musl-86 = "target:build-non-zts-musl-86"
    build-zts-musl-86     = "target:build-zts-musl-86"
  }
}

group "default" {
  targets = ["final"]
}
