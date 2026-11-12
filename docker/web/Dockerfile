FROM node:24-alpine

# Upgrade the Alpine base and npm's bundled helper dependencies, which are not
# covered by the application's package-lock.json.
RUN apk upgrade --no-cache \
    && npm install --global npm@12.0.2 \
    && mkdir -p /tmp/npm-fixes \
    && npm pack --pack-destination /tmp/npm-fixes brace-expansion@5.0.9 tar@7.5.21 ip-address@10.3.1 \
    && rm -rf /usr/local/lib/node_modules/npm/node_modules/brace-expansion \
        /usr/local/lib/node_modules/npm/node_modules/tar \
        /usr/local/lib/node_modules/npm/node_modules/ip-address \
    && mkdir -p /usr/local/lib/node_modules/npm/node_modules/brace-expansion \
        /usr/local/lib/node_modules/npm/node_modules/tar \
        /usr/local/lib/node_modules/npm/node_modules/ip-address \
    && tar -xzf /tmp/npm-fixes/brace-expansion-5.0.9.tgz --strip-components=1 \
        -C /usr/local/lib/node_modules/npm/node_modules/brace-expansion \
    && tar -xzf /tmp/npm-fixes/tar-7.5.21.tgz --strip-components=1 \
        -C /usr/local/lib/node_modules/npm/node_modules/tar \
    && tar -xzf /tmp/npm-fixes/ip-address-10.3.1.tgz --strip-components=1 \
        -C /usr/local/lib/node_modules/npm/node_modules/ip-address \
    && npm cache clean --force
