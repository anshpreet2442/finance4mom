# Moms Finance (PHP + MySQL)

Personal finance and savings manager: income, expenses, bills, yearly obligations, savings goals, house and
big-ticket purchase planning, a monthly planning workflow, reports and rule-based insights, plus an admin panel.
Plain PHP 8.1+, MySQL 8 / MariaDB 10.5+, Tailwind CSS (pre-built) and Lucide icons. No framework, no Composer, no Node on the server.

## Install (any PHP host: cPanel, XAMPP, MAMP, VPS)

1. Create an empty MySQL database and a user for it (or use `root` locally; if the user may create databases, even that is automatic).
2. Upload this folder. Edit the top of **`config.php`** (or set `MFNC_DB_*` environment variables):
   ```php
   define('DB_HOST', '127.0.0.1');  define('DB_NAME', 'momsfinance');
   define('DB_USER', 'root');       define('DB_PASS', '');
   define('TABLE_SUFFIX', '_mfnc');
   ```
3. Open the site. **`config.php` creates every table automatically** on the first request (and upgrades them when the schema version changes).
   All tables carry the suffix, e.g. `users_mfnc`, `transactions_mfnc`, so they can share a database with other software.
4. You land on **/register**. **The first account becomes the administrator.** Later sign-ups are regular users; the admin can close registration.

Local trial without Apache: `php -S localhost:8000 router.php`

### Pretty URLs (no `.php`)
* **Apache**: the included `.htaccess` sends every non-file request to `index.php` (needs `mod_rewrite` and `AllowOverride All`). It also blocks `config.php`, `app/`, `tools/`, `docs/`. Works in a sub-folder too (`/finance/transactions`).
* **Nginx**: see `nginx.conf.example`.

### Production checklist
* Serve over **HTTPS** (the session cookie becomes `Secure` automatically).
* Keep `APP_DEBUG` off (default). Errors are written to the PHP error log.
* Use a database user that only has rights to this database.
* Back up the database, or use Settings → *Full backup (JSON)* per user.

## Phone app (PWA), offline and the ember theme
* **Installable**: open the site on a phone and use *Install app* (Chrome/Android) or Share → *Add to Home Screen* (iPhone). It needs HTTPS (or `localhost`).
* **Offline**: pages you have opened open without a connection; unvisited pages show a friendly offline screen. Transactions added offline are stored on the device and sent to `/sync` as soon as a connection returns (Background Sync where the browser supports it, otherwise when the app is open). Each change carries a client id, so a retry can never create a duplicate. Offline mode only *creates* records; it never edits or deletes.
* **Expressive reload**: animated launch splash, pull-to-refresh on touch screens, and a "new version is ready" toast when you deploy an update (the service worker version changes automatically with the assets).
* **Theme**: the orange *ember* palette is the default on phones and tablets; Settings → Colours switches every screen to ember or lime. Dark mode works everywhere (toggle in the top bar).
* **Intro screen**: after 5 distinct days of use it appears when the app is opened, and at most once more that same day. A reload before dismissing does not use it up.
* **Toasts**, **Analysis** (bubble chart of spending by category, day-by-day, weekday pattern, top payees, payment methods) and **End credits** (`/credits`) are part of the same design.

## Accounts, savings plan and passkeys
* **Accounts** (`/accounts`): add your own bank, cash or wallet accounts, label each one (*Income from job*, *Savings account*, *Everyday spending*, *Other*, plus your own wording) and see each as a liquid **Slosh gauge** showing its share of your cash. *Set balance* reconciles to what the bank shows by recording the difference as a balance correction (not income or expense); *Move money* is a transfer.
* **This month's savings plan**: every goal, purchase and yearly bill gets the amount to move this month to stay on schedule, shared out in **priority order** (high first, then the nearest date) from your typical monthly saving (average of income − expenses over the last 3 full months). Each shows how much is saved so far (gauge), how much has already moved this month, and a *Move money now* button. **Plan ahead** adds future things (flights, repairs, gifts, a purchase) with a date and a price; an optional *Keep the savings in* account says where the money lives.
* **Passkeys** (Settings → Passkeys, and *Sign in with a passkey* on the login page): WebAuthn with no extra libraries. ES256 and RS256, user verification required, single-use 5-minute challenges, origin and RP-ID checks, signature-counter clone detection. Needs HTTPS or `localhost`; set `MFNC_APP_URL` in production so the relying-party ID is fixed. Attestation statements are not verified (only the public key is kept).
* **Motion**: pages cross-fade with the View Transitions API, popups grow out of the button that opened them and shrink back, cards reveal as they scroll in, numbers count up, bars and lines draw in, gauges slosh, and a quiet `/api/pulse` refresh keeps the balance, badges and reminders live. All of it is skipped under *reduce motion*.

## Single-page navigation and jelly tabs
`assets/spa.js` turns the app into a single-page app without changing the HTML: links and forms still work with JavaScript off, but with it on the next page is fetched and only the main content, top bar, menu, banner and pill nav are swapped (with a View Transition), history/back/scroll are handled, and the sidebar, dialogs, toasts, live badges and service worker are never reloaded. Requests carry `X-Mfnc-Spa: 1`; PHP answers redirects with `204` + `X-Mfnc-Redirect` so the router can follow them (or send the browser to the PayU page). Downloads (CSV/JSON backup), sign-in/out, the credits page and anything unexpected fall back to a normal navigation. Page scripts re-initialise on the `mfnc:page` event.
Tab groups use the JellyRadio component (`assets/jelly.js`, `jelly()` in `app/ui.php`): spring-animated chips with keyboard support (arrows, Home, End). Items with a link act as navigation tabs.

## Dues & payments (PayU gateway)
The administrator posts a one-off payment or a monthly charge to a person; they pay from **Dues & Payments** through the gateway's secure page.
Set these (environment variables are best; `config.php` reads them):
```
PAYU_BASE_URL=https://YOUR-GATEWAY/api/v1
PAYU_API_TOKEN=umpi_live_...          # server-side only, never stored in the database or logged
PAYU_WEBHOOK_SECRET=...               # the app's webhook secret from the gateway portal
MFNC_APP_URL=https://finance.example.com   # public address, used for payment return links
```
Then, in the gateway portal, set the app's **Webhook URL** to `https://finance.example.com/webhooks/payu` and use *Admin → Billing → Test connection* (calls `/ping` and `/diagnose`).
How it follows the gateway guide: payments are created server-side only; a charge becomes **paid only from a signature-verified webhook or a server-side status lookup**, never because the browser returned; webhooks are de-duplicated on `X-UMPI-Delivery-Id`, amount and currency are checked, `payment.failed` is not final, unknown events are acknowledged with 200; an open checkout for the same charge is reused because `POST /payment/init` is not idempotent.

**If a payment is late** (all configurable in *Admin → Billing*, shown to the payer in plain words): reminders the day after the due date → optional one-time late fee after a grace period (flat or %) → *read-only* after N days overdue → *locked to the Dues page* after M days. Even then the person can still pay, download their data and sign out. The admin can waive a late fee or a charge, record a cash payment, or exempt someone. Admins are never restricted.

## Pages
`/` dashboard · `/accounts` · `/analysis` · `/dues` · `/transactions` · `/income` · `/expenses` · `/budget` (monthly planner) · `/goals` · `/house` · `/big-purchases` · `/bills` · `/yearly` · `/reports` · `/settings`
Admin (administrators only): `/admin` overview · `/admin/users` · `/admin/billing` · `/admin/settings` · `/admin/activity` · `/admin/system`

## Admin panel
Create, disable/enable, promote/demote, reset the password of (a temporary one is shown once) and delete users; open or close registration;
set the default currency/locale and a site-wide notice; read the audit log; check PHP/MySQL versions and table sizes.
Admins see account details and counts, **never anyone's financial records**. The last active administrator cannot be disabled, demoted or deleted.

## Accounting rules the code enforces
Money is stored as integer minor units (paise, `BIGINT`); parsing never uses floats.
Transfers are neither income nor expense · savings contributions are a separate ledger that only *reserves* cash (spendable = cash − reserved) ·
paying a bill creates one expense and releases any money set aside for it (no double deduction) · unpaid bills are liabilities, not expenses ·
planned items never count as cash · refunds reduce net spending and cannot exceed the original · a plan cannot be confirmed above available funds
(re-checked on the server) and suggestions are never applied automatically.

## Tests
```bash
MFNC_DB_PORT=3306 MFNC_DB_USER=root php tests/run.php
```
228 checks (engine maths, accounting rules, workflows, user isolation, login throttle, admin protections, billing and enforcement, the gateway flow against a mock PayU server, webhook signatures and replay, offline-sync idempotency, the intro rule, passkey registration and sign-in with a simulated authenticator). They use their own database
`momsfinance_test` and suffix `_mfnt` and never touch real data.

## Changing the design
`assets/app.css` is pre-built. To change styles: `cd tools && npm install && npm run build:css` (Tailwind scans `app/**/*.php`).
New icons: use `icon('name')` in PHP, then `npm run icons` copies the referenced Lucide SVGs into `app/icons/`. App icons: `php tools/make-icons.php`.

## Security notes
Passwords: `password_hash` (bcrypt, 72-byte limit enforced) · sessions: HttpOnly, SameSite=Lax cookie, id regenerated at login · CSRF token on every POST ·
failed-login throttle (5 per email / 30 per IP per 15 minutes) · all SQL parameterised · every query scoped to the signed-in user, referenced ids verified ·
output escaped · CSV export neutralises spreadsheet formulas · `X-Frame-Options: DENY` and other headers.
Not included: refunds through the gateway (do them in its portal), email/SMS payment reminders (reminders are in-app), email verification, password-reset email (an admin can reset a password), 2FA, a strict Content-Security-Policy.
No bank connection exists or is claimed.

## Optional features (configured by the administrator)

All credentials are entered in the admin area, stored encrypted on the server (key derived from your DB credentials, or set `MFNC_SECRET`), shown masked, and never sent to the browser. Environment variables override the stored values.

| Feature | Admin page | Environment variables |
|---|---|---|
| Email (SMTP or PHP `mail()`), templates, log, test send | Admin → Email | `MFNC_SMTP_HOST/PORT/USER/PASS/FROM` |
| Twilio SMS with payment links, template + live preview, log, test send | Admin → SMS | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM` |
| AI advisor (Anthropic Messages API, server-side): model, system prompt, token prices, budgets, rate limits | Admin → AI agent | `ANTHROPIC_API_KEY`; `MFNC_AI_MOCK=1` runs everything against a fake model without a key |
| Free / paid plan / pay-as-you-go per feature, wallets, usage dashboards | Admin → Plans & usage | |
| Telegram bot and Telegram AI plugin: token, webhook, persona, reminders, log | Admin → Telegram | `TELEGRAM_BOT_TOKEN`, `TELEGRAM_WEBHOOK_SECRET`, `TELEGRAM_API_BASE` (tests) |

### Telegram bot (setup)

Two features are registered in the plan system (`telegram` free by default, `telegram_ai` paid by default, so the plugin shop and Plans page list them).

1. In Telegram open **@BotFather**, send `/newbot`, pick a name and a username ending in `bot`. Copy the token it gives you.
2. Your site needs a **public https address**: set `APP_URL` in `config.php` (or `MFNC_APP_URL`), e.g. `https://finance.example.com`. Telegram refuses plain http.
3. Admin → Telegram: paste the token and the bot username, switch it on, **Save**, then press **Register webhook**. That calls `setWebhook` with `{APP_URL}/webhooks/telegram`, a generated `secret_token` (stored encrypted, checked on every request in constant time) and `allowed_updates`, and publishes the command list. **Check now** shows the webhook address, waiting updates and Telegram's last delivery error.
4. People connect in Settings → Connections → **Connect Telegram**: a single-use, 15-minute, unguessable link opens `t.me/<bot>?start=<token>`; pressing Start links the chat to the account (one chat per account, one account per chat; `/stop` or the Disconnect button unlinks).
5. Commands: `/balance`, `/bills` (Mark paid with a confirm step), `/add` or just "spent 450 on groceries" (confirm, Undo), `/goals`, `/report`, `/menu`, `/notify on|off`, `/forget`, `/help`. Money changes use the same service functions as the web forms and are audit-logged.
6. **Telegram AI plugin**: free text goes to the person's own advisor (their name and setup profile), in an editable CA-friend persona (Admin → Telegram, with reset), with inline buttons the model may request from a whitelist, stat cards, monospace tables, PNG charts (PHP GD), receipt photos, and Apply / Undo for every change. Usage counts against the same AI budget or wallet. Chat memory is the last N turns plus a rolling summary; `/forget` wipes it; message text is never written to the log.
7. Reminders in Telegram are opt-in per person; run `php tools/telegram_cron.php` daily from cron (or press "Send due reminders now").

Local testing without Telegram: `php -S 127.0.0.1:8097 tests/telegram_mock.php`, then start the app with `TELEGRAM_API_BASE=http://127.0.0.1:8097 MFNC_AI_MOCK=1` and post crafted updates to `/webhooks/telegram` with the `X-Telegram-Bot-Api-Secret-Token` header. `tests/run.php` starts the mock itself. Charts need the GD extension; DejaVu fonts ship in `assets/fonts`.

Landing page: `/welcome` (and `/` when signed out). Run the tests with `MFNC_DB_USER=... MFNC_DB_PASS=... php tests/run.php`. After changing Tailwind classes rebuild CSS with `cd tools && npm i && npx tailwindcss -i input.css -o ../assets/app.css --minify`.
