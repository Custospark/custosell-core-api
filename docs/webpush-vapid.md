# Web Push — VAPID keys (how-to)

Web Push uses **VAPID** (Voluntary Application Server Identification) so push services trust that notifications really come from this server. You need one public/private key pair, set once, then reused.

## Where the keys are read from

- **Config:** `config/webpush.php` reads `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `WEB_PUSH_ENABLED`, `VAPID_SUBJECT`, `WEB_PUSH_ICON`, `WEB_PUSH_ROUTE` from the environment.
- **Env files** (all git-ignored): `.env`, `.env.local`, `.env.staging`, `.env.production`. The public key is served to the frontend by `GET /api/v1/webpush/status`; the private key never leaves the server.

> `.env.example` is the committed template — keep its `VAPID_*` values **blank** on purpose so secrets never land in git.

## Generate the keys (once)

```bash
cd Backend
composer require minishlink/web-push   # already present

php -r "require 'vendor/autoload.php';
echo json_encode(Minishlink\WebPush\VAPID::createVapidKeys(), JSON_PRETTY_PRINT);"
```

Output shape:

```json
{
  "publicKey": "B... base64url ...",
  "privateKey": "d... base64url ..."
}
```

**Windows note:** key *generation* needs OpenSSL + a reachable `openssl.cnf`. If it throws `Unable to create the key`, point OpenSSL at your config file:

```bash
OPENSSL_CONF="C:/xampp/apache/conf/openssl.cnf" php -r "require 'vendor/autoload.php'; echo json_encode(Minishlink\WebPush\VAPID::createVapidKeys(), JSON_PRETTY_PRINT);"
```

Runtime *signing* does **not** need that workaround (it is bcmath-based ES256).

## Fill the keys in

Put the same pair into every real env file (or a distinct pair per environment for isolation):

```bash
cd Backend
for f in .env .env.local .env.staging .env.production; do
  sed -i "s|^VAPID_PUBLIC_KEY=.*|VAPID_PUBLIC_KEY=<PUBLIC>|; s|^VAPID_PRIVATE_KEY=.*|VAPID_PRIVATE_KEY=<PRIVATE>|" "$f"
done
```

Then reload cached config:

```bash
php artisan config:clear
```

## Verify

```bash
php artisan tinker --execute="
echo 'enabled='.(config('webpush.enabled')?'yes':'no').PHP_EOL;
echo 'pub='.config('webpush.public_key').PHP_EOL;
echo 'priv_len='.strlen((string)config('webpush.private_key')).PHP_EOL;
"
```

Expect `enabled=yes`, a non-empty `pub`, and `priv_len=43`.

End-to-end check: open the app → **Notifications** → toggle **Desktop notifications** → `GET /webpush/status` returns your `public_key`, and the browser subscribes.

## Rotating / regenerating

Keys can be regenerated anytime. The browser reads the new public key from `/webpush/status` the next time it subscribes existing subscriptions signed under the old key continue to work; regenerating lets you revoke a leaked key.

## Frontend contract

The frontend does not hardcode VAPID — it fetches it from `GET /api/v1/webpush/status` and calls `pushManager.subscribe({ userVisibleOnly: true, applicationServerKey })`. See `Frontend/docs/adr/2026-08-05-web-push-notifications.md` for the end-to-end design.