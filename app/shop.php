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
    if (!$o && site_setting('shop_packs_seeded', '') === '') { return ['small' => ['key' => 'pack_small', 'label' => 'AI usage pack · 500', 'desc' => 'Adds ₹550 of AI credit to your wallet (10% bonus).', 'price' => 50000, 'credit' => 55000, 'hidden' => false], 'big' => ['key' => 'pack_big', 'label' => 'AI usage pack · 1000', 'desc' => 'Adds ₹1,200 of AI credit to your wallet (20% bonus).', 'price' => 100000, 'credit' => 120000, 'hidden' => false]]; }
    return $o;
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
    foreach (shop_packs() as $p) { $mt = is_array($meta[$p['key']] ?? null) ? $meta[$p['key']] : []; $hidden = $p['hidden'] || !empty($mt['hidden']); if ($hidden && !$withHidden) { continue; }
        $items[] = ['key' => $p['key'], 'kind' => 'pack', 'name' => $p['label'], 'desc' => $p['desc'], 'mode' => 'pack', 'price' => $p['price'], 'days' => 0, 'credit_micro' => $p['credit'] * 10000, 'state' => 'pack', 'until' => null, 'hidden' => $hidden, 'sort' => (int) ($mt['sort'] ?? 900), 'buyable' => true, 'admin_included' => false]; }
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
