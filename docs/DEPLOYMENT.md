# X2KEY — deployment from zero to fully working

This guide takes X2KEY from nothing to a live, fully working marketplace on **one Ubuntu 24.04 server**. That is the
simplest setup that runs every feature (shop, payments, live chat, background jobs, search, backups). When traffic
grows, the same steps work with a managed database and Redis: only the addresses in `.env` change.

Follow the parts in order. Each step says **what to do** and **how to check it worked**. Commands in `code blocks`
are typed on the server unless the step says otherwise. Replace `x2key.com` with your real domain everywhere.

Day-to-day operations after launch (alerts, incidents, restores) are in [RUNBOOK.md](RUNBOOK.md).

---

## Part 0 — Things to have before you start

| You need | Why | Where |
|---|---|---|
| A domain name | the site address | any registrar (Namecheap, Cloudflare, OVH…) |
| A server: Ubuntu 24.04, **4 vCPU, 8 GB RAM, 80 GB SSD** minimum | runs everything | Hetzner, DigitalOcean, OVH, AWS Lightsail… |
| A second storage provider (S3-compatible bucket) | off-site backups | Backblaze B2, Wasabi, AWS S3 — **not** the same company as the server |
| An e-mail sending service | sign-up codes, order e-mails | Resend, Postmark, Amazon SES, Mailgun |
| A Stripe account (live mode activated) | card payments | dashboard.stripe.com |
| A NOWPayments account (optional) | crypto payments | nowpayments.io |
| Access to the GitHub repository | to download the code | your GitHub account |
| An uptime monitor account (free tier is fine) | warns you when the site is down | UptimeRobot, Better Stack |
| A password manager | to keep every key safe | 1Password, Bitwarden |

> **Security rule for the whole guide:** every key, password and secret goes **only** into the server's `.env` file
> and your password manager. Never paste them in chats, tickets, e-mails or the git repository. If one leaks,
> regenerate it at the provider.

---

## Part 1 — Domain and server

### 1.1 Create the server
Create the Ubuntu 24.04 server at your hosting provider and add **your SSH key** during creation (no password login).
Note its public IP address.

### 1.2 Point the domain at the server
At your registrar (or Cloudflare DNS), create:

| Type | Name | Value |
|---|---|---|
| A | `@` | server IP |
| A | `www` | server IP |

**Check:** on your computer, `ping x2key.com` answers from the server IP (can take up to an hour).

### 1.3 First login, updates, a deploy user
```bash
ssh root@SERVER_IP
apt update && apt -y upgrade
adduser --disabled-password --gecos "" deploy
usermod -aG www-data deploy
mkdir -p /home/deploy/.ssh && cp ~/.ssh/authorized_keys /home/deploy/.ssh/ && chown -R deploy:deploy /home/deploy/.ssh
timedatectl set-timezone UTC
```

### 1.4 Firewall and basic protection
```bash
apt -y install ufw fail2ban unattended-upgrades
ufw allow OpenSSH && ufw allow 80 && ufw allow 443 && ufw --force enable
dpkg-reconfigure -plow unattended-upgrades   # answer "Yes"
```
Only ports 22, 80 and 443 are open. The database (5432), Redis (6379), Meilisearch (7700) and Reverb (8080) stay
private on the server.

Then disable password logins: in `/etc/ssh/sshd_config` set `PasswordAuthentication no` and `PermitRootLogin prohibit-password`, then `systemctl restart ssh`.

**Check:** `ufw status` lists only OpenSSH, 80, 443.

---

## Part 2 — Install the software

### 2.1 PHP 8.4 and extensions
```bash
add-apt-repository -y ppa:ondrej/php && apt update
apt -y install php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis php8.4-intl php8.4-bcmath php8.4-mbstring \
  php8.4-xml php8.4-curl php8.4-zip php8.4-gd php8.4-gmp php8.4-opcache php8.4-readline unzip git curl
```
Production PHP settings — create `/etc/php/8.4/fpm/conf.d/99-x2key.ini`:
```ini
memory_limit = 512M
upload_max_filesize = 40M
post_max_size = 40M
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 30000
opcache.validate_timestamps = 0
```
Copy the same file to `/etc/php/8.4/cli/conf.d/` but with `opcache.validate_timestamps = 1`.
**Never install Xdebug or PCOV on the server** (they slow every request).
```bash
systemctl restart php8.4-fpm
```

### 2.2 Composer
```bash
curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

### 2.3 Node.js 24 (builds the pages and renders them on the server)
```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | bash - && apt -y install nodejs
```

### 2.4 PostgreSQL 17
```bash
install -d /usr/share/postgresql-common/pgdg
curl -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc https://www.postgresql.org/media/keys/ACCC4CF8.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt noble-pgdg main" > /etc/apt/sources.list.d/pgdg.list
apt update && apt -y install postgresql-17
sudo -u postgres psql -c "CREATE USER x2key WITH PASSWORD 'CHOOSE_A_LONG_PASSWORD';"
sudo -u postgres psql -c "CREATE DATABASE x2key OWNER x2key;"
```
Keep the password for `.env`. (Managed database instead? Create it at your provider with daily backups +
point-in-time recovery, and use its host/port/user in `.env`.)

### 2.5 Redis
```bash
apt -y install redis-server
sed -i 's/^# requirepass .*/requirepass CHOOSE_A_REDIS_PASSWORD/' /etc/redis/redis.conf
systemctl restart redis-server
```

### 2.6 Meilisearch (catalog search)
```bash
curl -L https://install.meilisearch.com | sh && mv ./meilisearch /usr/local/bin/
useradd -r -s /usr/sbin/nologin meilisearch && mkdir -p /var/lib/meilisearch && chown meilisearch: /var/lib/meilisearch
```
Create `/etc/systemd/system/meilisearch.service`:
```ini
[Unit]
Description=Meilisearch
After=network.target

[Service]
User=meilisearch
ExecStart=/usr/local/bin/meilisearch --db-path /var/lib/meilisearch/data --http-addr 127.0.0.1:7700 --env production --master-key CHOOSE_A_MEILI_MASTER_KEY --no-analytics
Restart=always

[Install]
WantedBy=multi-user.target
```
```bash
systemctl daemon-reload && systemctl enable --now meilisearch
```

### 2.7 Nginx, Supervisor, Certbot
```bash
apt -y install nginx supervisor certbot python3-certbot-nginx
```

**Check Part 2:** `php -v` shows 8.4, `node -v` shows v24, `psql --version` shows 17,
`redis-cli -a YOUR_REDIS_PASSWORD ping` answers `PONG`, `curl -s 127.0.0.1:7700/health` answers `{"status":"available"}`.

---

## Part 3 — Get the code

### 3.1 Give the server read access to GitHub
As the deploy user:
```bash
su - deploy
ssh-keygen -t ed25519 -C "x2key-server" -N "" -f ~/.ssh/id_ed25519
cat ~/.ssh/id_ed25519.pub
```
In GitHub: repository › Settings › Deploy keys › Add key (read-only) and paste it.

### 3.2 Download the code
```bash
sudo mkdir -p /var/www && sudo chown deploy:www-data /var/www
cd /var/www && git clone git@github.com:YOUR_ACCOUNT/x2key.git x2key && cd x2key
git checkout main
```

---

## Part 4 — Configuration (`.env`)

### 4.1 Create the file
```bash
cp .env.example .env
chmod 640 .env
```

### 4.2 Generate the secrets
Run each command and paste the result into the matching line of `.env`:
```bash
composer install --no-dev --optimize-autoloader   # needed before artisan works
php artisan key:generate                         # writes APP_KEY
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # → STORAGE_MASTER_KEY
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'   # → STORAGE_HMAC_PEPPER
php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;'         # → REVERB_APP_KEY
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'         # → REVERB_APP_SECRET
php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'         # → HEALTH_TOKEN
php artisan push:keys                            # prints VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY
```

> ⚠️ **`APP_KEY`, `STORAGE_MASTER_KEY`, `STORAGE_HMAC_PEPPER` and the VAPID keys must never change after launch.**
> The storage keys encrypt every game key and account that sellers upload: lose or change them and that stock can
> never be read again. Save all four in your password manager **now**, in a second place too.

### 4.3 Fill in `.env`
Set (or change) these lines. Everything not listed keeps the value from `.env.example`.

```dotenv
APP_NAME=X2KEY
APP_ENV=production
APP_DEBUG=false
APP_URL=https://x2key.com

LOG_STACK=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=x2key
DB_USERNAME=x2key
DB_PASSWORD=the PostgreSQL password from 2.4

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=the Redis password from 2.5
REDIS_PORT=6379
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_ENCRYPT=true
QUEUE_CONNECTION=redis

SCOUT_DRIVER=meilisearch
CATALOG_SEARCH_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=the Meilisearch master key from 2.6

INERTIA_SSR_ENABLED=true
INERTIA_SSR_URL=http://127.0.0.1:13714

# Live chat and live counters: the app talks to Reverb locally, browsers through nginx on 443
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=x2key
REVERB_HOST=127.0.0.1
REVERB_PORT=8080
REVERB_SCHEME=http
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST=x2key.com
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https

# E-mail (example: Resend — Part 8)
MAIL_MAILER=smtp
MAIL_HOST=smtp.resend.com
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=resend
MAIL_PASSWORD=your e-mail provider API key
MAIL_FROM_ADDRESS=no-reply@x2key.com
MAIL_FROM_NAME=X2KEY
MERCHANT_SERVICES_EMAIL=merchants@x2key.com
CUSTOMER_SERVICES_EMAIL=support@x2key.com
DISPUTE_SERVICES_EMAIL=disputes@x2key.com

# Payments (Part 7)
STRIPE_KEY=pk_live_...
STRIPE_SECRET=sk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
NOWPAYMENTS_API_KEY=
NOWPAYMENTS_IPN_SECRET=
NOWPAYMENTS_SANDBOX=false

# Backups to another provider (Part 9)
BACKUP_DISK=s3
BACKUPS_REQUIRED=true
BACKUP_RETENTION_DAYS=14
AWS_ACCESS_KEY_ID=backup bucket key
AWS_SECRET_ACCESS_KEY=backup bucket secret
AWS_DEFAULT_REGION=bucket region
AWS_BUCKET=x2key-backups
AWS_ENDPOINT=https://s3.your-provider.com   # Backblaze/Wasabi; leave out for AWS
AWS_USE_PATH_STYLE_ENDPOINT=true            # Backblaze/Wasabi; false for AWS

# Alerts (Part 10)
OPS_ALERT_EMAIL=you@yourmail.com
OPS_SLACK_WEBHOOK=
HEALTH_TOKEN=generated in 4.2

# Web push
VAPID_SUBJECT=mailto:support@x2key.com
```
Leave `GOOGLE_*` / `FACEBOOK_*` empty for now (Part 11). Do **not** set `FAKE_PSP_WEBHOOK_SECRET`: the fake test
payment page is refused in production.

**Check:** `php artisan about` shows Environment `production`, Debug Mode `OFF`, and drivers `pgsql`, `redis`,
`reverb`.

---

## Part 5 — Build and install the application

All as the `deploy` user, in `/var/www/x2key`:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build                      # client + server-side rendering bundles; re-run after any VITE_* change
php artisan migrate --force        # creates every table
php artisan db:seed --force        # base data only in production: roles, levels, catalog, payment methods, rules, home page
php artisan storage:link
php artisan catalog:search-sync    # fills the search index
php artisan optimize
php artisan filament:optimize
sudo chown -R deploy:www-data storage bootstrap/cache && sudo chmod -R ug+rwX storage bootstrap/cache
```

In production the seeder does **not** create demo accounts (`admin_demo`, `buyer_demo`…) or demo offers, and it
does not add the fake test payment methods.

**Check:** `php artisan migrate:status` shows every migration as `Ran`.

---

## Part 6 — Web server, HTTPS and background processes

### 6.1 Nginx site
Create `/etc/nginx/sites-available/x2key`:
```nginx
server {
    listen 80;
    server_name x2key.com www.x2key.com;
    root /var/www/x2key/public;
    index index.php;
    client_max_body_size 40M;

    add_header X-Content-Type-Options nosniff;
    gzip on;
    gzip_types text/plain text/css application/json application/javascript image/svg+xml;

    # Hashed assets: cache for a year
    location ~ ^/(build|fonts)/ {
        expires max;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Live chat / live counters websocket (Reverb)
    location ~ ^/(app|apps)/ {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 120s;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```
```bash
sudo ln -s /etc/nginx/sites-available/x2key /etc/nginx/sites-enabled/ && sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 6.2 HTTPS certificate
```bash
sudo certbot --nginx -d x2key.com -d www.x2key.com --redirect -m you@yourmail.com --agree-tos
```
Certbot renews automatically. **Check:** `https://x2key.com` opens with a padlock.

### 6.3 Background processes (Supervisor)
Create `/etc/supervisor/conf.d/x2key.conf`:
```ini
[program:x2key-horizon]
command=php /var/www/x2key/artisan horizon
user=deploy
autostart=true
autorestart=true
stopwaitsecs=3600
stdout_logfile=/var/www/x2key/storage/logs/horizon.log

[program:x2key-reverb]
command=php /var/www/x2key/artisan reverb:start --host=127.0.0.1 --port=8080
user=deploy
autostart=true
autorestart=true
stdout_logfile=/var/www/x2key/storage/logs/reverb.log

[program:x2key-ssr]
command=php /var/www/x2key/artisan inertia:start-ssr
user=deploy
autostart=true
autorestart=true
stdout_logfile=/var/www/x2key/storage/logs/ssr.log
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```
All three must show `RUNNING`. Horizon is the queue worker: payment webhooks (`payments` queue, first), e-mails,
web push and broadcasts.

### 6.4 Scheduler (cron)
```bash
sudo crontab -u deploy -e
```
Add one line:
```
* * * * * cd /var/www/x2key && php artisan schedule:run >> /dev/null 2>&1
```
**Check (after 2 minutes):** `php artisan schedule:list` shows the jobs, and
`curl -s "https://x2key.com/health?token=YOUR_HEALTH_TOKEN"` shows `scheduler` as ok.

---

## Part 7 — Payments

### 7.1 Stripe (cards)
1. Stripe Dashboard › switch to **Live mode** (your account must be activated: business details, bank account).
2. Developers › API keys: copy the **Publishable key** (`pk_live_…`) and **Secret key** (`sk_live_…`) into `.env`.
3. Developers › Webhooks › **Add endpoint**:
   - URL: `https://x2key.com/webhooks/stripe`
   - Events: `checkout.session.completed`, `checkout.session.async_payment_succeeded`,
     `checkout.session.async_payment_failed`, `checkout.session.expired`
4. Open the endpoint › **Signing secret** (`whsec_…`) → `STRIPE_WEBHOOK_SECRET` in `.env`.
5. Apply: `php artisan config:cache && php artisan horizon:terminate` (Supervisor restarts it).

**Check:** Stripe › Webhooks › your endpoint › "Send test event" with `checkout.session.completed` → the delivery shows
**200** or **400** (400 only means "unknown test payment", the signature was accepted). Then Admin › Payments ›
Provider events lists it.

### 7.2 NOWPayments (crypto, optional)
1. NOWPayments dashboard › Settings › Payments: create the **API key** and the **IPN secret** → `.env`
   (`NOWPAYMENTS_API_KEY`, `NOWPAYMENTS_IPN_SECRET`).
2. IPN callback URL: `https://x2key.com/webhooks/nowpayments`.
3. Add your payout wallet (where NOWPayments sends the crypto you receive).
4. `php artisan config:cache && php artisan horizon:terminate`.

Crypto refunds are manual (RUNBOOK §11): each one appears under Admin › Risk flags as `manual_crypto_refund`.

### 7.3 Choose what buyers see
Admin › Payments › Payment methods: switch methods on/off, countries, minimum/maximum and fees. A method only shows
at checkout when its provider keys are set.

---

## Part 8 — E-mail

1. At your e-mail provider, add the domain `x2key.com` and create the DNS records it asks for: **SPF**, **DKIM**
   and a **DMARC** record (e.g. `v=DMARC1; p=quarantine; rua=mailto:you@yourmail.com`). Wait until the provider shows
   the domain as verified.
2. Put the SMTP settings in `.env` (Part 4.3), then `php artisan config:cache && php artisan horizon:terminate`.
3. Create the mailboxes you advertise (support@, merchants@, disputes@) at your mail host, or forward them.

**Check:** sign up on the site with a real address: the verification e-mail arrives (and not in spam). Admin ›
System › Queues & e-mail lists it under "Latest e-mails sent".

---

## Part 9 — Backups

1. At your **second provider** create a private bucket (e.g. `x2key-backups`) and an access key limited to it. Fill
   the `AWS_*` lines and `BACKUP_DISK=s3`, `BACKUPS_REQUIRED=true` (Part 4.3), then `php artisan config:cache`.
2. Install the PostgreSQL client tools on the server (the backup uses `pg_dump`): already present with PostgreSQL 17;
   for a managed database run `sudo apt -y install postgresql-client-17`.
3. Run a first backup by hand: `php artisan ops:backup`.

**Check:** the bucket contains `backups/<date>/` with `database.dump`, `uploads.tar.gz` and `manifest.json`. From now
on it runs every night at 03:15 UTC and old sets are deleted after 14 days.

4. **Restore drill (before launch, then monthly):** on another server, `scripts/restore-drill.sh <dump>` (RUNBOOK §4).
   Write down how long it took.

> Also keep **outside the server**: the `.env` file (in your password manager) — a backup without `APP_KEY` and the
> storage keys cannot be decrypted.

---

## Part 10 — Monitoring and alerts

1. `.env`: `OPS_ALERT_EMAIL` (and/or `OPS_SLACK_WEBHOOK`) receives an alert when a health check fails and when it
   recovers.
2. Uptime monitor: add a check on `https://x2key.com/health` every minute, alert by SMS/e-mail. This also warns you
   when the whole server is down.
3. Optional: add `https://x2key.com/health?token=YOUR_HEALTH_TOKEN` as a keyword check for `"status":"ok"`.

**Check:** `curl -s "https://x2key.com/health?token=YOUR_HEALTH_TOKEN"` — every check `ok` (backup becomes ok after
Part 9).

---

## Part 11 — Optional features

| Feature | What to do | Guide |
|---|---|---|
| Google / Facebook sign-in | create the apps, put `GOOGLE_*` / `FACEBOOK_*` in `.env` | RUNBOOK §8 |
| Web push notifications | done if `VAPID_*` are set (Part 4.2) | RUNBOOK §2 |
| Interface languages (es, de, pt, tr) | translate missing strings with `lang:missing` | RUNBOOK §9 |

After any `.env` change: `php artisan config:cache && php artisan horizon:terminate && php artisan reverb:restart`.
After any `VITE_*` change also `npm run build`.

---

## Part 12 — Your admin account

1. Open `https://x2key.com`, **sign up** with your own username and e-mail, and confirm the e-mail.
2. On the server, give that account the admin role:
   ```bash
   cd /var/www/x2key
   php artisan tinker --execute="App\Domains\Identity\Models\User::where('username', 'YOUR_USERNAME')->firstOrFail()->assignRole('admin');"
   ```
3. On the site: Settings › Security › **turn on two-step verification** (authenticator app). Save the recovery codes
   in your password manager.
4. Open `https://x2key.com/admin`.

Give other staff their roles from Admin › Users › open the user › Roles (support, merchant manager, moderator,
finance, admin). Each of them must turn on two-step verification before `/admin` opens for them. Who can open what:
Admin › Users › Staff roles.

---

## Part 13 — Set up the shop in the admin

Do these in Admin (`/admin`):

1. **System › Platform settings** — review fees, holds, payment window, risk limits, withdrawal minimum. Money
   settings need a second admin to approve: create a second admin account first, or ask a co-founder.
2. **Payments › Payment methods** — check which methods are on.
3. **Catalog** — in production the catalog starts **empty** (category types, platforms and regions are seeded; the
   demo games are local only). Create your games, their categories and products, and add pictures to each game
   (Catalog › Games › a game › Pictures). Sellers can only list offers on games that exist here.
4. **Content › Pages** — replace every page marked "Draft" (Terms, Privacy, Refund, Seller Agreement, Buyer
   Protection, Cookie, DMCA, GDPR, Pricing, Affiliate terms…) with the texts written with your lawyer.
5. **Content › Home modules / News / Help** — the home page and the help centre.
6. **Payouts › Payout method types** — the withdrawal methods sellers can use.
7. **Support › Canned replies** — ready answers for tickets.

---

## Part 14 — Final checks before opening

Do a complete test with real money (small amounts, then refund yourself):

- [ ] `https://x2key.com` loads fast, with a padlock, in English, French and Arabic (right-to-left).
- [ ] Sign up → verification e-mail arrives → log in → turn on 2FA.
- [ ] Seller: activate a store, create an offer, upload a key to Storage.
- [ ] Buyer (another account): buy with **Stripe** (real card, small amount) → order becomes **Paid** within seconds
      → key delivered → reveal works.
- [ ] (If enabled) buy with **NOWPayments** → order paid after the IPN.
- [ ] Chat between buyer and seller updates **without reloading** (live chat).
- [ ] The notification bell updates without reloading.
- [ ] Confirm the order → seller balance moves after the hold; refund an order from Admin › Orders → money goes back.
- [ ] Admin › System › Operations: every health check ok, scheduled jobs show recent runs.
- [ ] Admin › System › Queues & e-mail: no failed jobs.
- [ ] `/horizon` opens for your admin account (with 2FA).
- [ ] Backup present in the bucket; restore drill done.
- [ ] Uptime monitor green; you received the test alert.
- [ ] Legal pages final (no "Draft"), cookie banner shown, support e-mails answered.

When every box is ticked, the site is fully working.

---

## Part 15 — Updating the site later (every new version)

As `deploy`, in `/var/www/x2key`:
```bash
php artisan down --retry=60          # visitors see a short maintenance page
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize && php artisan filament:optimize
php artisan catalog:search-sync      # only if the release notes say the search index changed
php artisan horizon:terminate        # Supervisor starts the new version
php artisan reverb:restart
sudo supervisorctl restart x2key-ssr
sudo systemctl reload php8.4-fpm     # clears OPcache (validate_timestamps=0)
php artisan up
```
**Check:** `/health` ok, Admin › System › Queues & e-mail has no new failed jobs.

**If something breaks:** `git checkout <previous commit>` then repeat the same commands (skip `migrate`). If a
migration was the problem, restore the database from the night's backup (RUNBOOK §4) before going back.

Rules: always test a release on a copy of the site (a staging server set up with this same guide) before production;
never run `migrate:fresh`, `db:seed` with demo data or `e2e:prepare` on production (they refuse or wipe data).

---

## Part 16 — Not technical, but needed before a public launch

- Legal texts by a lawyer (Part 13.4), company registration, and VAT/tax setup for digital goods.
- Stripe and NOWPayments: confirm in writing that game keys/accounts are allowed for your account type.
- Founder decisions still open: docs/OPEN_QUESTIONS.md (KYC provider, SMS provider, payout providers).
- Virus scanning of uploads (ClamAV) — RUNBOOK §7.
- Load test on staging and a one-week staging soak — RUNBOOK §5.
