# Waitlist backend

## Current behavior

1. Both email forms POST to `api/waitlist.php`. A successful first submission saves the email and reserves one of 10,000 spots before the name screen opens.
2. Continue saves the first name, then the professional title and 1–3 skills, including custom niches. A six-digit email verification code is required before showing the card. The existing card layout, download, and sharing controls remain in place.
3. The server supplies the member number and a random public referral code. It stores the referring member when a valid `?ref=` link was used. Referrals do not move people ahead in the waitlist in this version.
4. Resend delivers verification codes. A valid code sets `email_verified_at` on the existing entry without allocating another number. Welcome/invitation emails, cross-device recovery, accounts, and public administrator endpoints are not implemented.

Numbers start at 1. The `waitlist_counter` row is locked within an InnoDB transaction, which serializes new allocations. Number 10,000 is the last issued number. Duplicates and rolled-back requests do not advance the counter. Deleting an individual record does **not** renumber members or reuse their number. A deliberate full local reset restarts at 1.

`waitlist_entries` stores email, name, title, number, referral code/referrer, private token hash, creation/update times, profile completion time, and email verification time. `waitlist_skills` stores ordered skills. `waitlist_email_verifications` stores one current challenge per member, keyed code hash, expiry, attempts, send limits, and provider message ID. Codes are not stored in plaintext. `waitlist_rate_limits` temporarily stores hashed rate-limit buckets; no raw IP addresses are saved there. Times are UTC.

## Local setup

Requirements: PHP 8.1+ with `pdo_mysql`, `mbstring`, and `curl`, Composer, and MariaDB 10.4+ or MySQL 8.0.16+. XAMPP on this PC provides PHP 8.2 and MariaDB 10.4.

1. Start MySQL in XAMPP and create `magasaka_waitlist` in phpMyAdmin.
2. Run `C:\xampp\php\php.exe scripts\setup-local.php` from the repo root. This only adds schema/configuration; it never clears existing entries. If necessary, set `WAITLIST_ADMIN_USER` / `WAITLIST_ADMIN_PASSWORD` in your terminal first. The setup creates an application user with SELECT, INSERT, UPDATE, and DELETE access to this database only.
3. Run `composer install` and configure Resend below. Re-run setup when pulling this change to add the verification table without clearing entries.
4. Run `C:\xampp\php\php.exe -S 127.0.0.1:4173 -t magasaka-waitlist-fullquality` and open `http://127.0.0.1:4173/magasaka-waitlist.html`.

The PHP built-in server is a local preview. Apache is not required for that URL; it can remain running for phpMyAdmin. A Python server or VS Code Live Server can display the frontend but cannot execute PHP.

For manual setup, select the intended database and import `database/schema.sql` in phpMyAdmin. Copy `backend/config.example.php` to `backend/config.local.php` and supply a database user, password, and a random application key of at least 32 characters. Keep that file private and outside the public web folder. It is excluded from Git.

## Resend email verification

Copy `backend/resend.example.php` to `backend/resend.local.php` and fill in the private settings. This file is ignored by Git and stays outside the public web root. The official `resend/resend-php` SDK is installed through Composer; never put the key in HTML or `waitlist-config.js`.

| Environment variable | PHP configuration key | Purpose |
| --- | --- | --- |
| `RESEND_API_KEY` | `resend_api_key` | Sending-only API key |
| `RESEND_FROM` | `resend_from` | Sender on a verified domain; local tests may use `Magasaka <onboarding@resend.dev>` |
| `RESEND_TEST_RECIPIENT` | `resend_test_recipient` | Restrict local tests to one inbox; empty for production |
| `RESEND_VERIFICATION_TEMPLATE` | `resend_verification_template` | Published template ID or alias; configured alias is `magasaka-waitlist-verification` |

The published template expects `CODE` (string, preserving leading zeros) and `EXPIRES_MINUTES` (number, 10). Its HTML lives in Resend, not in the application. If no template is configured, the app sends a plain-text code. Private environment values override file values. An explicit `WAITLIST_CONFIG_FILE` does not inherit `resend.local.php`, keeping isolated tests separate.

Without a verified domain, Resend allows its test sender only to the Resend account owner's email address. A Gmail inbox is a recipient, not a verified sending domain. Before public signup, verify the sending domain in Resend, set `RESEND_FROM`, remove the local recipient restriction, and confirm real delivery from the deployed application. Sending a message is not proof that it arrived in the inbox.

Codes expire 10 minutes after creation. Five wrong guesses invalidate the challenge; resends have a 60-second cooldown and a five-send limit per email per hour. Sending and verification also have per-IP limits. The server hashes codes using the private application key, consumes a successful challenge, and keeps the existing member number. Reopening a current sent challenge does not send a duplicate message. Transport failures retry the same payload and idempotency key; only connection failures, HTTP 429, and server errors are retried. Provider errors never produce a success card.

Email verification applies to completed existing entries too. Unverified email submissions still reserve numbers; this change does not reclaim abandoned spots. Do not rotate `WAITLIST_APP_KEY` casually: outstanding verification codes use it and would become invalid.

## Static previews and deployment

Deploy the PHP API and its sibling private `backend/` directory on PHP-capable hosting. Run `composer install --no-dev` in the repository root to install `vendor/`. Set the web document root to `magasaka-waitlist-fullquality/`, never to the entire repository. Create/import the production database separately; do not upload local test records or local credentials.

Configuration can come from `backend/config.local.php` or environment variables (environment variables take precedence):

| Variable | Purpose |
| --- | --- |
| `WAITLIST_DB_HOST` / `WAITLIST_DB_PORT` | Database connection address |
| `WAITLIST_DB_NAME` | Database name |
| `WAITLIST_DB_USER` / `WAITLIST_DB_PASSWORD` | Application account with data access only |
| `WAITLIST_APP_KEY` | Random secret, at least 32 characters, for rate-limit buckets and verification codes |
| `WAITLIST_ALLOWED_ORIGINS` | Comma-separated exact permitted frontend origins; no wildcard |
| `WAITLIST_CONFIG_FILE` | Optional path to a private PHP configuration file |

For a frontend on another host, set `apiUrl` in `waitlist-config.js` to the HTTPS endpoint and allow the frontend's exact origin on the API. Same-origin deployment needs no extra origins. For reverse proxies, configure the public frontend origin explicitly if PHP sees an internal HTTP scheme. Use HTTPS for both frontend and API in production. A partner's preview must permit outbound requests to the API; HTML still renders when an API is unavailable, but submissions correctly fail.

Set `publicPageUrl` in the frontend config to the live HTML page URL so shared referral links use the public domain. Neither the API nor a database is currently hosted by the local setup. A partner does not need a local database once a shared test API is deployed. Keep testing and production databases separate.

For a deliberate frontend-only design preview on localhost, set `previewMode: true` in `waitlist-config.js`. Only a missing/non-JSON API response may then use demo data. Demo verification uses `000000`, sends no email, and labels the result as a design preview. The default is `false`; real database or email failures never silently become successful signups. Keep this setting false in shared/production configuration.

## API contract

Only `POST` and CORS `OPTIONS` are supported. Responses are JSON and `Cache-Control: no-store`. POST requires `Content-Type: application/json` and an `X-Waitlist-Token` header containing a 64-character hexadecimal random browser key. Requests are limited to 8 KiB.

The frontend generates 32 random bytes and stores the key **before** submitting the email. The backend stores only its SHA-256 hash. This makes retrying a timed-out request safe: the same email/key resumes the same entry rather than consuming another slot. A different key cannot view or overwrite an existing email's data. The key itself is not proof of email ownership; the separate code confirms ownership. Cross-device recovery is future work; clearing browser storage loses access to that signup until recovery exists.

| Action | JSON fields | Result |
| --- | --- | --- |
| `join` | `email`, optional `referredBy` | Reserves a number, or resumes the entry when the key matches |
| `name` | `email`, `name` | Saves the first name of the authenticated entry |
| `complete` | `email`, `name`, `title`, `picks` | Saves the full profile, without verifying email |
| `send_code` | `email`, optional `resend: true` | Sends/resumes a code after profile completion; returns `verification: {sent, resendAfter}` |
| `verify` | `email`, `code` (six-character string) | Verifies email and returns the same member number/card data |

Success: `{ "ok": true, "created": true/false, "entry": { "email", "name", "title", "picks", "no", "ref", "referredBy", "complete", "verified" } }`.

Errors include 422 validation/invalid or expired code, 409 `email_exists` or `waitlist_full`, 401 invalid browser key, 429 rate/attempt/send limits, and 503 database or email-provider failure. Duplicate-email responses do not expose the existing name, skills, member number, or key. Per-IP minute limits are 20 joins, 120 profile requests, 10 code requests, and 30 verification attempts. CORS exposes `Retry-After` for allowed preview origins. Configure trusted proxy handling or edge limits if deploying behind a proxy; the application deliberately does not trust client-supplied forwarding headers.

Server validation, prepared statements, transaction locking, hashed browser keys/codes, request limits, and explicit CORS rules are included. Verification does not add cross-device login or a public lookup of registered profiles.

## Verification

Run from the repo root:

```powershell
& C:\xampp\php\php.exe tests\waitlist.php
& C:\xampp\php\php.exe tests\verification.php
```

The integration suite creates a randomly named `magasaka_waitlist_test_*` database, uses synthetic `example.com` addresses, then drops only that temporary database. It never resets the real `magasaka_waitlist` database. It tests numbering, retries, duplicate emails, Unicode names, profile validation, private updates, referrals, rate limits, HTTP/CORS behavior, and eight concurrent attempts for the last spot. Set `WAITLIST_TEST_ADMIN_USER` / `WAITLIST_TEST_ADMIN_PASSWORD` if your local administrator differs.

The verification suite also uses an isolated database. It tests expiry, attempts, resend limits, code consumption, preserved numbering, safe delivery retries, template variables, and SDK request formatting with an injected/mock mail transport. Automated tests send no real email.

The optional `--keep-browser-fixture` flag on `tests/waitlist.php` retains an empty test database and writes its private configuration to `.local/waitlist-test-config.php`. Use `WAITLIST_CONFIG_FILE` to run a separate preview against that fixture, never the real database. Add mail configuration only when explicitly testing delivery to an authorized inbox. Remove the temporary database when browser testing is finished.

## Reset local test entries

To deliberately delete **all** entries in the configured local database and restart at #1:

```powershell
& C:\xampp\php\php.exe scripts\reset-local-waitlist.php --database=magasaka_waitlist --confirm-delete-all
```

This command requires an explicit matching database name, the deletion flag, and a local database address. It clears entries, skills, and rate-limit buckets and resets the counter in a transaction. It is intended only for disposable local test data. It does not delete the database or change any other application's data. Without the confirmation arguments, it deletes nothing.

## Implementation references

- [MariaDB transactional row locking](https://mariadb.com/docs/server/reference/sql-statements/data-manipulation/selecting-data/for-update)
- [PHP prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php)
- [PHP timing-safe comparisons](https://www.php.net/hash_equals)
