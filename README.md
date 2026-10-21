# finance4mom
this is finance management php webapp for mothers

## Optional features (configured by the administrator)

All credentials are entered in the admin area, stored encrypted on the server (key derived from your DB credentials, or set `MFNC_SECRET`), shown masked, and never sent to the browser. Environment variables override the stored values.

| Feature | Admin page | Environment variables |
|---|---|---|
| Email (SMTP or PHP `mail()`), templates, log, test send | Admin → Email | `MFNC_SMTP_HOST/PORT/USER/PASS/FROM` |
| Twilio SMS with payment links, template + live preview, log, test send | Admin → SMS | `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM` |
| AI advisor (Anthropic Messages API, server-side): model, system prompt, token prices, budgets, rate limits | Admin → AI agent | `ANTHROPIC_API_KEY`; `MFNC_AI_MOCK=1` runs everything against a fake model without a key |
| Free / paid plan / pay-as-you-go per feature, wallets, usage dashboards | Admin → Plans & usage | |

Landing page: `/welcome` (and `/` when signed out). Run the tests with `MFNC_DB_USER=... MFNC_DB_PASS=... php tests/run.php`. After changing Tailwind classes rebuild CSS with `cd tools && npm i && npx tailwindcss -i input.css -o ../assets/app.css --minify`.
