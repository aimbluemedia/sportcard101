# 🃏 sportscard101

A password-protected web app that scans **eBay** for **PSA 10 graded sports
cards**, detects the best-priced auctions, and notifies you with everything you
need to place a bid.

Built with **PHP 8** + **MySQL/MariaDB** — no framework, no build step.

---

## How it works

1. You create **saved searches** (e.g. *"Michael Jordan Fleer Rookie — PSA 10,
   auctions only, flag anything 25%+ below market"*).
2. The scanner queries eBay's **Browse API** for matching active listings.
3. For each search it computes a **market baseline** (the median price of the
   comparable listings it found — the median ignores the very bargains we're
   hunting, so it isn't dragged down by them).
4. Any listing priced at/below your threshold under that baseline is flagged as
   a **deal** and surfaced on the dashboard, sorted by biggest discount, with a
   direct **"Bid on eBay"** link.
5. New deals trigger an **email notification** (optional) and a CLI scanner can
   run on **cron** so deals are found even while you're away.

> **Mock mode:** with no eBay credentials configured, the app runs on realistic
> sample data so you can explore every feature offline. The dashboard shows a
> banner when it's in mock mode.

---

## Requirements

- PHP 8.1+ with `pdo_mysql` and `curl` extensions
- MySQL 5.7+ / MariaDB 10.3+
- (Optional) eBay developer account for live data — https://developer.ebay.com/

---

## Setup

```bash
# 1. Configure
cp config.sample.php config.php
#   edit config.php — set DB credentials, and eBay API keys if you have them

# 2. Create the database
mysql -u root -e "CREATE DATABASE sportscard101 CHARACTER SET utf8mb4;"

# 3. Import schema + create your login (username, password, [email])
php bin/install.php admin 'your-strong-password' you@example.com

# 4. Serve the app
php -S 127.0.0.1:8000
#   then open http://127.0.0.1:8000/login.php
```

### Deploying to a subdomain (e.g. sportscard101.com on shared hosting)

The whole app runs from a **single folder** — the subdomain's document root.

1. Upload everything in this repo into the subdomain's document root folder
   (the folder hPanel created for `sportscard101.com`).
2. The included `.htaccess` files keep `config.php`, `schema.sql`, and the
   `src/` and `bin/` directories private — only the app pages are served.
3. Create `config.php` and run the installer (or use phpMyAdmin — see below).

---

## eBay API credentials

1. Sign in at https://developer.ebay.com/ and create an application keyset.
2. Copy the **Client ID** (App ID) and **Client Secret** (Cert ID) into
   `admin/config.php` under `ebay`.
3. That's it — the app uses the OAuth2 *client-credentials* flow to fetch an
   application token automatically; no user consent step required for searching.

Optionally set `campaign_id` (eBay Partner Network) to earn affiliate
attribution on the outbound listing links.

---

## AI Opportunity Engine

The scanner runs deal candidates through Claude to surface buy/sell
opportunities that are hard to spot manually:

- **Canonical card** — normalises messy eBay titles to one clean identity so
  prices actually compare.
- **Verdict** — BUY / WATCH / PASS with a confidence score.
- **Hidden gems** — listings that stay cheap because they're misspelled,
  miscategorised, or missing key terms ("rookie", grade) — bargains normal
  searches miss.
- **Flip margin** — estimated profit vs. baseline after ~13% eBay fees.
- **Beginner reason** — one plain-English sentence per listing.

Add an Anthropic API key (`ANTHROPIC_API_KEY`, or `ai.api_key` in `config.php`)
from https://console.anthropic.com/ to enable real AI analysis. With no key it
runs in **MOCK mode** using heuristic scoring, so the whole UI still works.
Model defaults to `claude-opus-4-8`.

> **Upgrading an existing install?** The AI fields are new columns on
> `listings`. Re-import `schema.sql` (it's idempotent for tables) or run the
> `ALTER TABLE listings ADD COLUMN ai_*` statements to add them.

## Automated scanning (cron)

Run the CLI scanner on a schedule so deals are found and emailed automatically:

```cron
*/15 * * * *  php /path/to/sportscard101/bin/scan.php >> /var/log/sportscard101.log 2>&1
```

Enable email in `admin/config.php` (`mail.enabled = true`, set `mail.to`) to receive a
digest whenever new deals appear. Email uses PHP's `mail()`; for Gmail/SMTP,
configure your server's mail transport or an SMTP relay.

---

## Project layout

```
.htaccess              Web-root rules (protects config/schema/code)
index.php              Dashboard / deals feed
searches.php           Manage saved searches
scan.php               "Scan now" action
login.php logout.php   Authentication
assets/style.css
config.sample.php      Configuration template (copy to config.php)
config.php             Your real config — git-ignored, blocked from web
schema.sql             MySQL schema (blocked from web)
src/                   Application code — included by PHP, blocked from web
  .htaccess            Deny-all
  bootstrap.php        Config, autoloader, session, DB
  Database.php  Auth.php
  EbayClient.php       eBay Browse API client (+ mock fallback)
  DealFinder.php       Scan + baseline + deal detection
  Notifier.php         Email notifications
bin/                   CLI scripts — blocked from web
  .htaccess            Deny-all
  install.php          One-time setup (schema + admin user)
  scan.php             Cron scanner
```

---

## Security notes

- All pages except login require an authenticated session.
- Passwords are stored with `password_hash()` (bcrypt).
- Forms are CSRF-protected; sessions use `HttpOnly`/`SameSite` cookies and are
  regenerated on login.
- `admin/config.php` is git-ignored so credentials are never committed.
