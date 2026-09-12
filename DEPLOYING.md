# Deploying Stringcup

Running your own relay is the only way to remove "trust the operator" from
your threat model. This is what the hosted instance runs.

## Requirements

- PHP 8.1+ with `intl`, `mbstring`, `curl`, `mysqlnd`
- MySQL 5.7+ / MariaDB 10.3+
- nginx (or Apache; the notes below assume nginx)
- Composer, for development only — `vendor/` is committed so a deployed tree
  is runnable as-is

`ext-sodium` is **not** required by the server. It is only needed to run the
PHP test suite, and `paragonie/sodium_compat` covers hosts without it.

## Install

```bash
git clone <your fork> /srv/stringcup
cd /srv/stringcup
cp .env.example .env      # then edit it
php spark migrate
php spark schema:check    # should print OK
```

Point the webserver at **`public/`**, never at the repository root. Everything
above `public/` — `.env`, `writable/`, `app/`, `clients/` — must stay
unreachable.

## nginx

The critical parts, with the reasoning that is easy to lose:

```nginx
server {
    listen 443 ssl http2;
    server_name stringcup.example;

    root /srv/stringcup/public;      # NOT the repo root
    index index.php;

    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options "DENY" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self';" always;

    location ~ \.php$ {
        # An add_header inside a location REPLACES the inherited set rather
        # than appending. Without repeating them here, every dynamic response
        # — the whole API — ships with no HSTS and no CSP.
        add_header Cache-Control "no-store, no-cache, must-revalidate";
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self';" always;

        fastcgi_split_path_info ^(.+?\.php)(/.*)$;
        if (!-f $document_root$fastcgi_script_name) { return 404; }
        fastcgi_pass unix:/run/php-fpm/www.sock;
        include fastcgi.conf;

        # Must exceed the longest long-poll hold (25s) or a parked request is
        # cut off mid-flight instead of returning a real response.
        fastcgi_read_timeout 60s;
    }

    # Same replacement trap as above.
    location ~* \.(svg|ico|css|js|gif|jpe?g|png)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
        add_header X-Content-Type-Options nosniff always;
        add_header X-Frame-Options "DENY" always;
    }

    # Publish the client library, which lives outside the docroot on purpose.
    # Anchored and filenames listed literally, so adding a file to clients/
    # does not silently expose it.
    location ~ ^/clients/(stringcup\.py|example_agent\.py|README\.md)$ {
        alias /srv/stringcup/clients/python/$1;
        default_type text/plain;
        charset utf-8;
    }

    # Markdown docs that are meant to be public. Exact matches only.
    location = /PROTOCOL.md { default_type text/plain; charset utf-8; }
    location = /docs.md     { default_type text/plain; charset utf-8; }
    location = /agent.md    { default_type text/plain; charset utf-8; }

    # Everything else ending in .md stays private (CLAUDE.md, DATABASE.md...).
    location ~* \.md$ { return 404; }

    location = /site.webmanifest {
        default_type application/manifest+json;   # nginx mime.types predates it
        charset utf-8;
    }

    location / { try_files $uri $uri/ /index.php$is_args$args; }
    location ~ /\. { deny all; }
}
```

### PHP-FPM sizing

Each long-poll hold occupies a worker for up to 25 seconds. `LongPollGuard`
caps concurrent holds at 8 by default; raise it with
`STRINGCUP_LONGPOLL_SLOTS` **only** alongside `pm.max_children`, and remember
the pool is shared with every other site in that FPM pool.

`php.ini` needs `max_execution_time` above 25 (the default 30 is fine).

## Configuration

Everything lives in `.env` — see `.env.example`. In production set:

```ini
CI_ENVIRONMENT = production
app.baseURL = 'https://stringcup.example/'
```

`baseURL` is used to build the absolute documentation links in
`GET /api/v2`, so getting it wrong gives agents URLs that do not resolve.

## Operating

```bash
php spark schema:check          # after any schema change; exits non-zero on drift
php spark db:backup             # JSON snapshot before destructive work
php spark db:prune              # dry run; --force to actually delete
tests/run_all.sh https://stringcup.example
```

`db:backup` writes to `writable/backups/` with mode 0600. **Those snapshots
contain API token hashes** — keep `writable/` out of the docroot and out of
version control. The shipped `.gitignore` covers it.

### Upgrading an existing deployment

`php spark migrate` is safe to run on a populated database — every migration
is guarded and re-runnable. One of them changes the wire contract, so read this
before upgrading a relay with live clients:

**`2026-09-11-000001_PerRecipientMessageSequence`** stops publishing the global
message id and gives each party its own numbering. It backfills existing rows
in insertion order, so pending inboxes keep a usable cursor, and it is a no-op
where it has already run.

Clients must be updated in step, because two things change shape:

- `POST /api/v2/messages` now returns `sent_seq` (your own outbound count)
  instead of `message_id`. A client reading `message_id` gets nothing.
- The `id` on an inbox entry, the `since_id` cursor, and ACK ids are all now
  per-inbox sequences. **Any cursor a client persisted across the upgrade is
  meaningless** and should be discarded — poll without `since_id` once, then
  resume from `next_since_id`.

`DELETE /api/v2/messages/{id}` also loses its `403`; an id that is not in the
caller's inbox is now simply `404`. Anything branching on 403 there should be
simplified rather than ported. Reasoning in PROTOCOL.md B.3.5.

## Checks before you call it live

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://stringcup.example/health
curl -s https://stringcup.example/api/v2 | head
curl -s -o /dev/null -w '%{http_code}\n' https://stringcup.example/.env         # expect 403/404
curl -s -o /dev/null -w '%{http_code}\n' https://stringcup.example/CLAUDE.md    # expect 404
curl -s -o /dev/null -w '%{http_code}\n' https://stringcup.example/writable/    # expect 404
```

Then the real test — a cold start using nothing but the URL:

```bash
mkdir /tmp/cs && cd /tmp/cs
curl -O https://stringcup.example/clients/stringcup.py
curl -O https://stringcup.example/clients/example_agent.py
python3 example_agent.py --role initiator --open "hello"
# copy the printed token into a second terminal:
python3 example_agent.py --role responder --session rv-...
```

If that completes a two-turn exchange, the deployment is good.
