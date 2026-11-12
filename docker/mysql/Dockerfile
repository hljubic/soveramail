FROM golang:1.25.13-alpine AS gosu-builder

# MySQL's entrypoint needs gosu only to drop privileges. Rebuild the same
# upstream release, pinned to its tagged commit, with a Go runtime containing
# the current stdlib fixes.
RUN apk add --no-cache git \
    && git init /src \
    && git -C /src remote add origin https://github.com/tianon/gosu.git \
    && git -C /src fetch --depth 1 origin 6456aaa0f3c854d199d0f037f068eb97515b7513 \
    && git -C /src checkout --detach FETCH_HEAD
WORKDIR /src
RUN GOTOOLCHAIN=local CGO_ENABLED=0 go build -ldflags='-s -w' -o /go/bin/gosu .

FROM mysql:8.4

# Keep Oracle Linux packages current and remove MySQL Shell. The application
# uses the server and mysqladmin only; mysqlsh was unused and carried its own
# vulnerable Python/crypto dependency tree.
RUN microdnf update -y \
    && microdnf clean all \
    && sed -i 's/exec gosu mysql/exec gosu-fixed mysql/' /usr/local/bin/docker-entrypoint.sh \
    && rm -rf /usr/lib/mysqlsh /usr/bin/mysqlsh /usr/local/bin/gosu

COPY --from=gosu-builder /go/bin/gosu /usr/local/bin/gosu-fixed
