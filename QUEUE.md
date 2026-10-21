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
- [x] A3 (builder) Sweep every page desktop + mobile for UI/UX bugs (overflow, empty space, tiny chart labels, a11y, focus, contrast, dark mode), fix them
## B. Features
- [x] B1 (builder) SwipeRow (vanilla JS port, assets/swiperow.js + css) on mobile list rows: transactions, bills, dues, goals, accounts. Delete/archive/pay actions via existing POST actions + CSRF
- [x] B2 (builder) Dock (vanilla JS port) desktop only, user pins pages, persisted per user; pin/unpin UI
- [x] B3 (builder) Email system: send email on new payment, admin toggle; SMTP settings in admin; templates
- [x] B4 (builder) Twilio SMS: admin toggle + credentials, payment-link alerts, editable default template with {parameters}
- [x] B5 (builder) Landing page (/ for guests, e.g. /welcome) using StarBorder (vanilla JS port) and other components
- [x] B6 (builder) AI agent ("finance advisor/CA") with Claude API: onboarding Q&A -> context, agent suggests its own name, user names it, DiceBear avatar, tools to read app data, rich result components, multi-step calcs, live previews, actions with undo page, admin edits system prompt + model
- [x] B7 (builder) Admin billing: make features (dock, AI, etc.) free/paid/pay-as-you-go; AI token metering, per-user budget, top-up based limits
## C. Mobile UI
- [x] C1 (builder; with designer polish.css) Improve mobile design across all pages (spacing, tap targets >=44px, bottom nav, sheets, safe-area)
## D. Designer proposals (Designer appends; Builder picks up)
- Designer notes: all design work lives in assets/polish.css (hand-written; Tailwind CLI is not installed offline, so NEW utility classes cannot be generated, use polish.css classes such as .stat-trio, .empty, .skel, .switch, .meter, .status). It loads after app.css/ext.css. Section 10 of polish.css pre-styles upcoming screens: AI chat (.ai-chat .ai-msg(.me) .ai-bubble .ai-avatar .ai-typing .ai-compose .ai-suggest .ai-card(.preview) .ai-steps .ai-tool), undo page (.undo-row .undo-pill), billing/settings (.plan-card(.is-featured) .plan-price .switch>input+i .meter(.warn/.over)>i[--w] .status(.ok/.warn/.bad) .sms-preview .tpl-token). Builder: use these class names for B3/B4/B6/B7 so screens feel native; empty_state($title,$text,$ctaHtml,$iconName) now takes a CTA button.
### Quick wins
- [x] D1 (designer) polish.css: tokens (radius/elevation/ease), focus ring (AA blue), light + Ember `--mute` raised to AA (was 3.4:1), tabular numbers, text-wrap balance, 44px touch targets + 16px inputs on touch, button busy state `[aria-busy=true]`, select/date styling, `:user-invalid` input state with `.field-msg`
- [x] D2 (designer) Dashboard chart was ~250px wide (axis labels ~4px). Overview card re-laid out: 3 stat tiles above a full-width chart; SVG axis text sized in viewBox units (polish.css s6). Files: app/pages/dashboard.php, polish.css
- [x] D3 (designer) Dialogs become bottom sheets on phones (grab handle, safe-area, slide up/down); toast moves above the pill on phones; #main bottom padding clears the pill
- [x] D4 (designer) empty_state() warm icon tile + optional CTA; Builder: pass a CTA at call sites (18 uses) e.g. empty_state('No bills yet','...','<a class="btn btn-primary" href="/bills">Add bill</a>','bell')
- [x] D5 (designer) m-sheet `lg:mt-0/bg-transparent/shadow-none` utilities never applied (component CSS loaded later) so admin/dues/accounts desktop had a card overlapping the heading; fixed. Admin tab strip gets padding/edge fade so the active pill is not clipped; `.jelly-radio` scrolls inside itself; card headers wrap on narrow screens
- [x] D6 (designer) list/table rows rise in with a 30ms stagger (first 8), row exit class `.row-exit`, sidebar nav fades at the bottom when it scrolls and compresses on <860px-high laptops
- [x] D7 (Builder) (builder done) /admin/billing at 320px still overflows by ~74px: header action buttons "One-off payment / Monthly charge" (div.flex.shrink-0) — allow wrap or stack under the title
- [x] D8 (Builder) (builder done) /analysis needs an <h1> (tester bug) — use page_header or `<h1 class="sr-only">`
### Medium
- [ ] D9 (Builder) Inline validation + masks: add `data-mask="inr"` to amount inputs (format 1,23,456 while typing, keep raw digits in a hidden/submitted value), `inputmode=decimal`, and show `<p class="field-msg">` under invalid fields (class `.field-msg` is styled; `.input:user-invalid` already shows red ring). Files: app/ui.php form_fields(), assets/app.js
- [ ] D10 (Builder) Money in/out not by colour alone: transaction rows show sign + category icon; add `.tx-dir.in/.out` arrow chip next to amount in the desktop table and on the dashboard "Recent" list (all rows there use the same arrow icon: use category icon like /transactions does)
- [ ] D11 Skeletons: SPA navigations show `.skel skel-block` placeholders in #main until the fetch resolves (spa.js) instead of only the top progress bar
- [ ] D12 Settings > Appearance: font size S/M/L (root font-size 15/16/18px) for older users; Hindi/regional-language labels later
- [ ] D13 Friendly language pass: "Liability", "Unallocated", "Reserved", "Outstanding payments" get a one-line plain explanation or an (i) popover; keep jargon out of headings
- [ ] D14 Date inputs: replace native mm/dd/yyyy with a day-first pill picker (Today / Yesterday / Pick) on the add-transaction sheet; native picker stays as the fallback
### Ambitious
- [ ] D15 Self-host Inter variable (woff2, `font-display: swap`, subset latin) in assets/fonts + preload; needs a network fetch the sandbox lacks
- [ ] D16 Tailwind rebuild in CI so polish.css utilities fold back into app.css and `--mute` etc. move into tools/input.css tokens
- [ ] D17 Charts: touch tooltips + tap-to-pin value labels, crosshair on line charts, accessible data table toggle ("View as table")
- [ ] D18 Pull-to-refresh polish: elastic rubber-band + haptic (navigator.vibrate(8)) at threshold; spring physics for bottom sheet drag-to-dismiss
- [ ] D19 Onboarding: 3-question wizard with progress meter and celebratory confetti on first goal created (respect reduced motion)
- [ ] D20 Dock + swipe polish (Builder's ext.css): magnify neighbours by distance (macOS style) and a one-time swipe coach-mark on the first list row

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
- [x] (B1 tester pass 1) SwipeRow @390 touch via CDP touch events: left-swipe reveals Delete, tap-away closes, vertical/diagonal drags do not trigger, full swipe collapses row + Undo toast, Undo restores, 5s later the POST commits (DB row gone, balance updated), tapping action button after partial swipe works. Present on transactions(40)/bills(10)/goals(3)/accounts(3); /dues has no rows for demo user (untested). Shots: .../shots/tester/swipe_*.png
- [x] (A3 re-check) /admin* mobile overflow FIXED (designer). /accounts at 390px still scrollWidth 393>390 (3px, was 11) — no visible un-clipped element found; likely a transformed/absolute element inside the card or swipe row; still [!] above until 0.
- [x] (builder) Fixed from E: /accounts + admin h-scroll (html/body overflow-x clip + wrapping card actions), /analysis h1, user-billing unknown id, Waive labels, filter chips scroll, locale-aware parse_money (de/fr comma), control chars stripped (in_str / new in_line for names, titles), favicon on guest pages, dock hides ai/plans when unavailable, email autolink punctuation, unknown template {params} rejected on save. Tests added.
