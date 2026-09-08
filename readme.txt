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
