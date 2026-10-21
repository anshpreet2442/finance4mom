<?php
declare(strict_types=1);
/**
 * Plugin shop + AI model catalog (builder-A, round 2).
 *  - features(): the built-in FEATURES plus admin-defined plugins (setting `shop_custom`) plus one "mdl_<model>" feature per premium AI model.
 *    Everything keeps using feature_cfg()/feature_access()/entitlements, so enforcement stays in one place.
 *  - shop_catalog(): the cards on /plugins (features, premium models, usage packs) with the viewer's state.
 *  - shop_buy(): idempotent, race-safe purchase from the wallet. Card payments reuse the existing charge flow (apply_charge_effects).
 *  - ai_models_cfg()/ai_model_access()/ai_effort_params(): which Claude models a person may use and how "effort" maps to max_tokens/thinking.
 */

// ============================ feature registry ============================
function shop_json(string $key, array $default = []): array { $v = json_decode(site_setting($key, ''), true); return is_array($v) ? $v : $default; }
function shop_custom(): array { $o = []; foreach (shop_json('shop_custom') as $r) { if (is_array($r) && preg_match('/^[a-z][a-z0-9_]{1,24}$/', (string) ($r['key'] ?? '')) && !isset(FEATURES[$r['key']])) { $o[$r['key']] = ['label' => mb_substr((string) ($r['label'] ?? $r['key']), 0, 60), 'desc' => mb_substr((string) ($r['desc'] ?? ''), 0, 200), 'modes' => ['free', 'paid', 'payg'], 'custom' => true]; } } return $o; }
/** Feature key for a premium model. Feature keys are limited to 30 characters. */
function model_feature(string $model): string { return 'mdl_' . $model; }
function is_model_feature(string $f): bool { return str_starts_with($f, 'mdl_'); }
function features(): array {
    static $cache = null, $stamp = null; $sig = site_setting('shop_custom', '') . '|' . site_setting('ai_models', '');
    if ($cache !== null && $stamp === $sig) { return $cache; }
    $f = FEATURES + shop_custom();
    foreach (ai_models_cfg() as $id => $m) { if ($m['state'] === 'premium') { $f[model_feature($id)] = ['label' => $m['name'] . ' model', 'desc' => 'Use ' . $m['name'] . ' in the AI advisor.' . ($m['tag'] !== '' ? ' ' . $m['tag'] . '.' : ''), 'modes' => ['paid'], 'default' => 'paid', 'price' => 19900, 'model' => $id]; } }
    $stamp = $sig; return $cache = $f;
}

// ============================ AI models ============================
const AI_EFFORT_DEFAULT = [
    'Low' => ['effort' => 'low', 'max_tokens' => 1000, 'budget' => 0], 'Medium' => ['effort' => 'medium', 'max_tokens' => 2000, 'budget' => 1024], 'High' => ['effort' => 'high', 'max_tokens' => 4000, 'budget' => 2048],
    'Extra' => ['effort' => 'xhigh', 'max_tokens' => 8000, 'budget' => 4096], 'Max' => ['effort' => 'max', 'max_tokens' => 16000, 'budget' => 8192],
];
const AI_API_EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];
function ai_model_defaults(string $id): array {
    $names = AI_MODELS[$id] ?? $id;
    $d = ['claude-fable-5-1' => ['premium', 'Flagship'], 'claude-opus-5-5' => ['premium', 'Deep thinking'], 'claude-sonnet-5-5' => ['allowed', 'Balanced'], 'claude-haiku-5-5' => ['allowed', 'Fast']][$id] ?? ['allowed', ''];
    return ['state' => $d[0], 'name' => $names, 'tag' => $d[1], 'mult_in' => 1.0, 'mult_out' => 1.0, 'thinking' => 'adaptive', 'vision' => true];
}
/** Every known model with its admin settings. state: allowed (free for everyone), premium (needs the model's plugin), hidden (nobody). */
function ai_models_cfg(): array {
    static $cache = null, $stamp = null; $raw = site_setting('ai_models', ''); if ($cache !== null && $stamp === $raw) { return $cache; }
    $saved = json_decode($raw, true); $saved = is_array($saved) ? $saved : []; $ids = array_keys(AI_MODELS); foreach (array_keys($saved) as $k) { if (is_string($k) && preg_match('/^claude-[a-z0-9.\-]{2,20}$/', $k) && !in_array($k, $ids, true)) { $ids[] = $k; } }
    $out = [];
    foreach ($ids as $id) {
        $d = ai_model_defaults($id); $s = is_array($saved[$id] ?? null) ? $saved[$id] : [];
        $state = in_array($s['state'] ?? '', ['allowed', 'premium', 'hidden'], true) ? $s['state'] : $d['state'];
        $out[$id] = ['state' => $state, 'name' => mb_substr(trim((string) ($s['name'] ?? '')) ?: $d['name'], 0, 40), 'tag' => mb_substr(trim((string) ($s['tag'] ?? $d['tag'])), 0, 24),
            'mult_in' => max(0.0, min(100.0, (float) ($s['mult_in'] ?? 1))), 'mult_out' => max(0.0, min(100.0, (float) ($s['mult_out'] ?? 1))),
            'thinking' => in_array($s['thinking'] ?? '', ['adaptive', 'budget', 'none'], true) ? $s['thinking'] : $d['thinking'], 'vision' => (bool) ($s['vision'] ?? $d['vision']), 'builtin' => isset(AI_MODELS[$id])];
    }
    $stamp = $raw; return $cache = $out;
}
function ai_model_name(string $id): string { return ai_models_cfg()[$id]['name'] ?? (AI_MODELS[$id] ?? $id); }
/** The admin's default model, falling back to the first allowed one. */
function ai_default_model(): string {
    $c = ai_models_cfg(); $want = site_setting('ai_model', 'claude-sonnet-5-5');
    if (isset($c[$want]) && $c[$want]['state'] === 'allowed') { return $want; }
    foreach ($c as $id => $m) { if ($m['state'] === 'allowed') { return $id; } }
    return isset($c[$want]) ? $want : 'claude-sonnet-5-5';
}
/** May this person use this model? ['ok','code','reason']. Enforced on every chat request, whatever the browser sends. */
function ai_model_access(?array $u, string $id): array {
    $c = ai_models_cfg(); if (!$u) { return ['ok' => false, 'code' => 'auth', 'reason' => 'Sign in first.']; }
    if (!isset($c[$id]) || $c[$id]['state'] === 'hidden') { if (is_exempt_user($u) && isset($c[$id])) { return ['ok' => true, 'code' => '', 'reason' => '']; } return ['ok' => false, 'code' => 'model', 'reason' => 'That model is not available.']; }
    if ($c[$id]['state'] === 'allowed' || is_exempt_user($u)) { return ['ok' => true, 'code' => '', 'reason' => '']; }
    $fa = feature_access($u, model_feature($id)); if ($fa['ok']) { return ['ok' => true, 'code' => '', 'reason' => '']; }
    return ['ok' => false, 'code' => 'model_locked', 'reason' => $c[$id]['name'] . ' is a premium model. Get it in Plugins to use it.'];
}
/** Picks the model for a request: '' = the default (or the best one this person may use). Throws UserError when the choice is not allowed. */
function ai_pick_model(array $u, string $requested): string {
    if ($requested === '') { $d = ai_default_model(); if (ai_model_access($u, $d)['ok']) { return $d; } foreach (ai_models_cfg() as $id => $m) { if (ai_model_access($u, $id)['ok'] && $m['state'] === 'allowed') { return $id; } } throw new UserError('No AI model is available to you right now.'); }
    $a = ai_model_access($u, $requested); if (!$a['ok']) { throw new UserError($a['reason']); } return $requested;
}
/** Models for the picker: allowed and owned first, locked premium ones (dimmed, link to the shop) after. Hidden ones never leave the server (admins still see them as hidden). */
function ai_model_options(array $u): array {
    $o = []; foreach (ai_models_cfg() as $id => $m) {
        if ($m['state'] === 'hidden' && !is_exempt_user($u)) { continue; } $a = ai_model_access($u, $id);
        $o[] = ['key' => $id, 'name' => $m['name'], 'tag' => $m['state'] === 'hidden' ? 'Hidden' : $m['tag'], 'locked' => !$a['ok'], 'vision' => $m['vision'], 'thinking' => $m['thinking']];
    }
    usort($o, fn($a, $b) => (int) $a['locked'] <=> (int) $b['locked']); return $o;
}
function ai_efforts_cfg(): array {
    $s = shop_json('ai_efforts'); $out = [];
    foreach (AI_EFFORT_DEFAULT as $label => $d) { $r = is_array($s[$label] ?? null) ? $s[$label] : []; $e = in_array($r['effort'] ?? '', AI_API_EFFORTS, true) ? $r['effort'] : $d['effort'];
        $out[$label] = ['effort' => $e, 'max_tokens' => max(200, min(32000, (int) ($r['max_tokens'] ?? $d['max_tokens']))), 'budget' => max(0, min(30000, (int) ($r['budget'] ?? $d['budget'])))]; }
    return $out;
}
/** Maps the chosen effort label to API parameters for one model. Unsupported settings are ignored (thinking none => nothing; budget models get budget_tokens; adaptive models get effort). */
function ai_effort_params(string $model, string $label): array {
    $e = ai_efforts_cfg(); $label = isset($e[$label]) ? $label : 'Medium'; $r = $e[$label]; $m = ai_models_cfg()[$model] ?? ai_model_defaults($model);
    $p = ['max_tokens' => $r['max_tokens'], 'label' => $label]; $mode = $m['thinking'];
    if ($mode === 'adaptive') { $p['thinking'] = ['type' => 'adaptive']; $p['output_config'] = ['effort' => $r['effort']]; }
    elseif ($mode === 'budget' && $r['budget'] >= 1024) { $p['thinking'] = ['type' => 'enabled', 'budget_tokens' => min($r['budget'], $r['max_tokens'] - 256)]; if ($p['thinking']['budget_tokens'] < 1024) { unset($p['thinking']); } }
    return $p;
}

// ============================ catalog ============================
function shop_meta(): array { return shop_json('shop_meta'); }
function shop_packs(): array {
    $o = []; foreach (shop_json('shop_packs') as $r) { if (!is_array($r) || !preg_match('/^[a-z][a-z0-9_]{1,24}$/', (string) ($r['key'] ?? ''))) { continue; } $price = (int) ($r['price'] ?? 0); $credit = (int) ($r['credit'] ?? 0);
        if ($price > 0 && $credit > 0) { $o[$r['key']] = ['key' => 'pack_' . $r['key'], 'label' => mb_substr((string) ($r['label'] ?? 'AI pack'), 0, 60), 'desc' => mb_substr((string) ($r['desc'] ?? ''), 0, 200), 'price' => $price, 'credit' => $credit, 'hidden' => !empty($r['hidden'])]; } }
    if (!$o && site_setting('shop_packs_seeded', '') === '') { return shop_default_packs(); }
    return $o;
}
function shop_default_packs(): array {
    return ['small' => ['key' => 'pack_small', 'label' => 'AI usage pack · 500', 'desc' => 'Adds ₹550 of AI credit to your wallet (10% bonus).', 'price' => 50000, 'credit' => 55000, 'hidden' => false], 'big' => ['key' => 'pack_big', 'label' => 'AI usage pack · 1000', 'desc' => 'Adds ₹1,200 of AI credit to your wallet (20% bonus).', 'price' => 100000, 'credit' => 120000, 'hidden' => false]];
}
/** One shop card per purchasable thing. kind: feature | model | pack. state: free | owned | expired | locked | payg. */
function shop_catalog(?array $u, bool $withHidden = false): array {
    $meta = shop_meta(); $items = []; $uid = $u ? (int) $u['id'] : 0; $i = 0;
    foreach (features() as $f => $def) {
        $c = feature_cfg($f); $mt = is_array($meta[$f] ?? null) ? $meta[$f] : []; $hidden = !empty($mt['hidden']); if ($hidden && !$withHidden) { $i++; continue; }
        $until = $uid ? entitlement_until($uid, $f) : null; $fa = $u ? feature_access($u, $f) : ['ok' => false];
        $state = !empty($u) && is_exempt_user($u) ? 'owned' : ($c['mode'] === 'free' ? 'free' : ($c['mode'] === 'payg' ? ($fa['ok'] ? 'payg' : 'locked') : ($fa['ok'] ? 'owned' : ($until ? 'expired' : 'locked'))));
        $items[] = ['key' => $f, 'kind' => isset($def['model']) ? 'model' : 'feature', 'name' => (string) ($mt['name'] ?? '') ?: $def['label'], 'desc' => (string) ($mt['desc'] ?? '') ?: $def['desc'], 'mode' => $c['mode'], 'price' => $c['price'], 'days' => $c['days'], 'unit_micro' => $c['unit_micro'],
            'state' => $state, 'until' => $until, 'hidden' => $hidden, 'sort' => (int) ($mt['sort'] ?? ($i * 10)), 'buyable' => $c['mode'] === 'paid' && $c['price'] > 0, 'admin_included' => $u && is_exempt_user($u)];
        $i++;
    }
    $i = 0; foreach (shop_packs() as $p) { $mt = is_array($meta[$p['key']] ?? null) ? $meta[$p['key']] : []; $hidden = $p['hidden'] || !empty($mt['hidden']); if ($hidden && !$withHidden) { continue; }
        $items[] = ['key' => $p['key'], 'kind' => 'pack', 'name' => (string) ($mt['name'] ?? '') ?: $p['label'], 'desc' => (string) ($mt['desc'] ?? '') ?: $p['desc'], 'mode' => 'pack', 'price' => $p['price'], 'days' => 0, 'credit_micro' => $p['credit'] * 10000, 'state' => 'pack', 'until' => null, 'hidden' => $hidden, 'sort' => (int) ($mt['sort'] ?? (900 + $i++)), 'buyable' => true, 'admin_included' => false]; }
    usort($items, fn($a, $b) => [$a['sort'], $a['key']] <=> [$b['sort'], $b['key']]); return $items;
}
function shop_item(string $key): ?array { foreach (shop_catalog(null, true) as $it) { if ($it['key'] === $key) { return $it; } } return null; }

// ============================ buying ============================
function shop_ref(string $key, string $nonce): string { return 'buy:' . substr(hash('sha256', $key . '|' . $nonce), 0, 30); }
function shop_title(array $it): string { return $it['kind'] === 'pack' ? $it['name'] : $it['name'] . ($it['days'] ? ' · ' . $it['days'] . ' days' : ''); }
function shop_record(int $uid, string $key, string $title, int $micro, ?int $days, string $method, string $ref, ?string $until): int {
    $ok = run('INSERT IGNORE INTO {shop_purchases} (user_id,item_key,title,amount_micro,days,method,ref,valid_until) VALUES (?,?,?,?,?,?,?,?)', [$uid, $key, mb_substr($title, 0, 120), $micro, $days, $method, $ref, $until]);
    return $ok ? (int) val('SELECT id FROM {shop_purchases} WHERE user_id=? AND ref=?', [$uid, $ref]) : 0;
}
/**
 * Buys a paid plugin / premium model from the wallet. $nonce makes a retry of the same click harmless (the same nonce is applied once).
 * Returns ['status' => 'done'|'already', 'purchase_id', 'until', 'balance_micro']. Throws UserError('..') with ->getCode()-less friendly text; use shop_shortfall() to learn the missing amount.
 */
function shop_buy(int $uid, string $key, string $nonce): array {
    if (strlen($nonce) < 8 || strlen($nonce) > 64) { throw new UserError('Your page is out of date. Reload and try again.'); }
    $it = shop_item($key); if (!$it || $it['hidden'] || $it['kind'] === 'pack' || !$it['buyable']) { throw new UserError('That plugin is not for sale.'); }
    $ref = shop_ref($key, $nonce); $micro = $it['price'] * 10000;
    return tx(function () use ($uid, $key, $it, $ref, $micro) {
        if (!val('SELECT id FROM {users} WHERE id=? FOR UPDATE', [$uid])) { throw new UserError('Account not found.'); } // serialises this person's purchases
        $done = one('SELECT id,valid_until FROM {shop_purchases} WHERE user_id=? AND ref=?', [$uid, $ref]);
        if ($done) { return ['status' => 'already', 'purchase_id' => (int) $done['id'], 'until' => $done['valid_until'], 'balance_micro' => wallet_balance($uid)]; }
        $bal = wallet_balance($uid); if ($bal < $micro) { throw new ShopShortfall('Your wallet has ' . micro_to_inr($bal) . ' and this costs ' . micro_to_inr($micro) . '. Top up to continue.', $micro - $bal); }
        if (!wallet_add($uid, -$micro, 'purchase', $it['name'], $ref)) { $d = one('SELECT id,valid_until FROM {shop_purchases} WHERE user_id=? AND ref=?', [$uid, $ref]); return ['status' => 'already', 'purchase_id' => (int) ($d['id'] ?? 0), 'until' => $d['valid_until'] ?? null, 'balance_micro' => wallet_balance($uid)]; }
        $until = grant_feature($uid, $key, max(1, (int) $it['days']), 'purchase'); $pid = shop_record($uid, $key, shop_title($it), $micro, (int) $it['days'], 'wallet', $ref, $until);
        return ['status' => 'done', 'purchase_id' => $pid, 'until' => $until, 'balance_micro' => wallet_balance($uid)];
    });
}
class ShopShortfall extends UserError { public function __construct(string $m, public int $missing_micro = 0) { parent::__construct($m); } }
/** Starts a card payment (the existing PayU charge flow) for an item or a usage pack, or a wallet top-up that completes the purchase when it lands. Returns the charge id. */
function shop_start_checkout(int $uid, string $key, string $mode, int $topupPaise = 0): int {
    $it = shop_item($key); if (!$it || $it['hidden']) { throw new UserError('That plugin is not for sale.'); }
    if ($it['kind'] === 'pack') { return create_self_charge($uid, $it['name'], $it['price'], ['topup_micro' => $it['credit_micro'], 'desc' => 'AI usage pack']); }
    if (!$it['buyable']) { throw new UserError('That plugin is not for sale.'); }
    if ($mode === 'card') { return create_self_charge($uid, $it['name'] . ' · ' . $it['days'] . ' days', $it['price'], ['feature' => $key, 'days' => $it['days'], 'desc' => 'Access to ' . $it['name']]); }
    // wallet top-up that completes the purchase afterwards
    $need = max(0, $it['price'] * 10000 - wallet_balance($uid)); $paise = max($topupPaise, intdiv($need + 9999, 10000), 1000);
    if ($paise > 5000000) { throw new UserError('Top up between ₹10 and ₹50,000.'); }
    $id = create_self_charge($uid, 'Wallet top-up ' . fmt_inr($paise), $paise, ['topup_micro' => $paise * 10000, 'desc' => 'Top-up to buy ' . $it['name']]);
    run('UPDATE {charges} SET buy_key=? WHERE id=? AND user_id=? AND buy_key IS NULL', [$key, $id, $uid]); return $id;
}
/** Called from apply_charge_effects once a top-up that was started from the shop has been credited: finishes the purchase if the wallet now covers it. */
function shop_complete_after_topup(int $uid, string $key, int $chargeId): void {
    try { shop_buy($uid, $key, 'topup-' . $chargeId); } catch (UserError) { /* still short or no longer for sale: the credit simply stays in the wallet */ }
}
/** A charge (card) that granted a feature or credited a pack gets a purchase record + receipt too. */
function shop_record_charge(array $c): void {
    $uid = (int) $c['user_id']; $ref = 'charge:' . $c['id']; $micro = (int) $c['amount'] * 10000;
    if (!empty($c['feature'])) { shop_record($uid, (string) $c['feature'], (string) $c['title'], $micro, (int) ($c['feature_days'] ?: 30), 'card', $ref, entitlement_until($uid, (string) $c['feature'])); shop_receipt_email($uid, (string) $c['title'], $micro); }
    elseif (!empty($c['topup_micro']) && str_starts_with((string) $c['description'], 'AI usage pack')) { shop_record($uid, 'pack', (string) $c['title'], $micro, null, 'card', $ref, null); shop_receipt_email($uid, (string) $c['title'], $micro); }
}
function shop_history(int $uid, int $limit = 20): array { return all('SELECT * FROM {shop_purchases} WHERE user_id=? ORDER BY id DESC LIMIT ' . (int) $limit, [$uid]); }
function shop_receipt_no(array $p): string { return 'R' . gmdate('ymd', strtotime((string) $p['created_at'])) . '-' . str_pad((string) $p['id'], 5, '0', STR_PAD_LEFT); }
function shop_receipt_email(int $uid, string $title, int $micro): void {
    try { notify_email($uid, 'shop.receipt', ['title' => $title, 'amount' => micro_to_inr($micro), 'link' => abs_url('/plugins')]); } catch (Throwable $e) { log_error($e); }
}

// ============================ POST handlers (person) ============================
function do_shop_buy(array $u): string {
    $key = in_str('key', 40); $nonce = in_str('nonce', 64); $r = shop_buy((int) $u['id'], $key, $nonce); $it = shop_item($key);
    if ($r['status'] === 'already') { return 'Already done: ' . ($it['name'] ?? 'that plugin') . ' is yours.'; }
    shop_receipt_email((int) $u['id'], shop_title($it ?? ['kind' => 'feature', 'name' => $key, 'days' => 0]), (int) (($it['price'] ?? 0) * 10000));
    return ($it['name'] ?? 'Plugin') . ' is on' . ($r['until'] ? ' until ' . date_label((string) $r['until']) : '') . '. Receipt saved below.';
}
function do_shop_topup_buy(array $u): ?string {
    $amt = in_money('amount', true, false, false, 'Amount'); if ($amt < 1000 || $amt > 5000000) { throw new UserError('Top up between ₹10 and ₹50,000.'); }
    $id = shop_start_checkout((int) $u['id'], in_str('key', 40), 'wallet', $amt); $GLOBALS['__redirect'] = url('/dues', ['charge' => $id]); return 'Pay the charge below. Your purchase completes automatically once it is confirmed.';
}
function do_shop_card(array $u): ?string { $id = shop_start_checkout((int) $u['id'], in_str('key', 40), 'card'); $GLOBALS['__redirect'] = url('/dues', ['charge' => $id]); return 'Pay the charge below to unlock it.'; }
function do_shop_pack(array $u): ?string { $id = shop_start_checkout((int) $u['id'], in_str('key', 40), 'card'); $GLOBALS['__redirect'] = url('/dues', ['charge' => $id]); return 'Pay the charge below to add the credit to your wallet.'; }

// ============================ POST handlers (admin) ============================
function shop_post_arr(string $k): array { $v = $_POST[$k] ?? []; return is_array($v) ? $v : []; }
function shop_money(mixed $v, string $label): int { $v = trim((string) $v); if ($v === '') { return 0; } $p = parse_money($v); if ($p === null || $p > 5000000) { throw new UserError($label . ': enter an amount in rupees, up to ₹50,000.'); } return $p; }
function do_admin_models_save(array $u): string {
    $cur = ai_models_cfg(); $out = []; $state = shop_post_arr('state'); $name = shop_post_arr('name'); $tag = shop_post_arr('tag'); $mi = shop_post_arr('mult_in'); $mo = shop_post_arr('mult_out'); $th = shop_post_arr('thinking'); $vi = shop_post_arr('vision'); $price = shop_post_arr('price'); $days = shop_post_arr('days');
    $new = trim((string) ($_POST['new_id'] ?? '')); $ids = array_keys($cur);
    if ($new !== '') { if (!preg_match('/^claude-[a-z0-9.\-]{2,20}$/', $new)) { throw new UserError('A model id looks like claude-sonnet-5-5 (letters, digits, dots and dashes, up to 26 characters).'); } if (!in_array($new, $ids, true)) { $ids[] = $new; $state[$new] = 'hidden'; $name[$new] = trim((string) ($_POST['new_name'] ?? '')) ?: $new; } }
    $prices = [];
    foreach ($ids as $id) {
        $s = (string) ($state[$id] ?? $cur[$id]['state'] ?? 'allowed'); if (!in_array($s, ['allowed', 'premium', 'hidden'], true)) { throw new UserError('Pick Allowed, Premium or Hidden for each model.'); }
        $t = (string) ($th[$id] ?? 'adaptive'); if (!in_array($t, ['adaptive', 'budget', 'none'], true)) { throw new UserError('Unknown thinking mode.'); }
        foreach ([['mult_in', $mi], ['mult_out', $mo]] as [$l, $arr]) { $v = trim((string) ($arr[$id] ?? '1')); if ($v === '' || !is_numeric($v) || (float) $v < 0 || (float) $v > 100) { throw new UserError('Price multipliers are numbers between 0 and 100, e.g. 1 or 1.5.'); } }
        $pr = shop_money($price[$id] ?? '', 'Plugin price'); $dy = (int) ($days[$id] ?? 30); if ($dy < 1 || $dy > 366) { throw new UserError('Plugin days must be between 1 and 366.'); } if ($s === 'premium' && $pr <= 0) { throw new UserError(trim((string) ($name[$id] ?? $id)) . ' is premium, so it needs a plugin price.'); }
        $out[$id] = ['state' => $s, 'name' => mb_substr(trim((string) ($name[$id] ?? '')) ?: $id, 0, 40), 'tag' => mb_substr(trim((string) ($tag[$id] ?? '')), 0, 24), 'mult_in' => round((float) ($mi[$id] ?? 1), 3), 'mult_out' => round((float) ($mo[$id] ?? 1), 3), 'thinking' => $t, 'vision' => !empty($vi[$id])];
        $prices[$id] = [$pr, $dy];
    }
    $def = (string) ($_POST['default_model'] ?? ''); if (!isset($out[$def]) || $out[$def]['state'] !== 'allowed') { throw new UserError('The default model must be one that is Allowed (free for everyone).'); }
    foreach ($out as $id => $m) { if ($m['state'] === 'premium' || isset($cur[$id])) { [$pr, $dy] = $prices[$id]; if ($pr > 0) { set_setting('feat_' . model_feature($id) . '_price', (string) $pr); } set_setting('feat_' . model_feature($id) . '_days', (string) $dy); } }
    set_setting('ai_models', json_encode($out, JSON_UNESCAPED_UNICODE)); set_setting('ai_model', $def); audit('admin.models', 'site', $def); return 'Models saved.';
}
function do_admin_efforts_save(array $u): string {
    $eff = shop_post_arr('eff'); $mt = shop_post_arr('max_tokens'); $bd = shop_post_arr('budget'); $out = [];
    foreach (AI_EFFORT_DEFAULT as $label => $d) {
        $e = (string) ($eff[$label] ?? $d['effort']); if (!in_array($e, AI_API_EFFORTS, true)) { throw new UserError('Unknown effort level.'); } $m = (int) ($mt[$label] ?? 0); $b = (int) ($bd[$label] ?? 0);
        if ($m < 200 || $m > 32000) { throw new UserError($label . ': max tokens must be between 200 and 32,000.'); } if ($b < 0 || $b > 30000) { throw new UserError($label . ': the thinking budget must be between 0 and 30,000.'); } $out[$label] = ['effort' => $e, 'max_tokens' => $m, 'budget' => $b];
    }
    $cmds = []; foreach (preg_split('/\r?\n/', (string) ($_POST['commands'] ?? '')) ?: [] as $ln) { if (trim($ln) === '') { continue; } $p = array_map('trim', explode('|', $ln, 3)); if (count($p) < 3 || !preg_match('/^[a-z][a-z0-9_-]{1,15}$/', $p[0]) || $p[2] === '') { throw new UserError('Slash commands look like: key | short description | instruction. The key is lowercase letters, digits, - or _. Problem line: ' . mb_substr($ln, 0, 40)); } $cmds[] = ['key' => $p[0], 'description' => mb_substr($p[1], 0, 60), 'prompt' => mb_substr($p[2], 0, 600)]; }
    set_setting('ai_efforts', json_encode($out)); set_setting('ai_commands', json_encode($cmds, JSON_UNESCAPED_UNICODE)); set_setting('ai_web_search', !empty($_POST['ai_web_search']) ? '1' : '0'); audit('admin.efforts', 'site'); return 'Effort map saved.';
}
function do_admin_catalog_save(array $u): string {
    $items = shop_catalog(null, true); $mode = shop_post_arr('mode'); $price = shop_post_arr('price'); $days = shop_post_arr('days'); $unit = shop_post_arr('unit'); $credit = shop_post_arr('credit'); $hidden = shop_post_arr('hidden'); $sort = shop_post_arr('sort'); $name = shop_post_arr('name'); $desc = shop_post_arr('desc');
    $rows = []; $meta = []; $packs = shop_json('shop_packs'); $seed = $packs ? [] : shop_default_packs();
    foreach ($items as $it) {
        $k = $it['key']; $label = $it['name']; $pr = shop_money($price[$k] ?? '', $label . ' price'); $meta[$k] = ['name' => mb_substr(trim((string) ($name[$k] ?? '')), 0, 60), 'desc' => mb_substr(trim((string) ($desc[$k] ?? '')), 0, 200), 'hidden' => !empty($hidden[$k]) ? 1 : 0, 'sort' => max(-9999, min(9999, (int) ($sort[$k] ?? 0)))];
        if ($it['kind'] === 'pack') { $cr = shop_money($credit[$k] ?? '', $label . ' credit'); if (empty($hidden[$k]) && ($pr <= 0 || $cr <= 0)) { throw new UserError($label . ' needs a price and a credit amount.'); } $rows[$k] = ['pack', $pr, $cr]; continue; }
        $m = (string) ($mode[$k] ?? $it['mode']); if (!in_array($m, features()[$k]['modes'] ?? ['free'], true)) { throw new UserError($label . ' cannot be sold that way.'); } $dy = (int) ($days[$k] ?? 30); if ($dy < 1 || $dy > 366) { throw new UserError($label . ': period must be 1 to 366 days.'); }
        if ($m === 'paid' && $pr <= 0 && empty($hidden[$k])) { throw new UserError($label . ' is marked paid, so it needs a price (or hide it).'); } $un = in_array('payg', features()[$k]['modes'], true) ? post_micro_val($unit[$k] ?? '') : null; $rows[$k] = [$m, $pr, $dy, $un];
    }
    foreach ($rows as $k => $r) {
        if ($r[0] === 'pack') { continue; } [$m, $pr, $dy, $un] = $r; set_setting("feat_{$k}_mode", $m); set_setting("feat_{$k}_price", (string) $pr); set_setting("feat_{$k}_days", (string) $dy); if ($un !== null) { set_setting("feat_{$k}_unit", (string) $un); }
    }
    $list = []; foreach (($packs ?: array_values($seed)) as $p) { $full = 'pack_' . preg_replace('/^pack_/', '', (string) $p['key']); if (!isset($rows[$full])) { $list[] = $p; continue; } $p['key'] = preg_replace('/^pack_/', '', (string) $p['key']); $p['price'] = $rows[$full][1]; $p['credit'] = $rows[$full][2]; $p['hidden'] = !empty($meta[$full]['hidden']); $list[] = $p; }
    if ($packs || $seed) { set_setting('shop_packs', json_encode($list, JSON_UNESCAPED_UNICODE)); set_setting('shop_packs_seeded', '1'); }
    set_setting('shop_meta', json_encode($meta, JSON_UNESCAPED_UNICODE)); audit('admin.catalog', 'site'); return 'Catalog saved.';
}
function post_micro_val(mixed $v): int { $v = trim((string) $v); if ($v === '') { return 0; } $p = parse_money($v); if ($p === null) { throw new UserError('Enter a valid per-use price.'); } return $p * 10000; }
function do_admin_plugin_add(array $u): string {
    $k = strtolower(trim(in_str('key', 25))); $label = in_line('label', 60, true, 'Name'); if (!preg_match('/^[a-z][a-z0-9_]{1,24}$/', $k) || str_starts_with($k, 'mdl_') || str_starts_with($k, 'pack_')) { throw new UserError('The key is lowercase letters, digits and underscores, starting with a letter (2 to 25 characters).'); }
    if (isset(FEATURES[$k])) { throw new UserError('That key is already a built-in plugin.'); } $c = shop_json('shop_custom'); foreach ($c as $r) { if (($r['key'] ?? '') === $k) { throw new UserError('That key already exists.'); } }
    if (count($c) >= 40) { throw new UserError('That is plenty of plugins already.'); } $c[] = ['key' => $k, 'label' => $label, 'desc' => in_str('desc', 200, false)]; set_setting('shop_custom', json_encode($c, JSON_UNESCAPED_UNICODE)); audit('admin.plugin.add', $k); return 'Plugin added. Set its mode and price in the catalog above.';
}
function do_admin_plugin_remove(array $u): string { $k = in_str('key', 25); $c = array_values(array_filter(shop_json('shop_custom'), fn($r) => ($r['key'] ?? '') !== $k)); set_setting('shop_custom', json_encode($c, JSON_UNESCAPED_UNICODE)); audit('admin.plugin.remove', $k); return 'Plugin removed.'; }
function do_admin_pack_add(array $u): string {
    $k = strtolower(trim(in_str('key', 25))); if (!preg_match('/^[a-z][a-z0-9_]{1,24}$/', $k)) { throw new UserError('The key is lowercase letters, digits and underscores.'); } $price = shop_money($_POST['price'] ?? '', 'Price'); $credit = shop_money($_POST['credit'] ?? '', 'Credit'); if ($price <= 0 || $credit <= 0) { throw new UserError('Enter a price and the credit it gives.'); }
    $c = shop_json('shop_packs') ?: array_map(fn($p) => ['key' => preg_replace('/^pack_/', '', $p['key'])] + $p, array_values(shop_default_packs())); foreach ($c as $r) { if (($r['key'] ?? '') === $k) { throw new UserError('That key already exists.'); } }
    $c[] = ['key' => $k, 'label' => in_line('label', 60, true, 'Name'), 'desc' => 'Adds ' . micro_to_inr($credit * 10000) . ' of AI credit to your wallet.', 'price' => $price, 'credit' => $credit, 'hidden' => false]; set_setting('shop_packs', json_encode($c, JSON_UNESCAPED_UNICODE)); set_setting('shop_packs_seeded', '1'); audit('admin.pack.add', $k); return 'Pack added.';
}
function do_admin_pack_remove(array $u): string { $k = in_str('key', 25); $c = array_values(array_filter(shop_json('shop_packs'), fn($r) => ($r['key'] ?? '') !== $k)); set_setting('shop_packs', json_encode($c, JSON_UNESCAPED_UNICODE)); set_setting('shop_packs_seeded', '1'); audit('admin.pack.remove', $k); return 'Pack removed.'; }
