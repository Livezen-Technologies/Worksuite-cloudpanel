# Coolify service templates

The `*.yaml` files in this folder are converted from Coolify's service templates
(https://github.com/coollabsio/coolify/tree/main/templates/compose), copyright Coolify
contributors, licensed under the Apache License 2.0
(https://www.apache.org/licenses/LICENSE-2.0). Changes made in conversion: Coolify's
magic variables mapped to this panel's (`SERVICE_URL`, `SERVICE_FQDN`, `SERVICE_PASSWORD_*`,
`SERVICE_USER_*`, `SERVICE_HEX_*`, `SERVICE_BASE64_*`), defaults inlined, host ports removed,
relative bind mounts turned into named volumes, and Traefik labels added to the web service.
Templates that need host paths, the Docker socket, privileged mode, host networking, extra
Linux capabilities or inline config files are not imported; `../coolify-skipped.json`
lists each with the reason.
