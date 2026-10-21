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
