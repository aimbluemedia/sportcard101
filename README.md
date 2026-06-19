# 🏆 vipsvault

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
mysql -u root -e "CREATE DATABASE vipsvault CHARACTER SET utf8mb4;"

# 3. Import schema + create your login (username, password, [email])
php bin/install.php admin 'your-strong-password' you@example.com

# 4. Serve the public/ directory
php -S 127.0.0.1:8000 -t public
#   then open http://127.0.0.1:8000/login.php
```

In production, point your web server's document root at the **`public/`**
directory so only that folder is web-accessible (config and source stay private).

---

## eBay API credentials

1. Sign in at https://developer.ebay.com/ and create an application keyset.
2. Copy the **Client ID** (App ID) and **Client Secret** (Cert ID) into
   `config.php` under `ebay`.
3. That's it — the app uses the OAuth2 *client-credentials* flow to fetch an
   application token automatically; no user consent step required for searching.

Optionally set `campaign_id` (eBay Partner Network) to earn affiliate
attribution on the outbound listing links.

---

## Automated scanning (cron)

Run the CLI scanner on a schedule so deals are found and emailed automatically:

```cron
*/15 * * * *  php /path/to/vipsvault/bin/scan.php >> /var/log/vipsvault.log 2>&1
```

Enable email in `config.php` (`mail.enabled = true`, set `mail.to`) to receive a
digest whenever new deals appear. Email uses PHP's `mail()`; for Gmail/SMTP,
configure your server's mail transport or an SMTP relay.

---

## Project layout

```
config.sample.php      Configuration template (copy to config.php)
schema.sql             MySQL schema
public/                Web root — the only folder that should be exposed
  index.php            Dashboard / deals feed
  searches.php         Manage saved searches
  scan.php             "Scan now" action
  login.php logout.php Authentication
  assets/style.css
src/                   Application code (kept outside web root)
  bootstrap.php        Config, autoloader, session, DB
  Database.php  Auth.php
  EbayClient.php       eBay Browse API client (+ mock fallback)
  DealFinder.php       Scan + baseline + deal detection
  Notifier.php         Email notifications
bin/
  install.php          One-time setup (schema + admin user)
  scan.php             Cron scanner
```

---

## Security notes

- All pages except login require an authenticated session.
- Passwords are stored with `password_hash()` (bcrypt).
- Forms are CSRF-protected; sessions use `HttpOnly`/`SameSite` cookies and are
  regenerated on login.
- `config.php` is git-ignored so credentials are never committed.
