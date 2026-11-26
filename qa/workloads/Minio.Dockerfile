FROM golang:1.26 AS build
RUN CGO_ENABLED=0 GOBIN=/out go install github.com/minio/minio@latest
FROM debian:trixie-slim
COPY --from=build /out/minio /usr/local/bin/minio
ENTRYPOINT ["/usr/local/bin/minio"]
