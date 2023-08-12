# Development

```shell
docker build . -t otel-installer:latest
docker run --rm -it -v $(pwd):/srv/app otel-installer:latest
./install-otel-instrumentation <mode> <stability>
```