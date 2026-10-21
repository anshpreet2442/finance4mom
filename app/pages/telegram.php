<?php
declare(strict_types=1);
/** The person's side of Telegram: the Connections card (Settings), the dashboard prompt and the tiny status endpoint the card polls. */

/** The Connect Telegram card. States: not available, locked by plan, not connected (with a pending deep link), connected. */
function telegram_connect_card(array $u): string {
    $c = tg_cfg(); $uid = (int) $u['id'];
    if (!tg_ready($c)) { return '<p class="text-sm text-mute">Telegram is not set up yet' . ($u['role'] === 'admin' ? '. <a class="underline" href="' . e(url('/admin/telegram')) . '">Set it up in Admin</a>.' : '. Ask the administrator.') . '</p>'; }
    $fa = feature_access($u, 'telegram'); if (!$fa['ok']) { return '<p class="text-sm text-mute">' . e($fa['reason']) . '</p>'; }
    $l = tg_link_for($uid); $ai = feature_on($u, 'telegram_ai'); $aiLine = $ai ? badge('Telegram AI plugin: active', 'pos') : '<span class="text-xs text-mute">Want to chat with your advisor here? <a class="underline" href="' . e(tg_shop_url()) . '">Get the Telegram AI plugin</a>.</span>';
    if ($l) {
        $who = ($l['first_name'] ?: 'your Telegram') . ($l['tg_username'] ? ' (@' . $l['tg_username'] . ')' : '');
        $o = '<div class="flex items-start gap-3"><span class="mt-0.5 grid size-9 shrink-0 place-items-center rounded-full bg-lime/20 text-lime-dk">' . icon('check', 'size-5') . '</span><div class="min-w-0"><p class="font-medium">Connected to ' . e($who) . '</p><p class="text-xs text-mute">Since ' . e(date_label(substr((string) $l['linked_at'], 0, 10))) . '. Send <code>/stop</code> in the chat, or use the button below, to disconnect.</p></div></div>';
        if ($c['notify']) { $o .= '<form method="post" action="' . e(url('/do/telegram-prefs')) . '" class="mt-4 flex flex-wrap items-center gap-3">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(current_url()) . '"><label class="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" name="notify" value="1" class="size-4 accent-[var(--lime-dk)]"' . ((int) $l['notify'] ? ' checked' : '') . '> Remind me about bills in Telegram</label><button class="btn btn-sm">Save</button></form>'; }
        $o .= '<div class="mt-4 flex flex-wrap items-center gap-2"><a class="btn btn-dark btn-sm" href="https://t.me/' . e($c['username']) . '" target="_blank" rel="noopener">' . icon('send', 'size-4') . ' Open the bot</a>'
            . confirm_btn('Disconnect', 'telegram-disconnect', [], 'Disconnect Telegram?', 'The bot stops messaging you. You can connect again any time.', 'Disconnect', 'btn btn-ghost btn-sm text-neg') . '</div><div class="mt-3">' . $aiLine . '</div>';
        return $o;
    }
    start_session(); $t = $_SESSION['tg_link'] ?? null; if ($t && (int) $t['expires'] < time()) { unset($_SESSION['tg_link']); $t = null; }
    $o = '<p class="text-sm text-mute">Check your balance, mark bills paid and log expenses from Telegram. Takes about ten seconds.</p>';
    if ($t) {
        $left = max(1, (int) ceil(((int) $t['expires'] - time()) / 60));
        $o .= '<div class="mt-4 rounded-2xl bg-soft p-4" data-tg-wait data-url="' . e(url('/api/telegram')) . '"><p class="text-sm font-medium">Step 2: open Telegram and press <strong>Start</strong></p><p class="mt-1 text-xs text-mute" aria-live="polite">Waiting for you… this link works once and expires in about ' . $left . ' minute' . ($left === 1 ? '' : 's') . '.</p>'
            . '<div class="mt-3 flex flex-wrap gap-2"><a class="btn btn-dark" href="' . e($t['link']) . '" target="_blank" rel="noopener">' . icon('send', 'size-4') . ' Open Telegram</a>' . post_btn('Start over', 'telegram-connect', [], 'btn btn-ghost') . '</div></div>'
            . '<script>(function(){var el=document.querySelector("[data-tg-wait]");if(!el)return;var n=0,t=setInterval(function(){if(!document.body.contains(el)||++n>300){clearInterval(t);return}fetch(el.getAttribute("data-url"),{credentials:"same-origin",headers:{"Accept":"application/json"}}).then(function(r){return r.json()}).then(function(j){if(j&&j.linked){clearInterval(t);location.reload()}}).catch(function(){})},3000)})()</script>';
    } else {
        $o .= '<div class="mt-4">' . post_btn(icon('send', 'size-4') . ' Connect Telegram', 'telegram-connect', [], 'btn btn-dark') . '</div>';
    }
    return $o . '<div class="mt-3">' . $aiLine . '</div>';
}
/** A one-line invitation on the dashboard until the person connects (or dismisses it on this device). */
function telegram_dashboard_prompt(array $u): string {
    try {
        if (!tg_ready() || !feature_on($u, 'telegram') || tg_link_for((int) $u['id'])) { return ''; }
        return '<div class="mb-5 flex items-center justify-between gap-3 rounded-2xl border border-line bg-card p-4" data-tg-prompt><div class="flex min-w-0 items-center gap-3"><span class="grid size-10 shrink-0 place-items-center rounded-full bg-soft">' . icon('send', 'size-5') . '</span><div class="min-w-0"><p class="text-sm font-medium">Get your bills and balance in Telegram</p><p class="text-xs text-mute">Connect once, then just message the bot.</p></div></div>'
            . '<div class="flex shrink-0 items-center gap-1"><a class="btn btn-sm btn-dark" href="' . e(url('/settings') . '#connections') . '">Connect</a><button type="button" class="btn btn-ghost btn-sm" data-tg-dismiss aria-label="Hide this">' . icon('x', 'size-4') . '</button></div></div>'
            . '<script>(function(){var b=document.querySelector("[data-tg-prompt]");if(!b)return;try{if(localStorage.getItem("mf_tg_dismiss")==="1"){b.style.display="none";return}}catch(e){}b.querySelector("[data-tg-dismiss]").addEventListener("click",function(){b.style.display="none";try{localStorage.setItem("mf_tg_dismiss","1")}catch(e){}})})()</script>';
    } catch (Throwable $e) { log_error($e); return ''; }
}
function page_api_telegram(): void {
    if (!headers_sent()) { header('Content-Type: application/json'); header('Cache-Control: no-store'); } $u = current_user();
    if (!$u) { http_response_code(401); echo json_encode(['error' => 'sign in']); return; }
    $l = tg_link_for((int) $u['id']); echo json_encode(['linked' => (bool) $l, 'username' => $l['tg_username'] ?? null]);
}
