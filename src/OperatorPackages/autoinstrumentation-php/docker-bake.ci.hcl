target "_build-base" {
  target = "artifact"
}

target "final-ci" {
  inherits = ["final"]
  contexts = {
    build-non-zts-81      = "oci-layout://./artifacts/build-non-zts-81"
    build-zts-81          = "oci-layout://./artifacts/build-zts-81"
    build-non-zts-musl-81 = "oci-layout://./artifacts/build-non-zts-musl-81"
    build-zts-musl-81     = "oci-layout://./artifacts/build-zts-musl-81"
    build-non-zts-82      = "oci-layout://./artifacts/build-non-zts-82"
    build-zts-82          = "oci-layout://./artifacts/build-zts-82"
    build-non-zts-musl-82 = "oci-layout://./artifacts/build-non-zts-musl-82"
    build-zts-musl-82     = "oci-layout://./artifacts/build-zts-musl-82"
    build-non-zts-83      = "oci-layout://./artifacts/build-non-zts-83"
    build-zts-83          = "oci-layout://./artifacts/build-zts-83"
    build-non-zts-musl-83 = "oci-layout://./artifacts/build-non-zts-musl-83"
    build-zts-musl-83     = "oci-layout://./artifacts/build-zts-musl-83"
    build-non-zts-84      = "oci-layout://./artifacts/build-non-zts-84"
    build-zts-84          = "oci-layout://./artifacts/build-zts-84"
    build-non-zts-musl-84 = "oci-layout://./artifacts/build-non-zts-musl-84"
    build-zts-musl-84     = "oci-layout://./artifacts/build-zts-musl-84"
    build-non-zts-85      = "oci-layout://./artifacts/build-non-zts-85"
    build-zts-85          = "oci-layout://./artifacts/build-zts-85"
    build-non-zts-musl-85 = "oci-layout://./artifacts/build-non-zts-musl-85"
    build-zts-musl-85     = "oci-layout://./artifacts/build-zts-musl-85"
  }
}
