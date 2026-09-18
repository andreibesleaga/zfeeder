# deploy/

Everything needed to run zFeeder somewhere other than a developer's laptop.
The instructions that go with these files are in [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).

| File | What it is for |
|---|---|
| `Dockerfile` | The production image: PHP 8.3 and Apache, document root at `public/`, data on a volume at `/var/www/data`, non-root, health check on `/healthz`. Build from the repository root with `-f deploy/Dockerfile`. |
| `docker-compose.yml` | Running that image locally with a named volume. |
| `entrypoint.sh` | Seeds an empty data directory from `data-dist/` on first boot and applies SQLite migrations when that backend is selected. |
| `php.ini` | Production PHP settings baked into the image. `allow_url_fopen` is off: feeds go through the HTTP client, which enforces the address rules. |
| `apache-vhost.conf` | Document root rules, the rewrite to the front controller, cache headers, and a deny rule for the data directory. |
| `nginx.conf.example` | The same, for nginx with PHP-FPM. |
| `shared-hosting.htaccess` | Dropped in as `public/.htaccess` when there is no access to the server configuration. |
| `fly.toml.example`, `render.yaml.example`, `k8s.yaml` | Starting points for Fly.io, Render and Kubernetes. |

There is no Railway configuration file. Railway's `DOCKERFILE` builder enum no longer
exists, and `railway.toml` is deprecated, so the service is configured with variables
instead: `RAILWAY_DOCKERFILE_PATH=deploy/Dockerfile` selects this image, and a volume
mounted at `/var/www/data` holds the data. See [../docs/DEPLOYMENT.md](../docs/DEPLOYMENT.md).
