# Multitenant Fax to 2 Email for Businesses

A self-hosted, multi-tenant fax ↔ email service for Debian 13+ and Rocky Linux 10+.
Faxes received on a business's numbers are emailed to them, and emails can be sent
out as faxes. Built on Laravel, Vue (Inertia), PostgreSQL, Nginx and FreeSWITCH.

> **Status:** early development. Receiving faxes works end to end (SIP trunk → fax number →
> PDF by email). Sending faxes is next.

## Roles

| Role | Can do |
|---|---|
| **Admin** | Everything, including inviting and removing Admins and Provisioners. |
| **Provisioner** | Create and manage accounts; invite, promote and remove Account Owners and Account Users in any account. Cannot invite, change or remove Admins or Provisioners, and cannot delete accounts. |
| **Account Owner** | Invite users to their own account and promote them to Account Owner. Cannot remove or demote other owners. An account can have several owners. |
| **Account User** | Uses the portal for their own account. |

Every account always keeps at least one owner. Nobody can change or delete their own access.
Sign-up is invitation-only.

## SIP trunks

Admins connect SIP providers under **SIP trunks**. Add as many as you need:

- **IP address** trunks: the provider sends calls from known addresses; no username or password.
- **Username and password** trunks: this server registers to the provider.
- **Protocol:** UDP, TCP or TLS. **Port:** 5060 by default (5061 for TLS), or any port your provider uses.
- **Additional provider addresses:** list any other IPs, ranges or hostnames the provider sends calls from.

Every trunk's addresses are added to the firewall whitelist as **Provider trunk**. Only those
addresses can reach the SIP ports (5060/5061); everyone else is dropped before reaching FreeSWITCH.
Hostnames are looked up again every 5 minutes, so the whitelist follows a provider's IP changes.
The page shows each trunk's live status (registered, or reachable for IP trunks).

Incoming fax calls are answered with T.38 when the provider offers it, otherwise G.711.

FreeSWITCH is built from source by the installer (pinned version, checksum-verified). It reads
its SIP and dialplan configuration from the app, so changes in the portal apply within seconds.
Set `FREESWITCH_EXTERNAL_IP` in `.env` if the server is behind NAT.

## Fax numbers and received faxes

Admins and Provisioners add **Fax numbers** (the DIDs your providers deliver) and assign each to
an account. Account Owners can change a number's label and the email addresses it delivers to.
If no recipients are set, faxes go to the account's owners.

When a fax arrives, FreeSWITCH asks the app what to do with the dialled number. Numbers that
aren't set up are refused. For a known number, the fax is received, converted to PDF and emailed
as an attachment. Every account member can see their account's faxes under **Faxes**, download
the PDF and, as an owner, email it again. Faxes are kept for 90 days.

- Numbers are stored with their country code; 10-digit North American numbers get a leading 1,
  and calls match whether the provider sends 10 or 11 digits.
- The TIFF-to-PDF conversion is built in: the fax's compressed image data is copied into the PDF
  unchanged, so there is no quality loss and no extra software to install.
- If the mail server is down, delivery is retried for about an hour; the Faxes page shows any error.

## Firewall

Admins manage the firewall under **Firewall** in the portal:

- **Blocked:** block an IP or range, permanently or for a set time. A block drops *all*
  traffic from that address (SIP, web and SSH). Bans made by fail2ban appear here too and
  can be lifted.
- **Whitelist:** addresses that are never blocked or banned. Add your office and your SIP
  carriers so they cannot be locked out.
- **Settings:** fail2ban watches SIP (FreeSWITCH), portal logins and SSH. By default an
  address is banned for 1 day after 5 failures within 10 minutes, and an address banned
  3 times within 7 days is banned for 30 days.
- **Activity:** history of every ban, block and change, kept for 180 days.

The portal can't block your own address, the server's loopback, a whitelisted range,
or anything larger than a /8.

The web app never touches the firewall itself. It stores the rules, and a root-owned
service (`fax2email-firewall-apply`, run by systemd) validates them and applies them to
fax2email's own nftables table and to fail2ban, within a few seconds. Every minute it also
copies fail2ban's current bans back into the portal.

## Where things live

Everything belonging to the application is under `/var/www/html`:

| Path | Contents |
|---|---|
| `/var/www/html/fax2email` | The application. Nginx serves only its `public/` folder. |
| `/var/www/html/fax2email/.env` | Settings and secrets. Never served to the web. |
| `/var/www/html/fax2email/storage` | Faxes, logs and uploaded files. |
| `/var/www/html/fax2email-backups` | Automatic backups taken before each update. |
| `/usr/local/sbin/fax2email-*` | The update and firewall commands, which run as root. They're kept outside the web folder so the website can't change them. |

Files are owned by `www-data:www-data` on both Debian and Rocky Linux.

## Installing

Requires a fresh **Debian 13+** or **Rocky Linux 10+** server and a user with `sudo`.

```bash
cd /tmp
# Download the latest release archive. While the repository is private, use the GitHub CLI:
gh release download --repo key2comm/fax2email --pattern 'fax2email-*.tar.gz'
tar -xzf fax2email-v*.tar.gz
sudo ./fax2email-v*/scripts/install.sh --server-name fax.example.com
```

The installer sets up Nginx, PHP, PostgreSQL, the `www-data` user, SELinux rules (Rocky),
the firewall, a background queue worker and scheduler (systemd), and asks for the first
Admin's name, email and password. It is safe to run again. Run it with `--help` for options,
including `--admin-password-file` for unattended installs.

When it finishes, log in at `http://<server-name>` and set up the outgoing mail server
under **Mail server**.

## Updating a server

```bash
sudo fax2email-update --check   # is there a new version?
sudo fax2email-update           # install the latest release
sudo fax2email-update 0.3.0     # install (or roll back to) a specific release
```

The updater puts the site in maintenance mode, backs up the code and database,
installs the release, runs database migrations, and brings the site back. If any
step fails it restores the backup automatically. The last 5 backups are kept.

While the GitHub repository is private, set `UPDATE_GITHUB_TOKEN` in `.env` to a
[fine-grained token](https://github.com/settings/personal-access-tokens) with
read-only access to this repository's **Contents**.

## Releasing a new version (maintainers)

```bash
scripts/release.sh 0.2.0
```

This bumps `VERSION`, commits, tags `v0.2.0` and pushes. GitHub Actions then runs the
tests, builds an archive that includes `vendor/` and the compiled frontend (so servers
never need Composer or Node), and publishes it as a GitHub release.

Versions follow [semantic versioning](https://semver.org): bump the patch number for
fixes, the minor number for new features, and the major number for breaking changes.

## Development

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed   # demo users, all with password "password"
npm run dev
php artisan test
```

Create a real admin with `php artisan fax2email:create-admin`.
