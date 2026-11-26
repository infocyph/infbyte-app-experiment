FROM debian:trixie-slim
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends pgpool2 && rm -rf /var/lib/apt/lists/*
USER postgres
ENTRYPOINT ["pgpool", "-n", "-f", "/config/pgpool.conf", "-F", "/config/pcp.conf"]
