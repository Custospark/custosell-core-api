# Custosell Backend — Server Deployment Runbook

Operational notes for deploying `api.custosell.com` (production) and
`staging-api.custosell.com` (staging) from the shared backend repo on
`147.79.103.136` (`fr-int-web1787`, cPanel VPS).

## The problem we fixed (2026-08-14)

After the backend `main` history was force-pushed (to remove two bad commits),
the server's local branch still held the old commits, so it **diverged** from
`origin/main`. `git pull origin main` then failed with:

```
hint: You have divergent branches and need to specify how to reconcile them.
fatal: Need to specify how to reconcile divergent branches.
```

## Permanent fix (run once per server, in order)

```bash
# 1. Go to the app folder (production: api.custosell.com)
cd ~/domains/staging-api.custosell.com

# 2. Realign the local branch to match GitHub exactly (one-time)
git fetch origin
git reset --hard origin/main

# 3. Make future pulls safe — fast-forward only, never a merge
git config pull.ff only
```

## Standard deploy (every time after the above)

Just pull — fast-forward only, and git refuses loudly if the server ever drifts
again instead of silently creating a messy merge.

```bash
cd ~/domains/staging-api.custosell.com
git pull origin main
```

### Most robust alternative (recommended for deploys)

For a deploy target the bulletproof command is to mirror upstream directly —
it never fails on divergence and guarantees the server matches `origin/main`:

```bash
git fetch origin && git reset --hard origin/main
```

## Why the server is a pure mirror

- The server should **never** hold local commits — it exists to run what's on
  GitHub.
- `git reset --hard origin/main` discards stale local commits (safe: `.env` is
  gitignored, so server config is preserved).
- `git config pull.ff only` makes future `git pull` fast-forward-only, so
  divergence surfaces as a loud error instead of a silent merge.

## Frontend build deploys (alternative to file-server upload)

The frontend web build can be shipped through this same backend repo — the
build output is committed under `public/staging` / `public/production` by the
frontend deploy script, then picked up by the same `git pull` above.

- Frontend (local): `npm run build:web:staging` / `build:web:production`
  (build → copy → commit → push — only the build folder is committed)
- Server: the `git pull` above pulls the new build under `/staging` or
  `/production`.
- See Frontend `scripts/deploy-to-backend.mjs`.

## Order of operations after a pull (new code + frontend build)

```bash
cd ~/domains/staging-api.custosell.com
git pull origin main

php artisan optimize:clear
php artisan migrate --force        # only when migrations changed
```

> ⚠️ Never run `php artisan key:generate` in production — it wipes all sessions
> and encrypted cookies, logging every user out.
