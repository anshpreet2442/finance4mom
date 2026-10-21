<?php
declare(strict_types=1);
/** Admin → Telegram: bot token, webhook, persona, reminder templates, log. */

function page_admin_telegram(): void {
    $me = require_admin(); admin_start('Telegram', 'telegram'); $c = tg_cfg(); $h = tg_health(); $conf = tg_configured($c); $url = tg_webhook_url(); $mine = tg_link_for((int) $me['id']);
    $stat = !$c['enabled'] ? badge('Off', 'warn') : ($conf ? (!empty($h['ok']) && !empty($h['matches']) ? badge('Live', 'pos') : badge('Register the webhook', 'warn')) : badge('Not configured', 'neg'));
    $linked = (int) val('SELECT COUNT(*) FROM {telegram_links}'); $msgs = (int) val("SELECT COUNT(*) FROM {telegram_log} WHERE direction='in' AND status='ok' AND created_at >= ?", [gmdate('Y-m-d H:i:s', time() - 86400)]);
    echo '<div class="grid gap-5 lg:grid-cols-2">';
    card_open('Telegram bot', 'Let people check money, log expenses and chat with their advisor in Telegram. ' . $stat);
    echo '<div class="mb-4 grid grid-cols-2 gap-3 text-sm"><div class="rounded-2xl bg-soft p-3"><p class="text-xs text-mute">Connected people</p><p class="num text-xl font-semibold">' . $linked . '</p></div><div class="rounded-2xl bg-soft p-3"><p class="text-xs text-mute">Messages, last 24 h</p><p class="num text-xl font-semibold">' . $msgs . '</p></div></div>';
    echo simple_form('admin-telegram-settings', [
        ['name' => 'tg_enabled', 'label' => 'Telegram bot is on (global switch)', 'type' => 'checkbox', 'value' => $c['enabled']],
        secret_field('tg_bot_token', 'Bot token', $c['token'], 'From @BotFather. Stored encrypted on the server, never shown again. Or set TELEGRAM_BOT_TOKEN in the environment.'),
        ['name' => 'tg_bot_token_clear', 'label' => 'Remove the saved token', 'type' => 'checkbox', 'value' => false],
        ['name' => 'tg_bot_username', 'label' => 'Bot username', 'value' => $c['username'], 'placeholder' => 'MomsFinanceBot', 'autocomplete' => 'off', 'hint' => 'Without the @. People connect through https://t.me/<username>.'],
        ['name' => 'tg_notify', 'label' => 'Allow bill and payment reminders in Telegram (people opt in themselves)', 'type' => 'checkbox', 'value' => $c['notify']],
        ['name' => 'tg_open_mode', 'label' => 'Open app pages from buttons', 'type' => 'select', 'required' => true, 'options' => ['url' => 'In the browser', 'webapp' => 'Inside Telegram (web app)'], 'value' => $c['open_mode']],
        ['name' => 'tg_rate_min', 'label' => 'Messages per minute, per chat', 'type' => 'number', 'min' => 1, 'max' => 120, 'half' => true, 'value' => $c['rate_min']],
        ['name' => 'tg_rate_day', 'label' => 'Messages per day, per chat', 'type' => 'number', 'min' => 10, 'max' => 5000, 'half' => true, 'value' => $c['rate_day'], 'hint' => 'AI messages also follow the limits on the AI agent page.'],
    ], 'Save Telegram settings'); card_close();

    echo '<div class="space-y-5">'; card_open('Webhook', 'Telegram sends every message to this address, signed with a secret header.');
    echo '<dl class="space-y-2 text-sm"><div><dt class="text-xs text-mute">Address Telegram will call</dt><dd class="break-all font-mono text-xs">' . e($url) . '</dd></div>'
        . '<div><dt class="text-xs text-mute">Secret header</dt><dd>' . (secret_get('tg_webhook_secret', 'TELEGRAM_WEBHOOK_SECRET') !== '' ? badge('Generated', 'pos') . ' <span class="text-xs text-mute">stored encrypted</span>' : badge('Not generated yet', 'warn') . ' <span class="text-xs text-mute">created when you register</span>') . '</dd></div>';
    if ($h) {
        if (!empty($h['ok'])) {
            echo '<div><dt class="text-xs text-mute">Bot</dt><dd>@' . e($h['bot'] ?? '') . ' <span class="text-mute">(' . e($h['bot_name'] ?? '') . ')</span></dd></div>'
                . '<div><dt class="text-xs text-mute">Registered address</dt><dd class="break-all font-mono text-xs">' . ($h['webhook'] !== '' ? e($h['webhook']) . ' ' . (!empty($h['matches']) ? badge('Matches', 'pos') : badge('Different', 'warn')) : '<span class="text-mute">none</span>') . '</dd></div>'
                . '<div><dt class="text-xs text-mute">Waiting updates</dt><dd class="num">' . (int) ($h['pending'] ?? 0) . '</dd></div>'
                . (!empty($h['last_error']) ? '<div><dt class="text-xs text-mute">Last delivery error' . (!empty($h['last_error_at']) ? ' (' . e($h['last_error_at']) . ' UTC)' : '') . '</dt><dd class="text-neg">' . e($h['last_error']) . '</dd></div>' : '');
        } else { echo '<div><dt class="text-xs text-mute">Last check failed</dt><dd class="text-neg">' . e($h['error'] ?? 'Unknown error') . '</dd></div>'; }
        echo '<p class="text-xs text-mute">Checked ' . e($h['at'] ?? '') . ' UTC</p>';
    }
    echo '</dl>';
    if (!tg_https($url)) { echo '<p class="mt-3 rounded-2xl bg-soft p-3 text-xs text-mute">' . icon('shield-check', 'size-4 inline') . ' Telegram only delivers to a public <strong>https</strong> address. Set <code>APP_URL</code> in config.php (or MFNC_APP_URL) to your site address first.</p>'; }
    echo '<div class="mt-4 flex flex-wrap gap-2">' . post_btn(icon('plug', 'size-4') . ' Register webhook', 'admin-telegram-register', [], 'btn btn-dark btn-sm') . post_btn('Check now', 'admin-telegram-check', [], 'btn btn-sm')
        . post_btn('New secret &amp; register', 'admin-telegram-register', ['rotate' => 1], 'btn btn-ghost btn-sm') . confirm_btn('Remove webhook', 'admin-telegram-unregister', [], 'Remove the webhook?', 'The bot stops receiving messages until you register it again.', 'Remove', 'btn btn-ghost btn-sm text-neg') . '</div>';
    card_close();
    card_open('Send a test message', $mine ? 'Goes to your own connected Telegram chat.' : 'Connect your own Telegram first (Settings → Connections).');
    echo post_btn(icon('send', 'size-4') . ' Send test to me', 'admin-telegram-test', [], 'btn btn-dark btn-sm', $mine ? '' : 'disabled aria-disabled="true"'); card_close();
    card_open('Set it up', ''); echo '<ol class="list-decimal space-y-1.5 pl-5 text-sm text-mute"><li>In Telegram open <strong>@BotFather</strong>, send <code>/newbot</code>, choose a name and a username ending in <code>bot</code>.</li><li>Paste the token above and the username, switch the bot on, save.</li><li>Press <strong>Register webhook</strong>. The address must be public https.</li><li>Connect yourself in Settings, then send the test message.</li><li>Optional: in BotFather use <code>/setprivacy</code> and <code>/setjoingroups</code> (disable) so the bot only works in private chats.</li></ol>'; card_close();
    echo '</div></div>';

    echo '<div class="mt-5 grid gap-5 lg:grid-cols-2">'; card_open('AI persona (Telegram AI plugin)', 'How the advisor sounds in Telegram. The web advisor keeps its own prompt on the AI agent page.');
    $pt = $c['persona'] !== '' ? $c['persona'] : tg_default_persona();
    echo form_open('admin-telegram-persona', 'space-y-3') . '<div><label class="label" for="tg-persona">Persona prompt</label><textarea class="input font-mono text-xs" id="tg-persona" name="tg_persona" rows="16" maxlength="8000">' . e($pt) . '</textarea></div>' . vars_help(TG_PERSONA_VARS)
        . '<p class="text-xs text-mute">Safety rules (no tax evasion, buttons, previews before changes) are always added after your text and cannot be edited out.</p>'
        . '<div class="grid grid-cols-2 gap-3"><div><label class="label" for="tg-max">Max reply length (characters)</label><input class="input" id="tg-max" name="tg_max_reply" type="number" min="120" max="3000" value="' . (int) $c['max_reply'] . '"></div><div><label class="label" for="tg-mem">Remember last (turns)</label><input class="input" id="tg-mem" name="tg_memory_turns" type="number" min="2" max="40" value="' . (int) $c['memory_turns'] . '"></div></div>'
        . '<div class="flex flex-wrap justify-between gap-2"><button class="btn btn-ghost btn-sm" name="reset" value="1" formnovalidate>Reset to default</button><button class="btn btn-dark">Save persona</button></div></form>'; card_close();

    echo '<div class="space-y-5">'; card_open('Reminder messages', 'Sent in Telegram to people who opted in. Plain text; parameters are filled in per person.');
    $rows = ''; foreach (TG_EVENTS as $ev => $label) { if ($ev === 'test') { continue; }
        $rows .= '<div><label class="label" for="tpl-' . e($ev) . '">' . e($label) . ' <span class="font-mono text-xs font-normal text-mute">' . e($ev) . '</span></label><textarea class="input" id="tpl-' . e($ev) . '" name="tpl[' . e($ev) . ']" rows="3" maxlength="600">' . e(tg_template($ev)) . '</textarea>'
            . '<button class="btn btn-ghost btn-sm mt-1" name="reset" value="' . e($ev) . '" formnovalidate>Reset</button></div>'; }
    $rows .= '<div><label class="label" for="tpl-test">Test message</label><textarea class="input" id="tpl-test" name="tpl[test]" rows="2" maxlength="600">' . e(tg_template('test')) . '</textarea></div>';
    echo form_open('admin-telegram-templates', 'space-y-3') . $rows . vars_help(TEMPLATE_VARS) . '<div class="flex justify-end"><button class="btn btn-dark">Save templates</button></div></form>';
    echo '<div class="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-4">' . post_btn('Send due reminders now', 'admin-telegram-remind', [], 'btn btn-sm') . '<span class="text-xs text-mute">For automatic reminders run <code>php tools/telegram_cron.php</code> from cron once a day.</span></div>'; card_close(); echo '</div></div>';

    echo '<div class="mt-5">'; card_flush_open('Telegram log', 'Latest 50 events. Message text is never stored here.');
    $log = all('SELECT created_at,direction,chat_id,user_id,kind,status,detail FROM {telegram_log} ORDER BY id DESC LIMIT 50');
    if (!$log) { echo '<p class="p-6 text-sm text-mute">Nothing yet.</p>'; } else {
        echo '<div class="relative overflow-x-auto"><table class="w-full min-w-[640px]"><thead><tr class="border-b border-line"><th class="th">When (UTC)</th><th class="th">Dir</th><th class="th">Chat</th><th class="th">Kind</th><th class="th">Status</th></tr></thead><tbody class="divide-y divide-line">';
        foreach ($log as $r) { echo '<tr><td class="td num whitespace-nowrap text-mute">' . e($r['created_at']) . '</td><td class="td">' . ($r['direction'] === 'in' ? 'In' : 'Out') . '</td><td class="td num text-mute">' . e((string) $r['chat_id']) . '</td><td class="td">' . e($r['kind']) . '</td><td class="td">' . badge(ucfirst($r['status']), $r['status'] === 'ok' ? 'pos' : ($r['status'] === 'failed' ? 'neg' : 'warn')) . ($r['detail'] ? '<div class="max-w-[16rem] truncate text-xs text-mute" title="' . e($r['detail']) . '">' . e($r['detail']) . '</div>' : '') . '</td></tr>'; }
        echo '</tbody></table></div>';
    }
    echo '</div></section></div>'; admin_end();
}
