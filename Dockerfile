# scout — the image every domain runs from (compose sets the domain in each ENTRYPOINT); the deployment ruled by Q8: Docker on a VPS, `state/` on a mounted volume,
# `scout run --watch` owning its own schedule rather than cron.
#
# GitHub Actions is ruled OUT for running this, explicitly and for a concrete reason: no persistent
# disk means no seen-set, which means re-notifying the entire market on every run. The seen-set is
# not a cache — losing it is a user-visible failure.
#
# Two properties of this project shape the whole file:
#
#   1. **Zero Composer dependencies.** `vendor/` holds only a generated PSR-4 autoloader (~56 KB).
#      That is not minimalism for its own sake: the container this was built in 403s Composer's dist
#      source, and installing PHPUnit from source produced a 2.6 GB `vendor/`. So there is no
#      `composer install` here — `dump-autoload` generates the map offline and needs no network.
#   2. **`vendor/` is gitignored**, so the image MUST generate it. Copying a host `vendor/` in would
#      make the image depend on whatever the developer last ran locally.
#
FROM php:8.5-cli AS build

# Composer is needed only to write the autoload map, so it never reaches the runtime image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./
COPY src/ ./src/

# `--no-dev` on purpose: the runtime image has no test suite in it, so the `Scout\Tests\`
# PSR-4 entry would map a namespace whose files are absent. (Locally the opposite is true and
# omitting `--dev` silently breaks the corpus suite — see CLAUDE.md § Gotchas.)
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

# ── runtime ───────────────────────────────────────────────────────────────────────────────────────
FROM php:8.5-cli

# The extensions the code actually uses, verified against the source rather than copied from
# `composer.json` — which understated them until 2026-08-22:
#
#   curl        CurlHttpClient
#   dom         HtmlSource, via PHP 8.4+'s `Dom\HTMLDocument`
#   pdo_sqlite  the store
#   openssl     `tls://` stream sockets — BOTH the hand-rolled IMAP client and SmtpTransport
#   mbstring    Text folding, throughout the classifier
#   pcntl       clean shutdown
#
# **`php:8.5-cli` already ships every one of them except `pcntl`** (checked with `php -m` against the
# image, not assumed), so only that one is built here. The first version of this file installed the
# lot, and the build FAILED: `docker-php-ext-install dom` recompiles the extension, and PHP 8.4+'s
# DOM parses HTML5 through **lexbor**, whose headers are not in the image — `fatal error:
# lexbor/html/parser.h: No such file or directory`. Reinstalling an extension the base image already
# provides is not a harmless belt-and-braces; here it replaced a working DOM with a broken build.
#
# NOT ext-imap: PHP unbundled it in 8.4, and `ImapMailbox` is hand-rolled on stream sockets for
# exactly that reason. Adding it would be cargo cult.
#
# `pcntl` needs no external library. WatchLoop guards its absence, but a deployed watcher that
# cannot finish the pass in flight on SIGTERM produces duplicate notifications on every redeploy —
# so it belongs in the image even though the code survives without it.
RUN docker-php-ext-install -j"$(nproc)" pcntl

# Q34: a container without TZ runs UTC, so the 08:00 digest silently becomes 10:00 Paris in summer.
# `bin/scout` reads this; the default here matches `.env.example` and compose can override it.
ENV TZ=Europe/Paris

WORKDIR /app

COPY --from=build /app/vendor/ ./vendor/
COPY src/ ./src/
COPY bin/ ./bin/
COPY config/ ./config/
COPY composer.json ./

# The fixture source is SHIPPED, deliberately — and it is `enabled: false` since 2026-08-22, which
# changes why rather than whether. It is ~4 KB, needs no network and no credentials, and it means a
# fresh VPS can prove the whole pipeline (fetch → map → classify → criteria → dedup → store →
# notify) before a single landlord is polled: `scout --domain=rent doctor --source=fixture_demo`, which force-runs
# a disabled source. Omit the file and that command — the first one README hands a new operator —
# fails on a box where nothing else is wrong.
COPY tests/fixtures/rent/fixture_demo/ ./tests/fixtures/rent/fixture_demo/

# `state/` is the MOUNTED VOLUME (Q8): the seen-set, the price history, the run log, the Q27
# heartbeat marker and `rent-last-refusal.txt`. Created here so the image runs even unmounted — but see
# Q36: an empty seen-set makes `run` REFUSE rather than notify the entire back catalogue, which is
# precisely what a forgotten volume looks like.
RUN mkdir -p /app/state

# Non-root. Nothing here needs privilege, and the one writable path is the volume.
RUN useradd --system --create-home --uid 10001 scout \
    && chown -R 10001:10001 /app
# Numeric, not the name: a host that maps the container's filesystem (a bind-mounted `state/`, which
# is exactly what Q8 prescribes) resolves a numeric id without needing the name to exist there.
USER 10001:10001

# No HEALTHCHECK, deliberately. Q27's heartbeat IS the liveness mechanism and it reports through a
# notification channel the operator actually reads; a container healthcheck polling the marker file
# would fight the 24 h interval and report to a dashboard nobody is watching. One guarantee, one
# implementation.

ENTRYPOINT ["php", "/app/bin/scout"]
CMD ["help"]
