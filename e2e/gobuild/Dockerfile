# Copied into the e2e Git repository next to a statically linked binary (no registry pulls).
FROM scratch
COPY app /app
EXPOSE 8080
USER 65534
ENTRYPOINT ["/app"]
