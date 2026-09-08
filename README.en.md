# RepairDesk – Ticket & Repair Management

[Deutsch](README.md) · **English**

A lightweight ticket and repair management system written in PHP – no
framework, no Composer, no build step. Upload the files, open `install.php`,
done.

Built for repair shops, IT service providers and small support teams that want
to keep repair jobs, customers and technicians in one place without renting a
cloud service.

> **Note:** RepairDesk is proprietary software, **not open source**.
> The source of this Free edition is readable and free to use, but must not be
> sold or redistributed. See [LICENSE](LICENSE) for the full terms.
> The license is written in German and is the legally binding version; this
> README is a convenience translation.

---

## Free edition and paid edition

This repository always holds the **Free edition**: a complete, fully working
build of RepairDesk that trails the current commercial release by one version.

| | Free (this repo) | Subscription / Lifetime |
|---|---|---|
| Feature set | complete, one version behind | current |
| Price | free of charge | paid |
| Installations | unlimited, commercial use allowed | 1 per license |
| Updates | when a newer version moves down | immediately, via auto-updater |
| Support | none | yes |

Whenever a new commercial version ships, the previous one moves here as the
Free edition. Which one that currently is can be found under
[Releases](../../releases) – deliberately not in this text.

Licenses and pricing: **https://norix.at/repairdesk/#preise**

---

## Features

**Tickets**
- Repair jobs with ticket number, status, priority and technician assignment
- Device data: model, serial number, condition on arrival, damage description
- Internal notes (technicians only) and customer-facing comments
- Price proposal to the customer, final price on the ticket
- Customer view via token link – no customer login required

**Customers**
- Records with company, address, phone and email
- Search directly from the ticket form
- Ticket history per customer

**Email**
- Outgoing mail via SMTP
- Inbound import over IMAP: replies are attached to the matching ticket
- OAuth 2.0 for Microsoft 365 (no app password needed)
- Automatic fetching via cron job
- Log of every processed message

**Users & security**
- Technician and admin roles
- Two-factor authentication via TOTP (Google Authenticator, Aegis, etc.)
- Trusted devices, so the second factor isn't required on every login
- Brute-force throttling based on logged login attempts
- Password hashing with `password_hash()`

**Customisation & operations**
- Freely definable extra fields on tickets (text, select, date, checkbox, …)
- Company data, ticket prefix and timezone configurable in the settings
- Backup download (files and database) from the admin area
- Database migrations run automatically during updates

---

## Requirements

- PHP 8.0 or newer
- MySQL 5.7+ or MariaDB 10.3+ (InnoDB, `utf8mb4`)
- Apache or nginx
- PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `json`, `zip`
- For mail import additionally: `imap`
- Write access in the installation directory (for `config.php` and `backups/`)
- HTTPS strongly recommended

---

## Installation

1. Create an empty database (for example via phpMyAdmin) – no tables.
2. Upload the contents of the release to your web server.
3. Open `https://your-domain.tld/install.php` in a browser.
4. Enter database credentials, admin account, company data and base URL.
5. Submit. The installer creates all tables and the admin account, writes
   `config.php`, locks the `backups/` folder with an `.htaccess`, and then
   deletes itself.
6. Log in at `login.php`.

**Check after installing:**

- Is `install.php` really gone from the server?
- Is `config.php` unreachable from outside?
- Is `/backups/` blocked (a request must return 403)?
- Is 2FA enabled for every admin account?

Cron job for mail fetching, e.g. every 5 minutes:

```
*/5 * * * * php /path/to/project/app/fetch_mail.php >/dev/null 2>&1
```

---

## Updating

Free edition: download the new release and overwrite the files – **except
`config.php` and the `backups/` folder**. Database changes are handled by the
bundled migrations. Take a backup first.

Subscription and Lifetime licenses update straight from the admin area under
*Lizenz & Updates* instead: enter the license key, start the update. Files and
database are backed up automatically as part of that.

---

## Privacy

All customer, ticket and user data stays in your own database. There is no
cloud service behind it and no vendor access.

A connection to NORIX servers is only made when a license key is stored – the
key, the installation's domain and the program version are then sent for
license validation and update delivery. No customer data is transmitted.
Without a license key the software sends nothing outbound.

Running the system in compliance with GDPR (information duties, deletion
policy, data processing agreements) is the responsibility of whoever operates
the installation.

---

## Support

There is no support and no guaranteed response time on issues or pull requests
for the Free edition. Bug reports are welcome regardless – please include the
version, your PHP version and, if possible, an excerpt from the error log.

Commercial support is part of the Subscription and Lifetime licenses:
**office@norix.at**

---

## License

Proprietary. © 2026 NORIX IT Support und Webdesign e.U.

Free of charge, including commercial use inside your own business, on any
number of your own installations. Selling, redistributing to third parties and
offering it as your own product are not permitted. Full terms (in German):
[LICENSE](LICENSE).
