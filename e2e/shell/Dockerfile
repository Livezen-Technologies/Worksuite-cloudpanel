# Terminal e2e image: static mini shell as /bin/sh, non-root user "app" (uid 1000). Built locally, FROM scratch.
FROM scratch
COPY sh /bin/sh
COPY passwd /etc/passwd
COPY group /etc/group
WORKDIR /
USER 1000:1000
ENTRYPOINT ["/bin/sh", "--serve"]
