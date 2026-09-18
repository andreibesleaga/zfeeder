# Deployment

zFeeder 2.0 is a PHP application with no database server, no build step and no
background daemon. It needs a web server that can run PHP 8.3, a directory it
can write to, and — if you want feeds refreshed without a visitor triggering
it — a cron entry.

This page has one section per deployment target. Each is a recipe: what you
need, the commands, the environment to set, how to check it worked, and how to
upgrade it later. [`docs/RUNBOOK.md`](RUNBOOK.md) covers operating it once it
runs; [`docs/CONFIGURATION.md`](CONFIGURATION.md) is the full list of options.

## Contents

- [Before you start](#before-you-start)
- [Docker](#docker)
- [Docker Compose](#docker-compose)
- [Railway](#railway)
- [Fly.io](#flyio)
- [Render](#render)
- [Kubernetes](#kubernetes)
- [A plain VPS: nginx or Apache](#a-plain-vps-nginx-or-apache)
- [Shared hosting, from the release archive](#shared-hosting-from-the-release-archive)
- [Keeping the feeds fresh](#keeping-the-feeds-fresh)
- [TLS and reverse proxies](#tls-and-reverse-proxies)
- [Upgrading](#upgrading)
- [Troubleshooting](#troubleshooting)

## Before you start

### Requirements

| | |
|---|---|
| PHP | 8.3 or newer |
| Required extensions | `dom`, `json`, `libxml`, `mbstring`, `simplexml` |
| Extra extension for `ZF_STORAGE=sqlite` | `pdo_sqlite` |
| Suggested | `curl`, for a faster HTTP transport when fetching feeds |
| Web server | anything that can send every non-file request to `public/index.php` |
| Disk | the feed cache: roughly the size of the feeds you subscribe to |

The list comes from `composer.json`. `bin/zfeeder check-config` checks the same
set at runtime and names anything missing.

### Two rules that apply to every target

1. **The document root is `public/`.** Nothing above it may be reachable over
   HTTP: `src/`, `vendor/`, `templates/` and `composer.json` are not meant to be
   served.
2. **The data directory must be outside `public/`.** It holds the OPML
   subscription files, the feed cache and the log.
   `ConfigLoader::assertDataOutsideWebRoot()` refuses to boot if `ZF_DATA_DIR`,
   `ZF_CATEGORIES_DIR` or `ZF_CACHE_DIR` resolves inside the web root, so a
   mistake here stops the application rather than leaking the files.

### Three ways to configure it

Environment beats `data/config.json` beats the schema default. An option set in
the environment shows as read-only in the admin panel and cannot be changed
there. `bin/zfeeder check-config` prints the effective value of every option and
where it came from. See [`docs/CONFIGURATION.md`](CONFIGURATION.md) for all 44
options.

**Where the configuration file lives.** `ConfigLoader` reads
`<project root>/data/config.json` — the path is built from the project root, not
from `ZF_DATA_DIR`. When `ZF_DATA_DIR` is unset the two are the same directory
and everything sits together. When `ZF_DATA_DIR` points elsewhere, which every
container recipe below does, the subscriptions, cache, log and rate-limit files
move but the configuration file does not: it stays at `<project root>/data/config.json`,
which inside an image means the container's own filesystem. Two consequences:

- **Configure a container through environment variables.** A `config.json`
  placed in the mounted data directory is not read. `bin/zfeeder check-config`
  prints the exact path it used on the `Config file` line — check it if a
  setting is not taking effect.
- **A change made in the admin panel — including a password change — is written
  to `<project root>/data/config.json` and does not survive a redeploy** of a
  container whose data volume is mounted somewhere else. Pin those settings in
  the environment instead.

The variables that come up in every recipe below:

| Variable | Why it matters |
|---|---|
| `ZF_DATA_DIR` | where subscriptions, cache and log live |
| `ZF_ADMIN_PASSWORD_HASH` | without it `/admin` answers 503 and there is no way in |
| `ZF_URL` | the public URL, with a trailing slash. Unset means the site root, `/`, which is right for anything served at the top of a domain; set it only for a subdirectory install |
| `ZF_STORAGE` | `flat` (OPML files) or `sqlite` (one file) |
| `ZF_TRUSTED_PROXIES` | required behind a load balancer, or the session cookie is never marked Secure |
| `ZF_LOG_PATH` | `php://stdout` in a container, a file elsewhere |
| `ZF_REFRESH_KEY` | the shared secret for `GET /refresh?key=…`; unset means that endpoint is closed |

Create the password hash before you deploy anything:

```console
$ bin/zfeeder hash-password
Password: (not echoed)
Repeat:   (not echoed)
$argon2id$v=19$m=65536,t=4,p=1$...
ZF_ADMIN_PASSWORD_HASH=$argon2id$v=19$m=65536,t=4,p=1$...
"admin_password_hash": "$argon2id$v=19$m=65536,t=4,p=1$..."
```

Three lines on standard output: the bare hash, the shell assignment and the JSON
fragment. Pipe the first through `head -1` when you want just the hash. The
minimum password length is 12 characters.

### Which target

| Target | Persistent storage | Effort | Suits |
|---|---|---|---|
| Docker | a named volume | low | one machine, one instance |
| Docker Compose | a named volume | low | the same, declared in a file |
| Railway | a volume you attach | low | the public demo; Git-push deploys |
| Fly.io | a Fly volume | medium | one small machine close to you |
| Render | a paid disk | low | Git-push deploys, cron as a separate service |
| Kubernetes | a PersistentVolumeClaim | high | an existing cluster |
| VPS + nginx/Apache | the filesystem | medium | full control, cheapest at scale |
| Shared hosting | the filesystem | low | no shell, no Docker, FTP only |

---

## Docker

The image is built from [`deploy/Dockerfile`](../deploy/Dockerfile): a
`composer:2` stage that installs the production dependencies, then
`php:8.3-apache`, which already ships every extension zFeeder needs, so nothing
is compiled. It enables `rewrite`, `headers` and `expires`, sets the document
root to `/var/www/html/public`, exposes port 80 and carries a `HEALTHCHECK` that
curls `/healthz` on `${PORT:-80}`.

Four things about it are worth knowing before you put it on a platform, because
each one exists to work around something a platform does.

- **There is no `VOLUME` instruction.** Railway rejects an image that declares
  one, and the instruction is advisory everywhere else. Mount your persistent
  storage at `/var/www/data` when you run the image instead; every recipe below
  does.
- **There is no `USER` instruction.** Apache keeps the conventional split: the
  master process starts as root so it can bind the port and take ownership of a
  volume that has just been mounted, and every worker that handles a request runs
  as `www-data` (`APACHE_RUN_USER` in the base image). A container that starts as
  `www-data` throughout cannot initialise an empty volume, which is how most
  platforms present one on the first boot.
- **`autoindex` and `status` are left enabled.** Disabling them needs
  `a2dismod -f`, because other modules declare a dependency on `autoindex`, and
  forcing it has been observed to leave a second MPM enabled, which Apache
  refuses to start with. Neither module exposes anything: directory listing is
  off through `Options -Indexes` in the vhost fragment, and `mod_status` serves
  nothing without a `SetHandler` that is never configured. The build asserts
  `apache2ctl -t` and exactly one loaded MPM, and the entrypoint repeats the MPM
  check at boot in case a platform rebuilt or patched the base layer.
- **The listening port is decided at boot, not at build.** See the next section.

### Prerequisites

Docker. Nothing else — PHP and Composer are only needed inside the build.

### Run the published image

```bash
docker volume create zfeeder-data

docker run -d --name zfeeder \
  -p 8080:80 \
  -v zfeeder-data:/var/www/data \
  -e ZF_ADMIN_PASSWORD_HASH='PASTE THE HASH HERE' \
  -e ZF_TEMPLATE_SET=modern \
  -e ZF_LOG_PATH=php://stdout \
  -e ZF_LOG_LEVEL=info \
  ghcr.io/andreibesleaga/zfeeder:2.0.0
```

The published tags are the full version, the major-minor pair and `latest`; the
image is built for `linux/amd64` and `linux/arm64` (see
[`.github/workflows/release.yml`](../.github/workflows/release.yml)).

### Build it yourself

The build context is the repository root, not `deploy/`:

```bash
docker build -f deploy/Dockerfile -t zfeeder:local .
```

### What the entrypoint does on every boot

[`deploy/entrypoint.sh`](../deploy/entrypoint.sh) runs before Apache, in this
order:

1. **Asserts one MPM.** If more than one `mpm_*.load` is enabled it removes
   `mpm_event` and `mpm_worker` and keeps `mpm_prefork`, because Apache refuses
   to start with "More than one MPM loaded".
2. **Rewrites the listening port.** `LISTEN_PORT="${PORT:-80}"`; the script
   writes `Listen $LISTEN_PORT` into `/etc/apache2/ports.conf`, rewrites
   `<VirtualHost *:…>` in `000-default.conf` to match, and sets `ServerName
   localhost` through a new `zf-servername` conf. Railway, Cloud Run, Heroku and
   Fly all tell the container which port to bind through `PORT` and health-check
   that port; Apache's default of 80 has to be reconciled with it somewhere, and
   this is the only place that can see the value.
3. **Takes ownership of the data directory.** It creates
   `$ZF_DATA_DIR/categories` and `$ZF_DATA_DIR/cache`, then `chown -R
   www-data:www-data` and `chmod 0750`. A newly attached volume arrives owned by
   root on most platforms, so this has to happen as root, before anything tries
   to write. It is why the image has no `USER` instruction.
4. **Ensures the SQLite schema.** `bin/zfeeder migrate --ensure-schema`, run as
   `www-data` through `su` so that anything it creates is owned correctly. It
   exits cleanly when the flat backend is in use, so it is unconditional.
5. **Seeds.** Copies `data-dist/config.json.dist` to `$ZF_DATA_DIR/config.json`
   if that file does not exist, then runs `bin/zfeeder seed`, again as
   `www-data`.
6. **Re-applies ownership** and `exec`s the command, normally
   `apache2-foreground`.

Steps 1 to 3 and the final `chown` are guarded by `[ "$(id -u)" = "0" ]`, so the
image still starts if you run it with `--user`; it just cannot fix a volume's
ownership in that case.

**Why seeding goes through the CLI.** An earlier version copied
`data-dist/categories/*.opml` into the data directory behind a `.seeded` marker
file. That only works for the flat backend: with `ZF_STORAGE=sqlite` the files
are never read, the installation starts with no categories, and every page
renders an error. `bin/zfeeder seed` writes through the same store interface the
admin panel uses, so a first boot looks the same either way.

**Why it runs on every boot rather than once.** `seed` leaves a category that
already exists alone unless `--force` is given, so the repeat costs a few
milliseconds. A marker file would be worse than useless: it would record that
seeding happened even when it did not take effect, so a volume created by an
older version, or one whose categories were emptied, would never repair itself.

The seeded subscriptions are read; the seeded `config.json` is not. The image
sets `ZF_DATA_DIR=/var/www/data` while the project root is `/var/www/html`, and
`ConfigLoader` looks for `/var/www/html/data/config.json`. Configure the
container with `-e ZF_*` variables, as the commands here do, and treat the
defaults in `data-dist/config.json.dist` as what a source install gets, not what
this container gets.

### Verify

```bash
curl -fsS http://127.0.0.1:8080/healthz
docker inspect --format '{{.State.Health.Status}}' zfeeder
```

`/healthz` returns JSON with `status`, `version`, `storage` and two checks. A
200 means the data directory is writable. Then open
<http://127.0.0.1:8080/> for the demo site and
<http://127.0.0.1:8080/admin> for the panel.

The repository also has a scripted check that starts a container and asserts the
real endpoints and the security headers — the same one CI runs:

```bash
./tools/smoke-image.sh zfeeder:local
```

### Upgrade

```bash
docker pull ghcr.io/andreibesleaga/zfeeder:2.0.1
docker rm -f zfeeder
docker run -d --name zfeeder ...   # same flags, same volume, new tag
```

The volume carries the data across. Read [`CHANGELOG.md`](../CHANGELOG.md)
first, and back the volume up — [`docs/RUNBOOK.md`](RUNBOOK.md#backup) has the
command.

---

## Docker Compose

[`deploy/docker-compose.yml`](../deploy/docker-compose.yml) builds the image from
the repository, publishes `8080:80`, keeps the data in a named volume
`zfeeder-data` mounted at `/var/www/data`, and restarts unless stopped.

### Prerequisites

Docker with the Compose plugin, and a checkout of the repository.

### Run it

```bash
export ZF_ADMIN_PASSWORD_HASH="$(php bin/zfeeder hash-password | head -1)"
docker compose -f deploy/docker-compose.yml up --build -d
```

`ZF_ADMIN_PASSWORD_HASH` is the only value the file takes from your shell; the
rest are written into it. Change them there rather than on the command line.

### Verify

```bash
docker compose -f deploy/docker-compose.yml ps
curl -fsS http://127.0.0.1:8080/healthz
docker compose -f deploy/docker-compose.yml logs -f zfeeder
```

Because the file sets `ZF_LOG_PATH: php://stdout`, the application log appears
in `docker compose logs` alongside Apache's.

### Upgrade

```bash
git pull
docker compose -f deploy/docker-compose.yml up --build -d
```

### Refresh from the host

```bash
docker compose -f deploy/docker-compose.yml exec zfeeder bin/zfeeder refresh
```

---

## Railway

Railway runs the public demo, at **<https://zfeeder.up.railway.app>**.

There is no `railway.toml` in this repository. The service is configured through
Railway variables and one volume, for the reasons in the next two paragraphs.
`.railwayignore` at the repository root is the one file that does affect the
build: it keeps `tests/`, `tools/`, `vendor/`, `project/` and the
development-only configuration out of the build context, and is deliberately
anchored so that `docs/` is still shipped, because the running instance links to
it.

### Prerequisites

The Railway CLI, and an account. Signing in is an owner-side step:

```bash
railway login
```

### Create the service

```bash
railway init                 # or: railway link, to attach to an existing project
railway volume add --mount-path /var/www/data
railway variables --set RAILWAY_DOCKERFILE_PATH=deploy/Dockerfile
railway up
```

**Railway picks the Dockerfile through a variable, not through a config file.**
A `builder = "DOCKERFILE"` setting in `railway.toml` is not how a Dockerfile
outside the repository root is selected; `RAILWAY_DOCKERFILE_PATH` is, and
without it the build either uses Nixpacks or fails to find a Dockerfile. This is
the single most common reason a Railway deployment of this image does not build.

**Railway rejects a `VOLUME` instruction**, which is why `deploy/Dockerfile` has
none, and why the mount is an explicit `railway volume add`. The mount path must
be `/var/www/data`, which is what `ZF_DATA_DIR` is set to in the image.

**The volume is not optional.** Without it every redeploy starts from a freshly
seeded copy: every subscription added through the panel, and the whole feed
cache, disappear. The loss looks like a reset rather than an error, because the
entrypoint reseeds the empty directory on the way up.

**The image must listen on `$PORT`.** Railway assigns a port, sets `PORT` in the
environment and health-checks that port; a container listening on a hard-coded
80 is reported unhealthy and cycled. `deploy/entrypoint.sh` rewrites Apache's
`ports.conf` and the default vhost from `PORT` at boot, so nothing has to be set
for this — but if you replace the entrypoint, this is what you have to keep.

**The entrypoint must be able to run as root.** A freshly attached Railway
volume is owned by root and mounted empty. The entrypoint takes ownership before
Apache drops to `www-data`. Setting a non-root user on the service breaks the
first boot with permission errors on `/var/www/data`.

All five points apply just as much to Fly, Render and Cloud Run: each assigns a
port through `PORT`, each mounts storage as a separate declaration rather than
from a `VOLUME` line, and each presents a new volume owned by root.

Settings saved in the panel are a separate matter, and the volume does not save
them either: they go to `/var/www/html/data/config.json`, inside the container.
Pin anything you care about in the environment.

### Environment

Set these in the Railway dashboard, or with `railway variables --set`:

| Variable | Value | Why |
|---|---|---|
| `ZF_ADMIN_PASSWORD_HASH` | the Argon2id hash | without it `/admin` answers 503 |
| `ZF_TRUSTED_PROXIES` | `*` | Railway terminates TLS at its edge; see [TLS and reverse proxies](#tls-and-reverse-proxies) |
| `ZF_URL` | `https://zfeeder.up.railway.app/` | optional; unset means the site root, which is correct here |
| `ZF_LOG_PATH` | `php://stdout` | so the log shows in `railway logs` |
| `ZF_LOG_LEVEL` | `info` | |
| `ZF_TEMPLATE_SET` | `modern` | |
| `ZF_DEMO_MODE` | `true` | makes the panel read-only for a public demo |
| `ZF_REFRESH_KEY` | a long random string | lets a scheduler call `/refresh?key=…` |

`ZF_ENV=production` and `ZF_DATA_DIR=/var/www/data` are already baked into the
image and do not need setting. `RAILWAY_DOCKERFILE_PATH=deploy/Dockerfile` has
to be set, as above.

`ZF_URL` is optional now: an unset value means the site root, `/`, so
`{scripturl}` resolves without configuration on any installation served at the
top of a domain — which is what Railway, Fly, Render and the development server
all are. Set it only when zFeeder is served from a subdirectory, and then it must
end with a slash.

`ZF_DEMO_MODE=true` leaves the panel browsable but refuses every unsafe HTTP
method with 403 — `src/Admin/Middleware/DemoMode.php` allows only `GET`, `HEAD`
and `OPTIONS`, plus sign-in and sign-out. Visitors can look at every screen and
change nothing.

### Verify

```bash
railway open                                        # opens the deployed URL
curl -fsS https://zfeeder.up.railway.app/healthz
curl -sI https://zfeeder.up.railway.app/admin | grep -i content-security-policy
railway logs
```

### Switching the demo off

`ZF_DEMO_ENABLED=false` takes the site down without deleting anything. The front
controller checks it before it routes anything and serves a short "This zFeeder
instance is paused" page with status 503.

```bash
railway variables --set ZF_DEMO_ENABLED=false     # paused
railway variables --set ZF_DEMO_ENABLED=true      # back
```

**This also switches off `/healthz`**, because the check happens before routing:
every path, including the health endpoint, gets the 503 page. Railway's health
check will therefore fail while the site is paused, and the deployment will be
reported unhealthy and may be restarted. Pause a service only when you are
willing to see that, and note that `ZF_DEMO_MODE` (read-only panel) and
`ZF_DEMO_ENABLED` (whole site off) are different switches.

### Upgrade

`railway up` from an updated checkout, or connect the GitHub repository and let
a push to `main` deploy. Either way the volume is untouched.

---

## Fly.io

### Prerequisites

`flyctl`, and an account.

### Deploy

```bash
cp deploy/fly.toml.example fly.toml
# edit: app name, primary_region
fly apps create zfeeder
fly volumes create zfeeder_data --size 1 --region cdg
fly secrets set ZF_ADMIN_PASSWORD_HASH="$(php bin/zfeeder hash-password | head -1)"
fly secrets set ZF_REFRESH_KEY="$(openssl rand -hex 24)"
fly deploy
```

Run everything from the repository root: `[build] dockerfile = "deploy/Dockerfile"`
is resolved against the build context, which is the root.

[`deploy/fly.toml.example`](../deploy/fly.toml.example) sets `internal_port = 80`,
forces HTTPS, runs an HTTP check against `/healthz` every 30 seconds, mounts
`zfeeder_data` at `/var/www/data`, and sets `ZF_TRUSTED_PROXIES = "*"` because
Fly's proxy terminates TLS.

`internal_port` and the port Apache binds have to agree. Fly does not set `PORT`
unless you put it in the `[env]` block, so the entrypoint falls back to 80, which
is what `internal_port = 80` expects. If you change one, set the other: with
`PORT = "8080"` in `[env]`, `internal_port` must be `8080`.

The Fly volume behaves like the Railway one: it arrives owned by root, and the
entrypoint has to run as root long enough to take ownership of it before Apache
drops to `www-data`. Do not add a `USER` to the image.

`min_machines_running = 1` is deliberate. A machine that has been auto-stopped
runs no cron, so feeds go stale until the next visitor wakes it.

### Verify

```bash
fly status
fly logs
curl -fsS https://zfeeder.fly.dev/healthz
```

### Upgrade

```bash
git pull
fly deploy
```

The volume survives. Fly attaches it to the new machine.

---

## Render

### Prerequisites

A Render account with the repository connected. A **paid instance type**: the
persistent disk that holds the data directory is not available on the free tier.

### Deploy

```bash
cp deploy/render.yaml.example render.yaml
# edit: region, and the cron job's ZF_TARGET_URL
```

Commit `render.yaml` and point a Render Blueprint at the repository.
[`deploy/render.yaml.example`](../deploy/render.yaml.example) declares a `web`
service built from `deploy/Dockerfile` with the repository root as the build
context, a 1 GB disk mounted at `/var/www/data`, `healthCheckPath: /healthz`, and
`PORT=80`. Render sets `PORT` for every web service; the entrypoint reads it and
makes Apache listen there, so pinning it to 80 simply fixes both ends at the
image's documented default. Leaving `PORT` at whatever Render assigns works too
— the entrypoint follows it.

`ZF_ADMIN_PASSWORD_HASH` and `ZF_REFRESH_KEY` are declared with `sync: false`:
Render prompts for them once and keeps them out of the repository.

Without the disk, subscriptions and the feed cache are recreated from
`data-dist/` on every deploy and every cold start.

### Refreshing

A Render cron job runs in its own container and cannot mount the web service's
disk, so `bin/zfeeder refresh` there would refresh a different, empty data
directory. The blueprint therefore declares a cron service that calls the HTTP
trigger instead:

```
curl -fsS "$ZF_TARGET_URL/refresh?key=$ZF_REFRESH_KEY"
```

Set the same `ZF_REFRESH_KEY` on both services. With no key configured,
`/refresh` answers 403 with `Refreshing over HTTP needs a refresh key.`

### Verify

```bash
curl -fsS https://zfeeder.onrender.com/healthz
```

and read the deploy log in the dashboard.

### Upgrade

Push to the tracked branch; `autoDeploy: true` does the rest.

---

## Kubernetes

[`deploy/k8s.yaml`](../deploy/k8s.yaml) is a complete manifest: a ConfigMap for
the plain configuration, a 1 GB `ReadWriteOnce` PersistentVolumeClaim, a
one-replica Deployment with `Recreate` strategy, a ClusterIP Service, a CronJob
that calls `/refresh`, and a commented Ingress.

### Prerequisites

A cluster, `kubectl`, and a default StorageClass (or edit `storageClassName` in
the PVC).

### Deploy

```bash
kubectl create namespace zfeeder

kubectl -n zfeeder create secret generic zfeeder \
  --from-literal=ZF_ADMIN_PASSWORD_HASH="$(php bin/zfeeder hash-password | head -1)" \
  --from-literal=ZF_REFRESH_KEY="$(openssl rand -hex 24)"

kubectl -n zfeeder apply -f deploy/k8s.yaml
```

Three details in the manifest are worth knowing before you change them:

- **`runAsUser: 33` and `fsGroup: 33`.** The image runs as `www-data`, uid 33.
  `fsGroup` is what makes the mounted volume writable without an init container.
- **`capabilities: add: ["NET_BIND_SERVICE"]`.** Apache binds port 80 as a
  non-root user. Docker lowers `net.ipv4.ip_unprivileged_port_start` to 0 by
  default, so this is invisible locally; Kubernetes does not, so the capability
  has to be granted or the container will not start.
- **One replica, `Recreate`.** A `ReadWriteOnce` volume cannot be mounted by the
  old and the new pod at once. zFeeder keeps no state in the process, but two
  replicas would need `ReadWriteMany` storage.

### Verify

```bash
kubectl -n zfeeder rollout status deploy/zfeeder
kubectl -n zfeeder get pod -l app.kubernetes.io/name=zfeeder
kubectl -n zfeeder port-forward svc/zfeeder 8080:80 &
curl -fsS http://127.0.0.1:8080/healthz
kubectl -n zfeeder logs deploy/zfeeder
```

The startup, readiness and liveness probes all use `/healthz`, so a pod that
cannot write its data directory never becomes ready.

### Upgrade

```bash
kubectl -n zfeeder set image deploy/zfeeder zfeeder=ghcr.io/andreibesleaga/zfeeder:2.0.1
kubectl -n zfeeder rollout status deploy/zfeeder
kubectl -n zfeeder rollout undo deploy/zfeeder      # if it goes wrong
```

With `Recreate` there is a short gap between the old pod stopping and the new
one becoming ready.

---

## A plain VPS: nginx or Apache

### Prerequisites

Root on the machine, PHP 8.3 with the extensions listed above, and either nginx
with PHP-FPM or Apache with `mod_rewrite`.

### Install the files

Either unpack a release archive, which already contains `vendor/`:

```bash
curl -fsSLO https://github.com/andreibesleaga/zfeeder/releases/download/v2.0.0/zfeeder-2.0.0.zip
unzip zfeeder-2.0.0.zip -d /var/www
mv /var/www/zfeeder-2.0.0 /var/www/zfeeder
```

or install from a checkout:

```bash
git clone https://github.com/andreibesleaga/zfeeder.git /var/www/zfeeder
cd /var/www/zfeeder
composer install --no-dev --optimize-autoloader
```

Then create the data directory and give it to the web server user. The default
layout — `data/` beside the code, one level above `public/` — is the one to use
on a VPS: it is already outside the document root, and it is the only layout
where `config.json` and the subscriptions live together, because `ConfigLoader`
always reads `<project root>/data/config.json`.

```bash
mkdir -p /var/www/zfeeder/data/categories /var/www/zfeeder/data/cache
cp /var/www/zfeeder/data-dist/config.json.dist  /var/www/zfeeder/data/config.json
chown -R www-data:www-data /var/www/zfeeder/data
chmod 0750 /var/www/zfeeder/data

# Load the shipped example subscriptions into whichever backend is configured.
sudo -u www-data /var/www/zfeeder/bin/zfeeder seed
```

Copying `data-dist/categories/*.opml` into `data/categories/` does the same
thing for the flat backend only. `bin/zfeeder seed` writes through the store
interface, so it works with `ZF_STORAGE=sqlite` as well, and it leaves a
category that already exists alone. `bin/zfeeder seed --dry-run` reports what it
would do.

If you would rather keep the data on a separate filesystem, set `ZF_DATA_DIR` to
that path. The subscriptions, cache, log and rate-limit files move there;
`config.json` stays at `<project root>/data/config.json` and must exist there,
or be replaced by environment variables. `bin/zfeeder check-config` prints both
paths, on its `Config file` and `Data directory` lines.

zFeeder writes its own files with mode 0640 and its own directories with 0750
(`src/Storage/AtomicFile.php`), so the 2004 instruction to `chmod 0777` does not
apply and should not be followed.

### nginx

```bash
cp /var/www/zfeeder/deploy/nginx.conf.example /etc/nginx/sites-available/zfeeder
# edit the lines marked CHANGE: server_name, root, fastcgi_pass, ZF_URL
ln -s /etc/nginx/sites-available/zfeeder /etc/nginx/sites-enabled/zfeeder
nginx -t && systemctl reload nginx
```

[`deploy/nginx.conf.example`](../deploy/nginx.conf.example) sets the root to
`public/`, sends everything that is not a real file to `index.php`, executes
only `index.php` and returns 404 for every other `.php` path, denies dot files
and stray `.sqlite`, `.log`, `.opml` and `.md` files, and passes the `ZF_*`
configuration as `fastcgi_param`. That works because `ConfigLoader::realEnv()`
falls back to `$_SERVER` when `getenv()` has nothing. Setting the same values
with `env[...]` in the PHP-FPM pool, or in `data/config.json`, is equally valid.

### Apache

[`deploy/apache-vhost.conf`](../deploy/apache-vhost.conf) is written to be used
inside a `<VirtualHost>`. It grants access to `/var/www/html/public`, rewrites
to the front controller, denies `/var/www/data` outright, sets cache headers, and
blocks `composer.json`, `.env*`, `*.md`, `*.sqlite` and `*.log`.

Those two `<Directory>` paths are the container's. Copy the file next to your
installation and change them to `/var/www/zfeeder/public` and
`/var/www/zfeeder/data` before including it:

```apache
<VirtualHost *:443>
    ServerName feeds.example.com
    DocumentRoot /var/www/zfeeder/public

    SetEnv ZF_URL https://feeds.example.com/
    SetEnv ZF_ENV production
    # ZF_DATA_DIR is not set: the default, /var/www/zfeeder/data, is already
    # outside the document root and keeps config.json with the subscriptions.

    # your edited copy, with the two <Directory> paths changed
    Include /etc/apache2/conf-available/zfeeder-paths.conf

    SSLEngine on
    # SSLCertificateFile / SSLCertificateKeyFile, or let certbot fill these in
</VirtualHost>
```

`a2enmod rewrite headers expires` if they are not enabled. PHP settings worth
copying from [`deploy/php.ini`](../deploy/php.ini): `expose_php = Off`,
`display_errors = Off`, `allow_url_fopen = Off`, `allow_url_include = Off`,
`session.cookie_httponly = 1`, `session.cookie_samesite = "Lax"`. zFeeder never
opens a URL as a file; feeds go through the HTTP client, which enforces the
address rules.

### Verify

```bash
sudo -u www-data php /var/www/zfeeder/bin/zfeeder check-config
curl -fsS https://feeds.example.com/healthz
curl -sI https://feeds.example.com/admin | grep -i -E 'content-security-policy|strict-transport'
```

Run `check-config` as the web server user, not as root: it reports whether *that*
user can write the data directory.

### Upgrade

```bash
cd /var/www/zfeeder
git pull                                      # or unpack the new archive alongside
composer install --no-dev --optimize-autoloader
sudo -u www-data php bin/zfeeder check-config
systemctl reload php8.3-fpm                   # clears opcache
```

`opcache.validate_timestamps = 0` in `deploy/php.ini` means PHP will not notice
changed files on its own; reload PHP-FPM or Apache after every upgrade.

---

## Shared hosting, from the release archive

This is how zFeeder was installed in 2004, and it still works: download, unpack,
upload. The archive already contains `vendor/`, so the server needs no Composer,
no shell and no build step.

### Prerequisites

A hosting account with PHP 8.3, the extensions listed above, and either a
document root you can point at a subdirectory or `mod_rewrite` with `.htaccess`
support.

### Install

1. Download `zfeeder-2.0.0.zip` from the
   [releases page](https://github.com/andreibesleaga/zfeeder/releases) and check
   it against `SHA256SUMS` from the same release.
2. Unpack it. You get a `zfeeder-2.0.0/` directory containing `bin/`, `src/`,
   `public/`, `templates/`, `templates-admin/`, `data-dist/`, `vendor/`,
   `docs/`, `zfeeder.php` and a `deploy/` directory with
   `apache-vhost.conf`, `php.ini` and the `.htaccess` already copied into
   `public/.htaccess`.
3. Upload the whole directory **above** your web root, for example to
   `/home/youraccount/zfeeder`.
4. Create the data directory **inside the uploaded directory but above the web
   root**, which is the default layout and the one that needs no configuration:

   ```
   /home/youraccount/zfeeder/
       public/              <- this is the document root
       data/
           categories/      <- leave empty; `bin/zfeeder seed` fills it
           cache/           <- leave empty
           config.json      <- copy data-dist/config.json.dist here
   ```

   If you have shell access, `bin/zfeeder seed` loads the shipped example
   subscriptions into whichever backend is configured. With FTP only, copy
   `data-dist/categories/*.opml` into `data/categories/` by hand — which works
   because shared hosting uses the flat backend.

   `data/` is a sibling of `public/`, so nothing in it is reachable over HTTP,
   and `ConfigLoader` finds `config.json` where it always looks for it:
   `<project root>/data/config.json`. Setting `ZF_DATA_DIR` elsewhere moves the
   subscriptions and cache but **not** the configuration file, so on shared
   hosting it is usually more trouble than it is worth.

5. Point the site's document root at `/home/youraccount/zfeeder/public`.
   If your host will not let you move the document root, upload the contents of
   `public/` into `public_html/` and the rest one level above it, then set
   `RewriteBase` in `public_html/.htaccess`.
6. Configure it. Either edit `data/config.json`, using the JSON keys from
   [`docs/CONFIGURATION.md`](CONFIGURATION.md), or set the variables in
   `public/.htaccess`
   ([`deploy/shared-hosting.htaccess`](../deploy/shared-hosting.htaccess) is the
   source), where a commented block is waiting:

   ```apache
   <IfModule mod_env.c>
       SetEnv ZF_URL https://example.com/
       SetEnv ZF_ADMIN_PASSWORD_HASH "$argon2id$v=19$m=65536,t=4,p=1$..."
   </IfModule>
   ```

   `SetEnv` values land in `$_SERVER`, which is where `ConfigLoader` reads them
   from when `getenv()` has nothing. Some CGI wrappers strip them; if a setting
   made this way does not take effect, put it in `config.json` instead.
7. Generate the password hash. If you have SSH:

   ```bash
   php /home/youraccount/zfeeder/bin/zfeeder hash-password
   ```

   If you do not, generate it on any machine with PHP 8.3 — the hash is portable.

### Verify

Open the site. Then check that the data directory is not reachable: a request
for `/config.json`, `/categories/news.opml` or `/zfeeder.log` must return 404.
If any of them returns the file, stop and move the data directory. If you have
SSH, `php bin/zfeeder check-config` answers the same question and more.

### Upgrade

Upload the new archive next to the old one, point the document root at the new
`public/`, keep the same data directory, and delete the old copy once the new
one answers. Nothing in the archive is modified at runtime, so the two can
coexist.

### Building the archive yourself

```bash
./tools/build-dist.sh 2.0.0
```

It installs the production dependencies, stages `bin src public templates
templates-admin data-dist vendor docs zfeeder.php composer.json LICENSE
README.md CHANGELOG.md SECURITY.md`, copies `deploy/apache-vhost.conf`,
`deploy/php.ini` and `deploy/shared-hosting.htaccess` (as `public/.htaccess`),
normalises permissions to 0755/0644, writes `dist/zfeeder-2.0.0.zip`,
`dist/zfeeder-2.0.0.tar.gz` and `dist/SHA256SUMS`, then restores the development
dependencies.

---

## Keeping the feeds fresh

There are two modes, set by `ZF_REFRESH_MODE`:

| Mode | Behaviour |
|---|---|
| `online` (default) | a page request fetches any subscription whose cache has expired |
| `offline` | rendering only ever reads the cache; nothing is fetched until you refresh |

`offline` plus a cron entry is the right setup for a public site: no visitor
ever waits on a slow publisher, and a feed that is down never slows a page down.

### Cron

```cron
*/15 * * * * /usr/bin/php /var/www/zfeeder/bin/zfeeder refresh >> /var/log/zfeeder-refresh.log 2>&1
```

Run it as the web server user, so the files it writes stay readable by the web
server:

```cron
*/15 * * * * www-data /usr/bin/php /var/www/zfeeder/bin/zfeeder refresh
```

Per-feed refresh intervals still apply: a subscription with a 120 minute
interval is not fetched again by a run 15 minutes later — it is reported as
`not expired yet`. Add `--force` to fetch regardless.

Overlapping runs are safe. `refresh` takes an exclusive, non-blocking lock on
`refresh.lock` in the data directory; a second run finds the lock held, prints
`Another refresh is already running`, and exits 0.

Useful variations:

```bash
bin/zfeeder refresh --category=news      # one category
bin/zfeeder refresh --force              # ignore the cache TTL
bin/zfeeder refresh --json               # machine readable report
```

In a container:

```cron
*/15 * * * * docker exec zfeeder bin/zfeeder refresh
```

### Over HTTP, when you have no shell

Set `ZF_REFRESH_KEY` to a long random string and call:

```
GET /refresh?key=<the key>
```

It prints the same report the 1.6 offline refresh did. With no key configured
the endpoint answers 403 and refreshes nothing, so leaving `ZF_REFRESH_KEY`
unset is how you keep it closed. Use it from an external scheduler
(cron-job.org, a Render cron service, a Kubernetes CronJob) and always over
HTTPS — the key is in the query string.

---

## TLS and reverse proxies

Get a certificate however your platform does it: Railway, Fly and Render
terminate TLS for you; on a VPS use certbot. zFeeder itself sends
`Strict-Transport-Security: max-age=31536000; includeSubDomains` on every
response it believes is HTTPS.

### `ZF_TRUSTED_PROXIES`

Behind a load balancer the request reaches PHP over plain HTTP, and the only
evidence of TLS is the `X-Forwarded-Proto` header. `SecurityHeaders::isHttps()`
believes that header **only** when the deployment says a proxy is in front,
because trusting it unconditionally would let any client claim HTTPS and, with
it, a `Secure` cookie on a plaintext connection.

| Value | Meaning |
|---|---|
| empty (default) | `X-Forwarded-Proto` is ignored; only a real `https://` request counts |
| `*` | always believe the header — correct behind a platform load balancer such as Railway, Fly or Render, where nothing else can reach the container |
| `10.0.0.5,10.0.0.6` | believe it only when `REMOTE_ADDR` is exactly one of these |

The list is exact addresses, not CIDR ranges: `src/Http/SecurityHeaders.php`
compares `REMOTE_ADDR` with `in_array`. If your proxy's address is not stable,
use `*` and make sure the container cannot be reached except through the proxy.

**The symptom of getting this wrong**: the site works over HTTPS, but signing in
to `/admin` appears to do nothing, or you are signed out on the next click. The
session cookie was issued without the `Secure` flag, or `session_secure=auto`
resolved to "not HTTPS". Set `ZF_TRUSTED_PROXIES` and try again.

### Proxy headers to forward

Whatever sits in front should pass `Host`, `X-Forwarded-Proto` and
`X-Forwarded-For`. If generated links come out with the wrong host, set `ZF_URL`
to the public URL with a trailing slash instead of relying on detection;
`check-config` fails the `base_url ends with a slash` check when you forget it.

---

## Upgrading

The order is the same on every target.

1. Read [`CHANGELOG.md`](../CHANGELOG.md) for the version you are moving to, and
   [`VERSIONING.md`](../VERSIONING.md) for what a major, minor and patch change
   is allowed to break.
2. Back up the data directory. See [`docs/RUNBOOK.md`](RUNBOOK.md#backup).
3. Replace the code: pull the image, pull the repository, or upload the archive.
4. Run `bin/zfeeder check-config` as the web server user.
5. Load `/healthz` and confirm `"status": "ok"`.
6. Sign in to `/admin` once.

Within 2.x your subscriptions, templates and configuration keys carry over
untouched — that is the compatibility promise in `VERSIONING.md`. A SQLite
installation applies any new migrations on the first boot;
`bin/zfeeder migrate --ensure-schema` does it explicitly and is safe to run
every time, which is what the container entrypoint does.

---

## Troubleshooting

| Symptom | Likely cause | What to do |
|---|---|---|
| `zFeeder cannot start: its configuration is not valid.` in plain text, 500 | boot failed in `ConfigLoader`: an unknown key in `config.json`, a value out of range, or a data directory inside `public/` | the detail is in the PHP error log (`error_log` says `zfeeder boot failure: …`); then `bin/zfeeder check-config` |
| `Configuration error: …` from the CLI, exit 78 | same, on the command line, where the message is printed in full | fix the named key |
| `/healthz` returns 503 with `"status": "degraded"` | `data_writable` is false: the data directory does not exist, or the web server user cannot write it | `ls -ld` the directory; `chown` it to the web server user; `bin/zfeeder check-config` names the user it tested as |
| `/healthz` returns the "This zFeeder instance is paused" page | `ZF_DEMO_ENABLED` is false | set it to `true`; note the pause happens before routing, so every path is affected |
| `The administration panel has no password yet.`, 503 | `ZF_ADMIN_PASSWORD_HASH` and `admin_password_hash` are both empty | `bin/zfeeder hash-password`, then set the variable and restart |
| `The administration panel is switched off.`, 404 | `ZF_ADMIN_ENABLED` is false | set it to `true` |
| Panel refuses every save with a 403 demonstration page | `ZF_DEMO_MODE` is true | set it to `false` on a real installation |
| Sign-in succeeds then immediately signs out, over HTTPS | the proxy's `X-Forwarded-Proto` is not believed | set `ZF_TRUSTED_PROXIES`; see [TLS and reverse proxies](#tls-and-reverse-proxies) |
| `Too many sign-in attempts. Please wait N seconds`, 429 | the login rate limiter: `ZF_LOGIN_MAX_ATTEMPTS` (5) within `ZF_LOGIN_WINDOW` (900s), per address | wait it out, or raise the limits |
| Every page is the demo index and no feeds appear | the data directory was seeded but never refreshed, or `ZF_REFRESH_MODE=offline` with no cron | `bin/zfeeder refresh --force`, then `bin/zfeeder list-feeds` |
| A single feed is missing from the page | that fetch failed and there is no cached copy | `bin/zfeeder list-feeds` shows the last fetch per feed, and the log carries the reason. `ZF_DISPLAY_ERROR` exists in the schema but nothing reads it, so it will not put the reason on the page |
| PHP fatal: `could not find driver` | `ZF_STORAGE=sqlite` without `pdo_sqlite` | install the extension, or go back to `ZF_STORAGE=flat`; `check-config` reports it as a failed extension check |
| A request for `/config.json` or an `.opml` file returns the file | the data directory is inside the web root | move it and set `ZF_DATA_DIR`; the boot check only covers the configured paths, not a stray copy |
| A setting you put in `config.json` is ignored | the loader reads `<project root>/data/config.json`, which is not `$ZF_DATA_DIR/config.json` when the two differ | `bin/zfeeder check-config` prints the file it used on the `Config file` line |
| Panel settings reset after a redeploy | the panel wrote them to `<project root>/data/config.json` inside the container, not to the mounted volume | move those settings to `ZF_*` variables |
| Code changed but the site did not | `opcache.validate_timestamps = 0` | reload PHP-FPM or Apache, or restart the container |
| Container exits at once with an Apache bind error under Kubernetes | port 80 as a non-root user without `NET_BIND_SERVICE` | the capability is in [`deploy/k8s.yaml`](../deploy/k8s.yaml); add it to your own manifest |
| Data lost on every deploy on a platform | no volume or disk attached at `/var/www/data` | attach one; the entrypoint reseeds an empty directory, which is what makes the loss look like a reset |
| `Cannot open the refresh lock file` | the data directory is not writable by the user running cron | run cron as the web server user |
| The platform reports the deployment unhealthy and never routes traffic to it | Apache is listening on 80 while the platform assigned a different `PORT` | use `deploy/entrypoint.sh`, which rewrites `ports.conf` from `PORT`; it prints `zfeeder: listening on port N` on the first line of the log |
| Railway build fails with no Dockerfile found, or silently uses Nixpacks | `RAILWAY_DOCKERFILE_PATH` is not set | `railway variables --set RAILWAY_DOCKERFILE_PATH=deploy/Dockerfile` |
| Railway refuses the image | the Dockerfile declares `VOLUME` | remove it and attach a Railway volume at `/var/www/data` instead |
| Permission denied on `/var/www/data` on the first boot of a new volume | the container was run with a non-root user, so the entrypoint could not `chown` the freshly mounted volume | let the entrypoint start as root; Apache still drops to `www-data` for every worker |
| SQLite installation shows no categories after a first boot | the data directory was seeded by copying OPML files, which only the flat backend reads | `bin/zfeeder seed` |
| `zfeeder: more than one Apache MPM enabled` in the log | the base layer was rebuilt or patched with a second MPM | nothing — the entrypoint has already removed `mpm_event` and `mpm_worker` and kept `mpm_prefork` |

Anything not in the table: read the log. [`docs/OBSERVABILITY.md`](OBSERVABILITY.md)
says what is in it, and [`docs/RUNBOOK.md`](RUNBOOK.md) has the symptom table for
the running system.
