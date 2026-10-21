<?php
declare(strict_types=1);
/** Plugin shop (/plugins), receipts, and the admin editor for the catalog and AI models (/admin/plugins). Purchases and rules live in app/shop.php. */

function shop_icon(array $it): string {
    $k = $it['key'];
    $ic = match (true) { $it['kind'] === 'model' => 'pills', $it['kind'] === 'pack' => 'coins', $k === 'dock' => 'layout-grid', $k === 'ai' => 'pills', $k === 'email' => 'mail', $k === 'sms' => 'message-square', str_starts_with($k, 'telegram') => 'send', default => 'puzzle' };
    return '<span class="shop-ic">' . ($ic === 'pills' ? '<span class="pills-mark" data-pills data-size="28"></span>' : icon($ic, 'size-5')) . '</span>';
}
function shop_price_text(array $it): string {
    return match ($it['mode']) {
        'paid' => $it['price'] > 0 ? fmt_inr($it['price']) . ' <span class="text-mute">/ ' . (int) $it['days'] . ' days</span>' : '<span class="text-mute">Not on sale yet</span>',
        'payg' => $it['unit_micro'] > 0 ? e(micro_to_inr($it['unit_micro'])) . ' <span class="text-mute">per use, from your wallet</span>' : '<span class="text-mute">Billed by use, from your wallet</span>',
        'pack' => fmt_inr($it['price']) . ' <span class="text-mute">→ ' . e(micro_to_inr((int) $it['credit_micro'])) . ' AI credit</span>',
        default => '<span class="text-mute">Included for everyone</span>',
    };
}
function shop_state_badge(array $it): string {
    return match ($it['state']) {
        'owned' => $it['admin_included'] ? badge('Included (admin)', 'dark') : badge('Owned' . ($it['until'] ? ' · until ' . date_label($it['until']) : ''), 'pos'),
        'free' => badge('Free', 'pos'), 'payg' => badge('Pay as you go', 'dark'),
        'expired' => badge('Expired ' . date_label((string) $it['until']), 'warn'), 'pack' => badge('Credit pack', 'neutral'),
        default => badge($it['mode'] === 'payg' ? 'Wallet empty' : 'Locked', 'neutral'),
    };
}
/** The Buy / Extend control for one card. Wallet purchase when the balance covers it, otherwise a top-up sheet; card payment is always an option. */
function shop_action_html(array $it, int $bal, string $nonce): string {
    $k = $it['key']; $micro = $it['price'] * 10000;
    if ($it['admin_included']) { return '<p class="text-sm text-mute">Included for administrators.</p>'; }
    if ($it['kind'] === 'pack') {
        return post_btn('Buy · ' . e(fmt_inr($it['price'])), 'shop-pack', ['key' => $k], 'btn btn-dark btn-sm', 'aria-label="Buy ' . e($it['name']) . ' by card"') . '<p class="mt-2 text-xs text-mute">Pay securely by card or UPI. The credit lands in your wallet when the payment is confirmed.</p>';
    }
    if ($it['mode'] === 'free') { return '<p class="text-sm text-mute">Nothing to buy: it is part of the app.</p>'; }
    if ($it['mode'] === 'payg') { return '<a class="btn btn-sm" href="#wallet" data-open="#topup">Top up wallet</a>'; }
    if (!$it['buyable']) { return '<p class="text-sm text-mute">Not available to buy yet.</p>'; }
    $label = $it['state'] === 'owned' ? 'Extend' : 'Buy'; $o = '';
    if ($bal >= $micro) {
        $o .= '<form method="post" action="' . e(url('/do/shop-buy')) . '" class="inline">' . csrf_field() . '<input type="hidden" name="_back" value="' . e(url('/plugins')) . '"><input type="hidden" name="key" value="' . e($k) . '"><input type="hidden" name="nonce" value="' . e($nonce) . '">'
            . '<button class="btn btn-dark btn-sm" aria-label="' . e($label . ' ' . $it['name']) . ' for ' . e(fmt_inr($it['price'])) . ' from your wallet">' . $label . ' · ' . e(fmt_inr($it['price'])) . '</button></form>';
        $o .= '<p class="mt-2 text-xs text-mute">Paid instantly from your wallet.</p>';
    } else {
        $need = $micro - $bal; $o .= '<button type="button" class="btn btn-dark btn-sm" data-open="#need-' . e($k) . '">' . $label . ' · ' . e(fmt_inr($it['price'])) . '</button><p class="mt-2 text-xs text-mute">Your wallet is short by ' . e(micro_to_inr($need)) . '. Top up and it completes automatically.</p>';
        $paise = max(1000, (int) (ceil($need / 10000 / 10000) * 10000)); // round the shortfall up to a whole ₹100
        $o .= '<dialog id="need-' . e($k) . '" class="modal" aria-labelledby="need-' . e($k) . '-t"><div class="modal-body"><div class="mb-4 flex items-start justify-between gap-4"><h2 id="need-' . e($k) . '-t" class="text-xl font-semibold tracking-tight">Top up to get ' . e($it['name']) . '</h2><button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close">' . icon('x', 'size-4') . '</button></div>'
            . '<p class="mb-4 text-sm text-mute">It costs ' . e(fmt_inr($it['price'])) . '. You have ' . e(micro_to_inr($bal)) . ' in your wallet. Add credit, pay on the secure gateway page, and the purchase completes as soon as the payment is confirmed.</p>'
            . shop_topup_form('shop-topup-buy', ['key' => $k], $paise, 'Top up and buy')
            . '<div class="mt-4 border-t border-line pt-4">' . post_btn('Or pay ' . e(fmt_inr($it['price'])) . ' directly by card', 'shop-card', ['key' => $k], 'btn btn-sm w-full') . '</div></div></dialog>';
    }
    return $o;
}
/** Amount form with ₹100 / ₹500 / ₹1000 presets and a custom amount. $paise = the suggested amount. */
function shop_topup_form(string $action, array $hidden, int $paise, string $submit): string {
    $h = ''; foreach ($hidden as $k => $v) { $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
    $chips = ''; foreach ([10000, 50000, 100000] as $p) { $chips .= '<button type="button" class="chip" data-topup-preset="' . ($p / 100) . '">' . e(fmt_inr($p)) . '</button>'; }
    $id = 'amt-' . substr(md5($action . json_encode($hidden)), 0, 6);
    return '<form method="post" action="' . e(url('/do/' . $action)) . '" class="space-y-3" data-topup>' . csrf_field() . '<input type="hidden" name="_back" value="' . e(url('/plugins')) . '">' . $h . '<div class="flex flex-wrap gap-2" role="group" aria-label="Quick amounts">' . $chips . '</div>'
        . '<div><label class="label" for="' . $id . '">Amount (₹)</label><div class="relative"><span aria-hidden="true" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-mute">₹</span><input id="' . $id . '" class="input !pl-8" name="amount" inputmode="decimal" required value="' . e(money_input($paise)) . '" autocomplete="off"></div><p class="mt-1 text-xs text-mute">Between ₹10 and ₹50,000.</p></div>'
        . '<button class="btn btn-primary w-full">' . e($submit) . '</button></form>';
}

function page_plugins(): void {
    $u = require_login(); $s = snap(); $uid = (int) $u['id']; layout_start('Plugins', 'plugins', $s); $bal = wallet_balance($uid);
    page_header('Plugins', 'Add features to your app. Pay from your wallet and they switch on straight away.', '<a class="btn btn-sm" href="' . e(url('/plans')) . '">' . icon('credit-card', 'size-4') . 'Plans &amp; wallet</a>');
    echo '<section id="wallet" class="card mb-5 flex flex-wrap items-center justify-between gap-4 p-5"><div class="flex items-center gap-4"><span class="shop-ic shop-ic--lg">' . icon('wallet', 'size-6') . '</span><div><p class="text-xs font-medium uppercase tracking-wide text-mute">Wallet balance</p><p class="num text-3xl font-semibold" data-wallet-balance>' . e(micro_to_inr($bal)) . '</p></div></div>'
        . '<button type="button" class="btn btn-dark" data-open="#topup">' . icon('circle-plus', 'size-4') . 'Top up</button></section>';
    if ($um = usage_meters_html($u)) { echo '<div class="mb-5">' . $um . '</div>'; }
    echo '<dialog id="topup" class="modal" aria-labelledby="topup-t"><div class="modal-body"><div class="mb-4 flex items-start justify-between gap-4"><h2 id="topup-t" class="text-xl font-semibold tracking-tight">Top up your wallet</h2><button type="button" class="btn btn-ghost btn-sm -mr-2" data-close aria-label="Close">' . icon('x', 'size-4') . '</button></div>'
        . '<p class="mb-4 text-sm text-mute">You pay on the secure gateway page; the credit appears as soon as the payment is confirmed.</p>' . shop_topup_form('wallet-topup', [], 50000, 'Continue to payment') . '</div></dialog>';
    $items = shop_catalog($u); $nonce = bin2hex(random_bytes(8));
    if (!$items) { echo empty_state('Nothing for sale yet', 'The administrator has not listed any plugins.', '', 'puzzle'); }
    else {
        echo '<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" data-shop>';
        foreach ($items as $it) {
            $feat = $it['kind'] !== 'pack'; $soon = $it['state'] === 'owned' && $it['until'] && !$it['admin_included'] && days_between(today(), $it['until']) <= 7;
            echo '<article class="plan-card shop-card' . ($it['state'] === 'owned' && !$it['admin_included'] ? ' is-owned' : '') . ($it['state'] === 'locked' && $it['kind'] === 'model' ? ' is-locked' : '') . '" id="plug-' . e($it['key']) . '"><div class="flex items-start justify-between gap-3">' . shop_icon($it) . '<div class="flex flex-wrap justify-end gap-1.5">' . shop_state_badge($it) . ($soon ? badge('Ends soon', 'warn') : '') . '</div></div>'
                . '<h3 class="mt-1 text-lg font-semibold tracking-tight">' . e($it['name']) . '</h3><p class="text-sm text-mute">' . e($it['desc']) . '</p><p class="num mt-1 text-base font-semibold">' . shop_price_text($it) . '</p>'
                . '<div class="mt-auto pt-3">' . shop_action_html($it, $bal, $nonce) . '</div>' . (function_exists('c_plugin_extra') ? c_plugin_extra($it, $u) : '') . '</article>';
        }
        echo '</div>';
    }
    $hist = shop_history($uid, 20);
    echo '<div class="mt-6">'; card_flush_open('Purchase history', 'Receipts for everything you bought here.');
    if (!$hist) { echo '<p class="px-6 pb-6 text-sm text-mute">Nothing yet. Your receipts will show up here.</p>'; }
    else { echo '<ul class="divide-y divide-line px-6 pb-3 text-sm">'; foreach ($hist as $p) { echo '<li class="flex flex-wrap items-center justify-between gap-2 py-3"><span class="min-w-0"><span class="block truncate font-medium">' . e($p['title']) . '</span><span class="text-xs text-mute">' . e(substr($p['created_at'], 0, 16)) . ' UTC · ' . e($p['method'] === 'card' ? 'Card / UPI' : 'Wallet') . ($p['valid_until'] ? ' · until ' . e(date_label($p['valid_until'])) : '') . '</span></span>'
            . '<span class="flex items-center gap-3"><span class="num font-medium">' . e(micro_to_inr((int) $p['amount_micro'])) . '</span><a class="btn btn-ghost btn-sm" href="' . e(url('/plugins/receipt', ['id' => $p['id']])) . '">Receipt</a></span></li>'; } echo '</ul>'; }
    echo '</div></section></div>';
    echo '<script src="' . e(asset('shop.js')) . '" defer></script>';
    layout_end();
}

function page_plugin_receipt(): void {
    $u = require_login(); $s = snap(); $p = one('SELECT * FROM {shop_purchases} WHERE id=? AND user_id=?', [(int) get_str('id', 12), (int) $u['id']]);
    layout_start('Receipt', 'plugins', $s); page_header('Receipt', 'A simple receipt for your records.', '<button type="button" class="btn btn-sm" data-print>' . icon('receipt', 'size-4') . 'Print</button><a class="btn btn-sm" href="' . e(url('/plugins')) . '">Back to plugins</a>');
    if (!$p) { echo empty_state('Receipt not found', 'It may belong to another account.', '<a class="btn btn-primary" href="' . e(url('/plugins')) . '">Back to plugins</a>', 'receipt'); echo '<script src="' . e(asset('shop.js')) . '" defer></script>'; layout_end(); return; }
    $rows = ['Receipt number' => shop_receipt_no($p), 'Date' => substr($p['created_at'], 0, 16) . ' UTC', 'Billed to' => $u['name'] . ' (' . $u['email'] . ')', 'Item' => $p['title'], 'Paid with' => $p['method'] === 'card' ? 'Card / UPI' : 'Wallet balance'] + ($p['valid_until'] ? ['Access until' => date_label($p['valid_until'])] : []);
    echo '<article class="card mx-auto max-w-xl p-6" id="receipt"><div class="mb-4 flex items-center justify-between"><p class="text-lg font-semibold">' . e(APP_NAME) . '</p>' . badge('Paid', 'pos') . '</div><dl class="divide-y divide-line text-sm">';
    foreach ($rows as $k => $v) { echo '<div class="flex justify-between gap-4 py-2.5"><dt class="text-mute">' . e($k) . '</dt><dd class="text-right font-medium">' . e((string) $v) . '</dd></div>'; }
    echo '<div class="flex justify-between gap-4 py-3 text-base"><dt class="font-semibold">Total</dt><dd class="num font-semibold">' . e(micro_to_inr((int) $p['amount_micro'])) . '</dd></div></dl><p class="mt-3 text-xs text-mute">Purchases are final. This receipt is not a tax invoice.</p></article>';
    echo '<script src="' . e(asset('shop.js')) . '" defer></script>'; layout_end();
}

// ============================ admin: AI models, effort map and plugin catalog ============================
function page_admin_plugins(): void {
    $me = require_admin(); admin_start('Plugins & models', 'plugins'); $cfg = ai_models_cfg(); $def = ai_default_model(); $eff = ai_efforts_cfg(); $meta = shop_meta();
    echo '<div class="space-y-5">';
    // ---- models
    $rows = '';
    foreach ($cfg as $id => $m) { $fc = feature_cfg(model_feature($id)); [$pi, $po] = ai_price($id); $sid = e($id);
        $sel = fn(string $n, array $opts, string $cur) => '<select class="input !h-10" name="' . $n . '[' . $sid . ']" aria-label="' . e($m['name']) . ' ' . $n . '">' . implode('', array_map(fn($k, $l) => '<option value="' . e($k) . '"' . ($k === $cur ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts)) . '</select>';
        $rows .= '<div class="rounded-2xl border border-line p-4"><div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">' . e($id) . ($id === $def ? ' ' . badge('Default', 'dark') : '') . (!$m['builtin'] ? ' ' . badge('Custom') : '') . '</p><label class="flex items-center gap-2 text-sm"><input type="radio" name="default_model" value="' . $sid . '"' . ($id === $def ? ' checked' : '') . '> Default model</label></div>'
            . '<div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4"><div><label class="label">Availability</label>' . $sel('state', ['allowed' => 'Allowed (free)', 'premium' => 'Premium (needs plugin)', 'hidden' => 'Hidden'], $m['state']) . '</div>'
            . '<div><label class="label">Display name</label><input class="input !h-10" name="name[' . $sid . ']" maxlength="40" value="' . e($m['name']) . '"></div><div><label class="label">Tag</label><input class="input !h-10" name="tag[' . $sid . ']" maxlength="24" value="' . e($m['tag']) . '" placeholder="Flagship, Fast…"></div>'
            . '<div><label class="label">Thinking</label>' . $sel('thinking', ['adaptive' => 'Adaptive (effort)', 'budget' => 'Extended (token budget)', 'none' => 'None'], $m['thinking']) . '</div>'
            . '<div><label class="label">Input price ×</label><input class="input !h-10" name="mult_in[' . $sid . ']" inputmode="decimal" value="' . e((string) $m['mult_in']) . '"></div><div><label class="label">Output price ×</label><input class="input !h-10" name="mult_out[' . $sid . ']" inputmode="decimal" value="' . e((string) $m['mult_out']) . '"></div>'
            . '<div><label class="label">Plugin price (₹)</label><input class="input !h-10" name="price[' . $sid . ']" inputmode="decimal" value="' . e(money_input($fc['price'])) . '" placeholder="199"></div><div><label class="label">Plugin days</label><input class="input !h-10" name="days[' . $sid . ']" type="number" min="1" max="366" value="' . (int) $fc['days'] . '"></div>'
            . '<label class="col-span-2 flex items-center gap-2 text-sm"><input type="checkbox" name="vision[' . $sid . ']" value="1"' . ($m['vision'] ? ' checked' : '') . '> Can look at images and PDFs</label><p class="col-span-2 text-xs text-mute">Metered at ' . e((string) $pi) . ' / ' . e((string) $po) . ' ₹ per million tokens (set on the AI agent tab) × the multipliers.</p></div></div>';
    }
    card_open('AI models', 'Which models people can use in the advisor. Allowed = everyone; Premium = needs the model plugin from the shop (price below); Hidden = nobody (admins still see it). Enforced on the server for every chat request.');
    echo form_open('admin-models-save', 'space-y-3') . $rows . '<div class="rounded-2xl border border-dashed border-line p-4"><p class="mb-2 text-sm font-medium">Add a model</p><div class="grid grid-cols-2 gap-3"><div><label class="label" for="nm-id">Model id</label><input class="input !h-10" id="nm-id" name="new_id" placeholder="claude-…" maxlength="26" pattern="claude-[a-z0-9.\-]{2,20}"></div><div><label class="label" for="nm-n">Display name</label><input class="input !h-10" id="nm-n" name="new_name" maxlength="40"></div></div></div>'
        . '<div class="flex justify-end"><button class="btn btn-dark">Save models</button></div></form>'; card_close();
    // ---- effort
    $er = ''; foreach ($eff as $label => $r) { $opts = ''; foreach (AI_API_EFFORTS as $x) { $opts .= '<option' . ($x === $r['effort'] ? ' selected' : '') . '>' . $x . '</option>'; }
        $er .= '<tr><td class="py-1 pr-2 font-medium">' . e($label) . '</td><td class="py-1 pr-2"><select class="input !h-10" name="eff[' . e($label) . ']" aria-label="' . e($label) . ' effort level">' . $opts . '</select></td><td class="py-1 pr-2"><input class="input !h-10 w-24" type="number" min="200" max="32000" name="max_tokens[' . e($label) . ']" value="' . $r['max_tokens'] . '" aria-label="' . e($label) . ' max tokens"></td><td class="py-1"><input class="input !h-10 w-24" type="number" min="0" max="30000" name="budget[' . e($label) . ']" value="' . $r['budget'] . '" aria-label="' . e($label) . ' thinking budget"></td></tr>'; }
    card_open('Effort slider', 'What each step of the effort slider sends. Adaptive models get the effort level; extended-thinking models get the token budget (0 or under 1,024 = no thinking); models without thinking ignore both. Max tokens caps the reply.');
    echo form_open('admin-efforts-save', 'space-y-3') . '<div class="overflow-x-auto"><table class="w-full min-w-[420px] text-sm"><thead><tr class="text-left text-xs text-mute"><th class="py-1 pr-2">Slider</th><th class="py-1 pr-2">API effort</th><th class="py-1 pr-2">Max tokens</th><th class="py-1">Thinking budget</th></tr></thead><tbody>' . $er . '</tbody></table></div>'
        . '<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="ai_web_search" value="1"' . (site_setting('ai_web_search', '0') === '1' ? ' checked' : '') . '> Offer “Web search” in the chat (uses Anthropic’s web search tool; extra cost)</label>'
        . '<div><label class="label" for="cmds">Extra slash commands (one per line: key | short description | instruction to the model)</label><textarea id="cmds" name="commands" rows="4" class="input font-mono text-xs" maxlength="3000" placeholder="rent | Rent vs buy | Compare renting and buying with the calculators">' . e(shop_commands_text()) . '</textarea></div>'
        . '<div class="flex justify-end"><button class="btn btn-dark">Save</button></div></form>'; card_close();
    // ---- catalog
    $cat = ''; foreach (shop_catalog(null, true) as $it) { $k = e($it['key']); $mt = is_array($meta[$it['key']] ?? null) ? $meta[$it['key']] : []; $isPack = $it['kind'] === 'pack'; $f = $isPack ? null : (features()[$it['key']] ?? null);
        $modes = $isPack ? ['pack' => 'Credit pack'] : array_intersect_key(['free' => 'Free for everyone', 'paid' => 'Paid (time-limited)', 'payg' => 'Pay as you go'], array_flip($f['modes'] ?? ['free']));
        $mo = ''; foreach ($modes as $mk => $ml) { $mo .= '<option value="' . $mk . '"' . ($it['mode'] === $mk ? ' selected' : '') . '>' . e($ml) . '</option>'; }
        $cat .= '<div class="rounded-2xl border border-line p-4"><div class="flex flex-wrap items-center justify-between gap-2"><p class="font-semibold">' . e($it['name']) . ' <span class="text-xs font-normal text-mute">' . $k . ' · ' . e($it['kind']) . '</span></p><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="hidden[' . $k . ']" value="1"' . ($it['hidden'] ? ' checked' : '') . '> Hide from the shop</label></div>'
            . '<div class="mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4"><div class="col-span-2"><label class="label">Name</label><input class="input !h-10" name="name[' . $k . ']" maxlength="60" value="' . e((string) ($mt['name'] ?? '')) . '" placeholder="' . e($it['name']) . '"></div><div class="col-span-2"><label class="label">Description</label><input class="input !h-10" name="desc[' . $k . ']" maxlength="200" value="' . e((string) ($mt['desc'] ?? '')) . '" placeholder="' . e(mb_substr($it['desc'], 0, 60)) . '"></div>'
            . '<div><label class="label">Mode</label><select class="input !h-10" name="mode[' . $k . ']"' . ($isPack ? ' disabled' : '') . '>' . $mo . '</select></div><div><label class="label">Price (₹)</label><input class="input !h-10" name="price[' . $k . ']" inputmode="decimal" value="' . e(money_input((int) $it['price'])) . '"></div>'
            . ($isPack ? '<div><label class="label">Credit given (₹)</label><input class="input !h-10" name="credit[' . $k . ']" inputmode="decimal" value="' . e(money_input((int) ($it['credit_micro'] / 10000))) . '"></div><div></div>' : '<div><label class="label">Period (days)</label><input class="input !h-10" type="number" min="1" max="366" name="days[' . $k . ']" value="' . (int) $it['days'] . '"></div><div><label class="label">Per-use price (₹, pay as you go)</label><input class="input !h-10" name="unit[' . $k . ']" inputmode="decimal" value="' . e(micro_input((int) $it['unit_micro'])) . '"></div>')
            . '<div><label class="label">Order</label><input class="input !h-10" type="number" name="sort[' . $k . ']" value="' . (int) $it['sort'] . '"></div></div></div>'; }
    card_open('Plugin catalog', 'Everything on the Plugins page. Rename it, set mode, price and period, hide it, or change the order. Premium AI models are listed here too (their price can also be set above).');
    echo form_open('admin-catalog-save', 'space-y-3') . $cat . '<div class="flex justify-end"><button class="btn btn-dark">Save catalog</button></div></form>'; card_close();
    // ---- add custom plugin / pack
    card_open('Add to the catalog', 'A custom plugin is a switch you can check with feature_on($user, \'key\') in code or plugins, sold like any other. A pack sells wallet credit with a bonus.');
    echo '<div class="grid gap-5 lg:grid-cols-2">' . simple_form('admin-plugin-add', [['name' => 'key', 'label' => 'Key (a-z, 0-9, _)', 'required' => true, 'placeholder' => 'priority_support'], ['name' => 'label', 'label' => 'Name', 'required' => true], ['name' => 'desc', 'label' => 'Description']], 'Add plugin', 'btn btn-dark')
        . simple_form('admin-pack-add', [['name' => 'key', 'label' => 'Key (a-z, 0-9, _)', 'required' => true, 'placeholder' => 'ai_2000'], ['name' => 'label', 'label' => 'Name', 'required' => true, 'placeholder' => 'AI usage pack · 2000'], ['name' => 'price', 'label' => 'Price (₹)', 'type' => 'money', 'currency' => '₹', 'required' => true, 'half' => true], ['name' => 'credit', 'label' => 'Credit (₹)', 'type' => 'money', 'currency' => '₹', 'required' => true, 'half' => true]], 'Add pack', 'btn btn-dark') . '</div>';
    $custom = shop_custom(); $packs = shop_json('shop_packs');
    if ($custom || $packs) { echo '<div class="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">'; foreach (array_keys($custom) as $k) { echo confirm_btn('Remove plugin ' . e($k), 'admin-plugin-remove', ['key' => $k], 'Remove ' . $k . '?', 'It disappears from the shop. People who already bought it keep their access record.', 'Remove', 'btn btn-sm'); } foreach ($packs as $p) { if (!empty($p['key'])) { echo confirm_btn('Remove pack ' . e((string) $p['key']), 'admin-pack-remove', ['key' => $p['key']], 'Remove this pack?', 'It disappears from the shop.', 'Remove', 'btn btn-sm'); } } echo '</div>'; }
    card_close(); echo '</div>'; admin_end();
}
function shop_commands_text(): string { $o = []; foreach (shop_json('ai_commands') as $r) { if (is_array($r)) { $o[] = ($r['key'] ?? '') . ' | ' . ($r['description'] ?? '') . ' | ' . ($r['prompt'] ?? ''); } } return implode("\n", $o); }
