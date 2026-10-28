# Multitenant Fax to 2 Email for Businesses

A self-hosted, multi-tenant fax ↔ email service for Debian 13+ and Rocky Linux 10+.
Faxes received on a business's numbers are emailed to them, and emails can be sent
out as faxes. Built on Laravel, Vue (Inertia), PostgreSQL, Nginx and FreeSWITCH.

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

## Sending faxes

**From the portal:** under **Send fax**, pick a contact from the company address book or type
a number, choose which of your numbers to send from, and attach up to 10 PDFs (20 MB each).
They are faxed as one fax, in the order shown. Everyone in a company shares one **Address book**.

**By email:** email PDFs to `<fax number>@<fax domain>`, e.g. `15551234567@fax.example.com`,
from the address you log in with. Several PDFs become one fax, in attachment order.

So that people don't all need a portal login, Account Owners (and Admins and Provisioners) can
add **Sender domains** to an account under **Users**: anyone at that domain can then fax by email
from the account's first enabled number, without being invited. Invited users still send for
their own account. Each domain belongs to one account, matches exactly (add subdomains
separately), and webmail domains such as gmail.com can't be added. 

Because email From addresses are easy to forge, an email is only faxed when the sender passes
SPF, or a DKIM signature checked by OpenDKIM on this server, for the same domain as the From
address. Anything else is dropped without a reply. Admins see every fax email, accepted or
rejected, under **Mail server → Email to fax**.

Either way, PDFs are converted with Ghostscript, sent through the fax number's trunk (set per
number, or the first enabled trunk), retried up to 3 times if busy or unanswered, and the
sender gets an email with the result and a copy of what was sent.

## HTTPS

Admins turn on HTTPS under **Settings → HTTPS**:

- **Let's Encrypt (free):** enter the portal's domain and an email address. The domain's DNS
  must point at the server and port 80 must be reachable from the internet. The certificate is
  checked daily and renewed automatically before it expires.
- **Your own certificate:** paste or upload the certificate (with intermediates) and its
  private key. They are checked first: valid, not expired, the key matches, and the certificate
  covers the domain. The key is then kept in a root-only folder.
- **Send HTTP visitors to HTTPS** can be switched on or off, and HTTPS can be turned off again.

The portal never changes the web server itself. A root-owned service gets the certificate,
rewrites the Nginx site from built-in templates, tests it, and puts the previous configuration
back if Nginx rejects the new one, so a mistake can't take the portal offline.

## Time zones

Times are stored in UTC and shown in UTC by default. Each user can pick their own time zone
under **Profile**, and the portal then shows every date and time in that zone.

**Account TimeZones** Admin and Provisioners can set the account time zone so the header matches when sending faxes.


## Firewall

Admins manage the firewall under **Firewall** in the portal:

- **Blocked:** block an IP or range, permanently or for a set time. A block drops *all*
  traffic from that address (SIP, web and SSH). Bans made by fail2ban appear here too and
  can be lifted.
- **Whitelist:** addresses that are never blocked or banned. Add your office and your SIP
  carriers so they cannot be locked out.
- **Settings:** fail2ban watches SIP (FreeSWITCH), portal logins and SSH. By default, an
  address is banned for 1 day after 5 failures within 10 minutes, and an address banned
  3 times within 7 days is banned for 30 days.
- **Activity:** history of every ban, block and change, kept for 180 days.

The portal can't block your own address, the server's loopback, a whitelisted range,
or anything larger than a /8.

The web app never touches the firewall itself. It stores the rules, and a root-owned
service (`fax2email-firewall-apply`, run by systemd) validates them and applies them to
fax2email's own nftables table and to fail2ban, within a few seconds. Every minute it also
copies fail2ban's current bans back into the portal.

## Installing

Requires a fresh **Debian 13+** or **Rocky Linux 10+** server and a user with `sudo` or 'root'

```bash
curl -fsSL https://raw.githubusercontent.com/key2comm/fax2email/main/scripts/bootstrap.sh | sudo bash
```
The installer walks you through a few questions, with defaults in [brackets]:

1. **Portal address**: the hostname or IP people will use to open the portal.
2. **First Admin**: name, email and password for the first login (new servers only).
3. **Email-to-fax domain** (optional), e.g. `fax.yourdomain.com`. PDFs emailed to
   `<fax number>@fax.yourdomain.com` are faxed. Point that domain's MX record at the server.

It shows a summary to confirm, then installs everything without further prompts: Nginx, PHP,
PostgreSQL, FreeSWITCH (built from source, a few minutes), fail2ban, the firewall rules, and
Postfix/OpenDKIM if email-to-fax is on. It is safe to run again: it suggests your current
settings, keeps the database and Admin, and leaving the email-to-fax domain blank turns it off.

When it finishes, log in at `http://<portal address>` and set up the outgoing mail server
under **Mail server → SMTP Settings**.

## Firewall ports

If the server sits behind an external firewall (a cloud security group, your provider's
firewall, or a router), allow the following. The installer already opens these in the
server's own firewall (firewalld or ufw).

### Inbound (internet → server)

**Always needed**

| Port | Protocol | Allow from | Used for |
|---|---|---|---|
| 80 | TCP | Anywhere | The portal over HTTP, and Let's Encrypt certificate checks |
| 443 | TCP | Anywhere | The portal over HTTPS |
| 5060 | UDP | Your SIP providers' IPs | SIP calls from your providers |
| 5060 | TCP | Your SIP providers' IPs | SIP calls from your providers |
| 16384–32768 | UDP | Your SIP providers' media IPs, or anywhere | Fax media (RTP and T.38) |

**Only if you use the feature**

| Port | Protocol | Allow from | Needed when |
|---|---|---|---|
| 5061 | TCP | Your SIP providers' IPs | You have SIP trunks using TLS |
| 25 | TCP | Anywhere | Email to fax is turned on (a fax domain is set) |
| 22 | TCP | Your own IPs only | You manage the server over SSH |

Everything else can stay closed.

- Keep port **80** open even with HTTPS turned on: Let's Encrypt renewals are checked over it.
- Providers often send fax media from different IPs than their SIP signalling. If they
  publish their media ranges, restrict 16384–32768 to those; otherwise allow it from anywhere.
- SIP is protected on the server as well: 5060/5061 only accept connections from the addresses
  of your SIP trunks, even if the external firewall leaves them open to everyone.

### Outbound (server → internet)

| Port | Protocol | Used for |
|---|---|---|
| 53 | UDP and TCP | DNS (SPF/DKIM checks, trunk hostnames) |
| 80, 443 | TCP | Operating system packages, GitHub (fax2email updates), Let's Encrypt |
| 25, 465, 587 or 2525 | TCP | Sending email through your SMTP server (whichever port it uses) |
| 5060, 5061 | UDP and TCP | SIP to your providers: registrations and sending faxes |
| 16384–32768 | UDP | Fax media to your providers |
| 123 | UDP | Time synchronisation (NTP) |

### Never open

| Port | Service |
|---|---|
| 5432 | PostgreSQL database |
| 8021 | FreeSWITCH control port |
| 8891 | OpenDKIM |

These only listen on the server's local address.

## Updating a server

```bash
sudo fax2email-update --check   # is there a new version?
sudo fax2email-update           # install the latest release
sudo fax2email-update 0.1.0     # install (or roll back to) a specific release
```

The updater puts the site in maintenance mode, backs up the code and database,
installs the release, runs database migrations, and brings the site back. If any
step fails, it restores the backup automatically. The last 5 backups are kept.

### Lost your Admin password. Add a new one below
Create a real admin with `php artisan fax2email:create-admin`.
