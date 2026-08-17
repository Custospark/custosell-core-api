# Custosell — Deployment Runbook & Safety Guardrails

**OWNERSHIP:** Every agent that touches production (`custosell.com`,
`api.custosell.com`) or staging (`staging.custosell.com`,
`staging-api.custosell.com`) **MUST read this file in full before writing any
deployment plan or executing any deploy.**

**Golden rule: There is no room for error in production.**
If anything in this file is unclear — stop and ask Oscar (the human). Never
guess, never assume, never "just try it" on a live environment.

---

## 1. Environments & topology

| Environment | Frontend (web) | Backend API | Purpose |
|---|---|---|---|
| Local dev | `localhost:5173` | `localhost:8000` | Development, tests |
| **Staging** | `https://staging.custosell.com` | `https://staging-api.custosell.com/api/v1` | Pre-prod validation |
| **Production** | `https://custosell.com` | `https://api.custosell.com/api/v1` | Live customers |

**Hosting (Hostinger shared cPanel VPS):**
- Host: `147.79.103.136`, SSH port `65002`, user `u214605677`
- Backend code dirs:
  - Staging: `/home/u214605677/domains/staging-api.custosell.com`
  - Production: `/home/u214605677/domains/api.custosell.com`
- Frontend web docroot (under the `custosell.com` domain):
  - Staging: `/home/u214605677/domains/custosell.com/public_html/staging`
  - Production: `/home/u214605677/domains/custosell.com/public_html`
- The frontend web build is **shipped through the backend repo** at
  `Backend/public/staging` and `Backend/public/production`, then copied into the
  web docroot on the server. The backend `public/` folder is the single source
  of truth for deployed web builds.
- Credentials for the server live in `Backend/.env` under `SERVER_*` and are
  read by `Backend/scripts/ssh_run.py` (never typed, never printed).

**Separate databases per environment** (staging must never share prod data):
- Staging DB: `u214605677_CustosellStage`
- Production DB: `u214605677_Custosell` (verify exact name in the server `.env`)

---

## 2. Non-negotiables (violating ANY of these is a P0 incident)

1. **NEVER run `migrate:fresh`, `migrate:refresh`, `db:wipe`, or `drop database`
   on any environment.** We never wipe. We only ever **add** (migrations are
   additive and forward-only).
2. **NEVER run `key:generate` on staging or production** — it invalidates every
   session, token, and encrypted cookie, logging out all users and breaking
   signed URLs.
3. **NEVER delete or edit an existing migration.** Migrations are historical.
   To change schema, add a **new** forward migration.
4. **NEVER `git add -A` / force-push on the shared backend repo** during a
   deploy — only the exact paths that belong to the deploy may be committed
   (see §5). Force-push history rewrites are how the server previously diverged.
5. **NEVER run `php artisan migrate` without `--force` in production/staging** —
   non-interactive environments hang or abort without it.
6. **NEVER expose or print secrets** (passwords, tokens, private keys, `.env`
   contents). Read them from env files via scripts; mask them in output.
7. **NEVER deploy from a dirty working tree** locally or on the server. Verify
   `git status --short` is clean (except expected gitignored files) before/after.
8. **NEVER wipe anything on the server before creating a rollback backup** (§6).

---

## 3. Mandatory deployment plan (the approval gate)

> **Every deploy — no matter how small — requires a written deployment plan that
> is approved by Oscar BEFORE any command runs on a shared environment.**

The plan MUST be produced as a todo list (via the todo tool) and contain:

- **Scope:** exact commits / hashes (backend + frontend), and what changed
  (one line per meaningful change).
- **Files touched** and which are committed to which repo.
- **Migrations:** list of new migrations that will run, with `--force`, and the
  explicit statement "no destructive migration present".
- **Backup step:** where the pre-deploy backup will be written (timestamped).
- **Rollback plan:** the exact commands to restore the previous state if the
  deploy goes wrong.
- **Verification plan:** health checks that prove success (HTTP status, correct
  MIME types, asset counts, scheduler list, no error logs).
- **Owner approval:** the plan is presented to Oscar and explicitly approved
  before Step 1 executes.

**Never "deploy and see". Always "plan → approve → execute → verify → report".**

---

## 4. Standard backend deploy (staging or production)

### 4.1 Pre-flight (local)
```bash
cd Backend
composer vera:fast            # php -l on changed files + logic gates
php artisan test --filter=<changed area>   # targeted tests, never full suite in a loop
git status --short            # must be clean
```
- If the change is user-facing / changes an API contract, the plan must note
  the FE/BE sync (both stacks reviewed).

### 4.2 Push (local)
```bash
git add <exact paths>         # NOT git add -A
git commit -m "<descriptive message>"
git push origin main
```

### 4.3 Deploy to server (one environment at a time)
```bash
cd /home/u214605677/domains/<staging-api|api>.custosell.com
git fetch origin
git reset --hard origin/main    # bulletproof mirror; never local commits
# OR: git config pull.ff only && git pull origin main

# Order of operations AFTER the pull, and ONLY if migrations changed:
/usr/bin/php artisan migrate --force

# Always refresh caches so the new code/config takes effect:
/usr/bin/php artisan optimize:clear
/usr/bin/php artisan config:clear
/usr/bin/php artisan route:clear
```
> `git reset --hard` is safe for config: `.env` is gitignored and preserved.

### 4.4 Post-deploy verification (both environments)
```bash
/usr/bin/php artisan about | grep -iE 'Environment|Laravel Version|PHP'
/usr/bin/php artisan schedule:list        # confirm scheduled commands present
curl -s -o /dev/null -w '%{http_code}\n' https://<staging-api|api>.custosell.com/
# API smoke test: hit a public/authenticated endpoint and confirm a sane JSON body
```

---

## 5. Standard frontend (web) deploy

The frontend web build is shipped via the **backend repo**, then copied to the
web docroot.

### 5.1 Build & push (local, frontend repo)
```bash
cd Frontend
npm run deploy:web:staging     # OR deploy:web:production
```
This script (`Frontend/scripts/deploy-to-backend.mjs`):
1. typechecks (`tsc -b`) and builds with `--mode staging` (or production),
   using a **relative** asset base (`./`).
2. copies `dist/web` → `Backend/public/<target>`.
3. copies `Frontend/deploy/htaccess.staging` into the build folder as
   `.htaccess` (auto-shipped with every build — never manually re-added).
4. commits **only** `public/<target>` in the backend repo and pushes.

**Version tag:** the build is labeled with the frontend `package.json`
`version` field (e.g. `v5.2.0`) and committed to the backend repo as
`deploy(web): <target> build v<version> under public/<target>`. The deploy
commit on the backend repo IS the release record — bump `package.json`
`version` before deploying a new release, and record that commit hash in the
deploy report so it is restorable/auditable.

> The frontend `dist/` is gitignored; only the backend repo carries builds.
> If the backend push fails (large build → HTTP 408), increase the git buffer:
> `git config http.postBuffer 524288000` then `git push origin HEAD`.
> The script never `git add -A` — it stages only the build path, so unrelated
> backend/frontend source changes can never be swept into a deploy commit.

### 5.2 Server: pull + copy into the web docroot
```bash
# On the server, backend app dir:
cd /home/u214605677/domains/<staging-api|api>.custosell.com
git fetch origin
git reset --hard origin/main

# Copy the build to the web docroot. MUST use cp -rT (bash * silently skips
# dotfiles like .htaccess - the #1 cause of broken SPA deploys).
cd /home/u214605677/domains/custosell.com/public_html
rm -rf <staging|production>   # AFTER the backup in §6 exists
mkdir -p <staging|production>
cp -rT /home/u214605677/domains/<staging-api|api>.custosell.com/public/<target> <target>
```
> `cp -rT SRC DST` copies the **contents** of `SRC` (including dotfiles) into
> `DST`. `cp -rT .../* ...` would drop `.htaccess`.
> Verify the server working tree mirrors `origin/main` (`git status --short`
> clean + asset count) BEFORE wiping the docroot — a half-applied pull is the
> historical cause of missing-JS / `text/html` MIME incidents.

### 5.3 Frontend post-deploy verification (critical — this is what caught the
last incident)
```bash
cd /home/u214605677/domains/custosell.com/public_html/<target>

# 1. Asset count matches the build (both must be equal):
ls assets | wc -l

# 2. Every JS asset referenced by index.html EXISTS on disk:
grep -oE 'assets/[a-zA-Z0-9_/-]+\.js' index.html | sort -u \
  | while read f; do [ -f "$f" ] || echo "MISSING: $f"; done
#   → MUST output zero missing lines.

# 3. Served JS must be a JS MIME type, NEVER text/html:
curl -s -o /dev/null -w '%{http_code} %{content_type}\n' \
  https://<staging|custosell>.custosell.com/assets/<any-referenced-js>
#   → text/html means SPA rewrite is serving index.html for a MISSING file.

# 4. SPA deep route still rewrites to index.html:
curl -s -o /dev/null -w '%{http_code}\n' https://<staging|custosell>.custosell.com/<any-route>

# 5. The built API base points at the right environment (no cross-env leak):
grep -rloE 'https://(api|custosell-api)[^"'"'"']*' assets/ | head
```
**If any asset is missing or MIME is `text/html` — STOP. Do NOT tell the user
it's deployed. The folder is inconsistent; fix the checkout/copy and re-verify.**

---

## 6. Backup & rollback (mandatory before ANY wipe)

### 6.1 Pre-deploy backup (every time)
```bash
cd /home/u214605677/domains/custosell.com/public_html
cp -rT <target> <target>-backup-$(date +%Y%m%d-%H%M%S)
# Verify the backup exists and is non-empty (count files) before wiping.
```
> For database-affecting deploys, also take a DB backup through the host's
> tooling (or `mysqldump` with host-approved credentials) **before** migrating.

### 6.2 Rollback
```bash
# Frontend rollback (point the docroot back at the backup):
cd /home/u214605677/domains/custosell.com/public_html
rm -rf <target> && cp -rT <target>-backup-<timestamp> <target>
# then re-run §5.3 verification.

# Backend code rollback (mirror the previous known-good commit):
cd /home/u214605677/domains/<staging-api|api>.custosell.com
git fetch origin
git checkout <previous-known-good-commit> -- .
# Or reset to a prior tag/commit, then re-run migrations forward-only if needed.
```
> Rollback must be rehearsed as part of the plan, not invented under pressure.

---

## 7. Scheduler / cron guardrails

- The Laravel scheduler runs via one Hostinger cron firing
  `artisan schedule:run` **every minute**. Do NOT create per-task crons.
- Hostinger's PHP binary is `/usr/bin/php` (also `/opt/alt/php82/usr/bin/php`).
  The cron command is:
  ```
  /usr/bin/php /home/u214605677/domains/<staging-api|api>.custosell.com/artisan schedule:run
  ```
  with minute/hour/day/month/weekday all set to `*`.
- After a deploy that touches `routes/console.php`, run `schedule:list` and
  confirm the new command is registered and no command is missing.
- `crontab` is NOT available on this shared host — cron is managed in hPanel.
- Never run schedule-affecting tasks manually in a loop on production.

---

## 8. Guardrails from established enterprises (Google / Meta / Microsoft SRE)

Beyond the specific Custosell rules above, apply these industry-standard
practices on every deploy:

### 8.1 Change management & approvals
- **Two-person rule / approval gate:** no shared-environment change ships
  without explicit human (Oscar) sign-off. (Google SRE: change management
  principle.)
- **Change windows:** prefer low-traffic windows for production; batch related
  changes. Avoid deploying at busy hours or before an extended absence.
- **Single deploy owner:** one agent owns the deploy end-to-end; no parallel
  agents mutating the same server/repo during a deploy.

### 8.2 Gradual / reversible release
- **Canary / incremental roll-out where possible:** stage first, validate, then
  prod. Never jump straight to prod from an untested build.
- **Feature flags** for risky new behavior so it can be disabled without a
  redeploy.
- **Reversible steps:** every step has an inverse. If a step has no clean undo,
  it must have a backup + tested restore before running.

### 8.3 Configuration & secrets
- **Never put secrets in code or git.** Read from `.env` (gitignored) via
  scripts; mask in all output. (Microsoft / Google secret-management practice.)
- **Environment separation:** config values (API URLs, keys) must be verified
  to belong to the target environment after build — a staging build that leaks
  the production API URL is a release-blocking defect.

### 8.4 Observability & verification
- **Verify AFTER, not just before:** health checks, HTTP 200, correct MIME,
  scheduler list, and error-log sweep (tail Laravel `storage/logs/laravel.log`
  for new exceptions post-deploy).
- **Golden signals:** watch for errors/5xx, latency, and user-facing breakage
  for a short soak period after go-live. Log the deploy timestamp/commit for
  correlation.
- **Make failures loud:** a failed verification aborts the release. No "it's
  probably fine".

### 8.5 Immutability & reproducibility
- **Immutable builds:** the artifact on the server is exactly what was built
  and committed — never patch files by hand on the server.
- **Reproducible deploys:** same commit → same result. No server-side edits.
- **Tag releases** (e.g. `git tag release-2026-08-16-v5.2.0`) at the deploy
  commit so any commit is restorable and auditable.

### 8.6 Testing & validation
- **Test before deploy:** typecheck, lint (vera:fast), and targeted unit/feature
  tests must be green locally. (Google: tests before release.)
- **Staging == production parity:** staging should run the same code paths,
  same migration style, same cron, so staging validation is meaningful.
- **Smoke tests after deploy:** login flow, one core read + one core write on
  each environment.

### 8.7 Incident posture
- **If verification fails, roll back — do not "fix forward" blindly.**
- **Preserve evidence:** capture the failing output before changing anything.
- **No silent recovery:** report the failure, root cause, and recovery to Oscar
  in full.

---

## 9. The deployment plan template (copy into the todo list)

```
DEPLOYMENT PLAN — <staging|production> — <YYYY-MM-DD>

SCOPE
- Backend: commit(s) <hash> → <hash>   [what changed]
- Frontend: commit(s) <hash> → <hash>  [what changed]
- Repos touched: Backend/custosell-core-api, Frontend/custosell-web-desktop

MIGRATIONS
- New migrations to run (with --force): <list>
- Confirmation: NO destructive migration (no drop/refresh/fresh).

BACKUP
- Frontend docroot backup: <path> (created + verified before wipe)
- DB backup (if migration affects data): <method>

ROLLBACK
- Frontend: <exact restore command>
- Backend: <exact restore command>

STEPS
1. <pre-flight checks>
2. <build + commit + push>
3. <server pull / reset>
4. <migrate --force if needed>
5. <cache clears>
6. <frontend backup>
7. <wipe + cp -rT>
8. <verification per §5.3>

VERIFY (all must pass)
- [ ] HTTP 200 on <target URL>
- [ ] JS MIME = javascript (not text/html)
- [ ] 0 missing assets referenced by index.html
- [ ] scheduler list shows all commands
- [ ] no new errors in storage/logs/laravel.log

APPROVAL
- Presented to Oscar: [ ] APPROVED   Date/time: ______
```

---

## 10. Checklists (quick reference)

### Pre-deploy
- [ ] Read this runbook fully
- [ ] Deployment plan written as todos + presented for approval
- [ ] Local: vera:fast pass, targeted tests pass, `tsc -b` clean (frontend)
- [ ] Local working tree clean
- [ ] No destructive migration in the diff
- [ ] Backup plan defined (frontend docroot + DB if applicable)

### During
- [ ] Only exact paths staged (never `git add -A` on the backend repo)
- [ ] `git fetch origin && git reset --hard origin/main` (server mirror)
- [ ] `migrate --force` only when migrations changed
- [ ] `cp -rT` for the frontend copy (never `*`)
- [ ] No secret printed in any output

### Post-deploy
- [ ] §5.3 frontend verification (asset count, missing refs, MIME, deep route)
- [ ] API smoke test
- [ ] scheduler `schedule:list` confirms all commands
- [ ] error-log sweep clean
- [ ] Report to Oscar: what shipped, verified results, backup path, rollback cmd

---

## 11. Known server facts & gotchas (do not rediscover)

- SSH: `ssh -p 65002 u214605677@147.79.103.136` (password via env, never
  typed into chat).
- `crontab` is **not installed** on the host — cron lives in hPanel.
- PHP binary `/usr/bin/php` = PHP 8.2.32 (verified).
- A half-applied `git pull` (deleted files, no new files) produces missing JS →
  `text/html` MIME errors. Always verify the working tree matches
  `origin/main` (`git status --short` clean + asset count) before copying.
- `git push` of large builds can 408 — raise `http.postBuffer`.
- Server `.env` is gitignored and preserved across `git reset --hard`.
