# Changelog

All notable changes to fax2email. Versions follow [semantic versioning](https://semver.org).
Update a server with `sudo fax2email-update`.

## Unreleased

- **Branding** (Settings → Branding): your own app name, logo (with an optional dark-mode
  version), favicon and brand colour, used in the portal, login page and emails.

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
