ENGLSIH

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

------------------------------------------------------------

Deutsch 

# RepairDesk – Ticket- & Reparaturverwaltung

Schlankes Ticket- und Reparatursystem in PHP – ohne Framework, ohne Composer,
ohne Build-Schritt. Hochladen, `install.php` aufrufen, fertig.

Entwickelt für Werkstätten, IT-Dienstleister und kleine Serviceteams, die
Reparaturaufträge, Kunden und Techniker an einem Ort verwalten wollen, ohne
dafür eine Cloud-Lösung zu mieten.

> **Hinweis:** RepairDesk ist proprietäre Software, **kein Open Source**.
> Der Quellcode dieser Free-Version ist einsehbar und frei nutzbar, darf aber
> nicht verkauft oder weitergegeben werden. Details in der [LICENSE](LICENSE).

---

## Free-Version und Kaufversion

In diesem Repository liegt immer die **Free-Version**: eine vollständige,
lauffähige Fassung von RepairDesk, die dem aktuellen Verkaufsstand um eine
Version nachläuft.

| | Free-Version (dieses Repo) | Abo / Lifetime |
|---|---|---|
| Funktionsumfang | vollständig, Stand der Vorgängerversion | aktueller Stand |
| Kosten | kostenlos | kostenpflichtig |
| Installationen | beliebig viele, auch gewerblich | 1 pro Lizenz |
| Updates | sobald eine neue Version nachrückt | sofort, per Auto-Updater |
| Support | keiner | ja |

Sobald eine neue Verkaufsversion erscheint, rückt die bisherige hier als
Free-Version nach. Welche das gerade ist, steht unter
[Releases](../../releases) – nicht in diesem Text.

Lizenzen und Preise: **https://norix.at/repairdesk/#preise**

---

## Funktionen

**Tickets**
- Reparaturaufträge mit Ticketnummer, Status, Priorität und Techniker-Zuweisung
- Gerätedaten: Modell, Seriennummer, Zustand bei Annahme, Schadensbeschreibung
- Interne Notizen (nur für Techniker) und Kundenkommentare
- Preisvorschlag an den Kunden, Endpreis am Ticket
- Kundenansicht per Token-Link – ohne Login für den Kunden

**Kunden**
- Stammdaten mit Firma, Adresse, Telefon und E-Mail
- Suche direkt im Ticket-Formular
- Ticket-Historie pro Kunde

**E-Mail**
- Versand über SMTP
- Mail-Import per IMAP: eingehende Antworten landen am passenden Ticket
- OAuth 2.0 für Microsoft 365 (kein App-Passwort nötig)
- Automatischer Abruf per Cronjob
- Protokoll der verarbeiteten Nachrichten

**Benutzer & Sicherheit**
- Techniker- und Admin-Rollen
- Zwei-Faktor-Authentifizierung per TOTP (Google Authenticator, Aegis u. a.)
- Vertrauenswürdige Geräte, damit der zweite Faktor nicht bei jedem Login kommt
- Brute-Force-Bremse über protokollierte Login-Versuche
- Passwort-Hashing mit `password_hash()`

**Anpassung & Betrieb**
- Frei definierbare Zusatzfelder für Tickets (Text, Auswahl, Datum, Checkbox …)
- Firmendaten, Ticket-Prefix und Zeitzone über die Einstellungen
- Backup-Download (Dateien und Datenbank) aus dem Adminbereich
- Datenbank-Migrationen laufen beim Update automatisch

---

## Systemvoraussetzungen

- PHP 8.0 oder neuer
- MySQL 5.7+ oder MariaDB 10.3+ (InnoDB, `utf8mb4`)
- Apache oder nginx
- PHP-Erweiterungen: `pdo_mysql`, `mbstring`, `openssl`, `json`, `zip`
- Für den Mail-Import zusätzlich: `imap`
- Schreibrechte im Installationsverzeichnis (für `config.php` und `backups/`)
- HTTPS wird dringend empfohlen

---

## Installation

1. Datenbank anlegen (z. B. über phpMyAdmin) – leer, ohne Tabellen.
2. Den Inhalt des Releases auf den Webserver hochladen.
3. Im Browser `https://deine-domain.tld/install.php` aufrufen.
4. Datenbankzugang, Admin-Account, Firmendaten und Base-URL eintragen.
5. Absenden. Der Installer legt alle Tabellen und den Admin-Account an,
   schreibt `config.php`, sperrt den `backups/`-Ordner per `.htaccess`
   und löscht sich anschließend selbst.
6. Einloggen unter `login.php`.

**Nach der Installation prüfen:**

- Liegt `install.php` wirklich nicht mehr auf dem Server?
- Ist `config.php` von außen nicht erreichbar?
- Ist `/backups/` gesperrt (Aufruf muss 403 liefern)?
- 2FA für alle Admin-Accounts aktiviert?

Mail-Abruf per Cronjob, z. B. alle 5 Minuten:

```
*/5 * * * * php /pfad/zum/projekt/app/fetch_mail.php >/dev/null 2>&1
```

---

## Update

Free-Version: neues Release herunterladen, Dateien überschreiben – **außer
`config.php` und dem Ordner `backups/`**. Datenbankänderungen erledigen die
mitgelieferten Migrationen. Vorher ein Backup ziehen.

Abo- und Lifetime-Lizenzen aktualisieren stattdessen direkt aus dem
Adminbereich unter *Lizenz & Updates*: License Key eintragen, Update starten.
Dateien und Datenbank werden dabei automatisch gesichert.

---

## Datenschutz

Alle Kunden-, Ticket- und Benutzerdaten bleiben in deiner eigenen Datenbank.
Es gibt keinen Cloud-Dienst dahinter und keinen Zugriff des Herstellers.

Eine Verbindung zu Servern von NORIX entsteht nur, wenn ein License Key
hinterlegt ist – dann werden Key, Domain und Programmversion zur Lizenzprüfung
übertragen, keine Kundendaten. Ohne Key sendet die Software nichts nach außen.

Für den DSGVO-konformen Betrieb (Informationspflichten, Löschkonzept,
Auftragsverarbeitung) ist der Betreiber der Installation verantwortlich.

---

## Support

Für die Free-Version gibt es keinen Support und keine zugesagte Reaktionszeit
auf Issues oder Pull Requests. Fehlerberichte sind trotzdem willkommen –
bitte mit Version, PHP-Version und, wenn möglich, Auszug aus dem Fehlerlog.

Kommerzieller Support ist Teil der Abo- und Lifetime-Lizenzen:
**office@norix.at**

---

## Lizenz

Proprietär. © 2026 NORIX IT Support und Webdesign e.U.

Kostenlose Nutzung erlaubt, auch gewerblich im eigenen Betrieb, in beliebig
vielen eigenen Installationen. Verkauf, Weitergabe an Dritte und das Anbieten
als eigenes Produkt sind nicht gestattet. Vollständige Bedingungen:
[LICENSE](LICENSE).
