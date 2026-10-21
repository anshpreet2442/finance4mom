# Work queue (shared by Builder, Tester, Designer)

Status: `[ ]` todo · `[~]` in progress (add owner) · `[x]` done · `[!]` bug found, needs fix
Rule: edit only your own queue lines; append new items at the bottom of the right section. Commit small and often.

## Setup (shared)
- App served at http://127.0.0.1:8000 (php -S, 6 workers, MariaDB user mfnc/mfnc, MFNC_DEBUG=1). Test account mom@example.com / Password123! (admin, sample data loaded).
- Tests: `MFNC_DB_USER=mfnc MFNC_DB_PASS=mfnc php tests/run.php`
- Screenshots go to /tmp/claude-0/-home-user-finance4mom/971b8b9a-ce99-5b2a-921e-b9b2f006723f/scratchpad/shots/ (Playwright, chromium at /opt/pw-browsers/chromium, NODE module path: $(npm root -g)/playwright)

## A. Fixes
- [x] A1 (builder) Collation bug: "Illegal mix of collations (utf8mb4_general_ci / utf8mb4_unicode_ci)" (db.php) on real DBs, esp. /admin/billing. Make every table/column/connection collation consistent (explicit COLLATE in schema, SET NAMES ... COLLATE, migration that converts existing tables), no mixed-collation comparisons/JOINs.
- [x] A2 (builder) Inspect /admin/billing end to end (all actions, forms, empty/error states) and fix errors
- [ ] A3 Sweep every page desktop + mobile for UI/UX bugs (overflow, empty space, tiny chart labels, a11y, focus, contrast, dark mode), fix them
## B. Features
- [ ] B1 SwipeRow (vanilla JS port, assets/swiperow.js + css) on mobile list rows: transactions, bills, dues, goals, accounts. Delete/archive/pay actions via existing POST actions + CSRF
- [ ] B2 Dock (vanilla JS port) desktop only, user pins pages, persisted per user; pin/unpin UI
- [ ] B3 Email system: send email on new payment, admin toggle; SMTP settings in admin; templates
- [ ] B4 Twilio SMS: admin toggle + credentials, payment-link alerts, editable default template with {parameters}
- [ ] B5 Landing page (/ for guests, e.g. /welcome) using StarBorder (vanilla JS port) and other components
- [ ] B6 AI agent ("finance advisor/CA") with Claude API: onboarding Q&A -> context, agent suggests its own name, user names it, DiceBear avatar, tools to read app data, rich result components, multi-step calcs, live previews, actions with undo page, admin edits system prompt + model
- [ ] B7 Admin billing: make features (dock, AI, etc.) free/paid/pay-as-you-go; AI token metering, per-user budget, top-up based limits
## C. Mobile UI
- [ ] C1 Improve mobile design across all pages (spacing, tap targets >=44px, bottom nav, sheets, safe-area)
## D. Designer proposals (Designer appends; Builder picks up)

## E. Tester bugs (Tester appends; Builder fixes)
- [!] (A3/C1) Mobile horizontal page scroll on /accounts (scrollWidth 401>390) — 390px touch, light/dark: account `article.card.lift.flex.gap-4.p-5` is 11px wider than viewport. Expected no h-scroll. Shot: /tmp/claude-0/-home-user-finance4mom/971b8b9a-ce99-5b2a-921e-b9b2f006723f/scratchpad/shots/tester/vp_accounts.png
- [!] (A3/C1) Mobile horizontal page scroll on all /admin* pages (399>390) — the `.jelly-radio` admin tab nav is 399px wide and its chips extend to x=610 without being its own scroll container (overflow-x:auto needed, page must not scroll). Repro: 390px, /admin or /admin/billing. Shot: .../shots/tester/vp_admin_billing.png
- [!] (A3) /analysis has no <h1> (h1 count 0) — a11y/heading order; other pages have one.
- [x] (A1/A2 verified by tester) Legacy DB with mixed general_ci/unicode_ci tables (legacytest DB, port 8077): all 20 pages incl. /admin/billing render with no 1267 errors; admin billing validation (0/negative/3-decimals/huge amount, bad dates, unknown user, day 31, bad months, XSS titles) all handled and escaped.
- [!] (A2, minor) do_admin_user_billing with a nonexistent user id returns success flash "Dues are enforced for this person again" — should say user not found (app/actions.php ~283).
- [!] (A2, minor) /admin/billing Charges filter chips (All/Unpaid/Paid/Waived/Cancelled) overflow the card's right padding at 1280px (Cancelled chip ends ~7px past content edge); charge row buttons "Waive fee" vs "Waive" are ambiguous — label "Waive charge". Shot: .../shots/tester/billing_d.png
- [!] (data, low) parse_money() strips ALL commas, so a de-DE/fr-FR user typing "12,50" gets 1250.00 instead of 12.50 (app/helpers.php parse_money). Consider locale-aware decimal comma when locale uses ',' as decimal.
- [x] (B2/B3/B4 tester pass 1) Dock: renders desktop only (hidden at 390px), magnify/hover/right-click menu/arrow-keys OK, pin/unpin persisted, bad page keys rejected. Email: SMTP mock end-to-end OK (test mail, tx.payment receipt, MIME base64, HTML escaped, secret never echoed). SMS: Twilio mock OK (test, validation, template save/reset, token never echoed).
- [!] (B2) Default Dock includes "AI advisor" -> /ai which is 404 until B6 lands; hide/skip pins whose page does not exist yet (or build B6). Repro: log in at 1280px, click last dock icon.
- [!] (A3, low) /login, /register have no <link rel="icon">, so browsers request /favicon.ico -> 404 console error on every guest page. Add the icon link (or serve /favicon.ico) in the guest layout (app/ui.php).
- [!] (data, low) Names/descriptions accept control characters: profile name "Evil\r\nBcc: x" is stored with CR/LF and shows up in emails/SMS greetings ("Hi Evil\r\nBcc:,"). in_str() should collapse \r\n\t/control chars to a space for single-line fields (app/helpers.php in_str). Headers themselves are safe (clean_header).
- [!] (B3/B4, low) email_html() auto-links include trailing ")" and the &quot; of a quote in the href; and unknown {params} in SMS/email templates are silently left raw (admin gets no warning on save). Shot: n/a.
