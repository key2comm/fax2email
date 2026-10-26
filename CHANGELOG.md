# Changelog

All notable changes to fax2email. Versions follow [semantic versioning](https://semver.org).
Update a server with `sudo fax2email-update`.

## v0.1.7

- Fixed (Rocky Linux): sending a fax from the portal with a larger PDF failed with a blank page,
  because Nginx could not save the upload to its temporary folder.

## v0.1.6

- Fixed: with "Send HTTP visitors to HTTPS" on, FreeSWITCH could not load SIP trunks or the fax
  dialplan ("Not loaded in FreeSWITCH yet"). After updating, restart FreeSWITCH once:
  `sudo systemctl restart fax2email-freeswitch`.

## v0.1.5

- **Inbound MX** (Settings → Mail server): the DNS records email-to-fax needs, filled in with
  your domain and this server's address, advice on subdomains vs whole domains, and a
  **Check DNS** button that tests the MX records, where they point, and the mail service.
- **HTTPS auto-renew:** Let's Encrypt certificates renew automatically every 30 days (or 45 or
  60), automatic renewal can be turned off, and **Renew now** replaces the certificate at once.
- Fixed: a failed Let's Encrypt request could stop with "line 98" instead of the real reason.

## v0.1.4

- Fixed: the installer failed on Rocky Linux with SELinux enabled ("Usage: setsebool").

## v0.1.3

- **Branding** (Settings → Branding): your own app name, logo (with an optional dark-mode
  version), favicon and brand colour for the website (emails are not changed).

## v0.1.2

- **HTTPS from the portal** (Settings → HTTPS): free Let's Encrypt certificates that renew
  automatically, or your own certificate; optional redirect from HTTP to HTTPS.
- **Time zones:** each user can choose the time zone they see times in (Profile); the default
  is UTC.
- Admin pages are grouped under a **Settings** menu; the Mail server's first tab is now
  **SMTP Settings**.
- Fixed: unattended installer re-runs failed when an Admin already existed.

## v0.1.1

- Added this changelog.
- The guided installer asks for a GitHub token for updates while the repository is private.

## v0.1.0

First release.

- **Portal:** Admin, Provisioner, Account Owner and Account User roles; accounts (companies);
  invitation-only sign-up; light/dark Tabler interface.
- **Receiving faxes:** SIP trunks (IP or username/password; UDP, TCP or TLS; any port), fax
  numbers per account, faxes converted to PDF and emailed to each number's recipients.
- **Sending faxes:** from the portal with a company-wide address book, or by emailing PDFs to
  `<number>@<fax domain>` (senders verified by SPF/DKIM). Several PDFs become one fax; busy
  lines are retried; the sender gets a confirmation.
- **Mail server:** SMTP settings, mail log, email-to-fax log.
- **Firewall:** block and whitelist IPs, fail2ban for SIP, portal and SSH logins with longer bans
  for repeat offenders; SIP ports open only to trunk addresses.
- **Installer and updates:** guided installer for Debian 13+ and Rocky Linux 10+, one-command
  bootstrap from GitHub, and `fax2email-update` with automatic backup and rollback.
