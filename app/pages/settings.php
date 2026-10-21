<?php
declare(strict_types=1);

function page_settings(): void {
    $u = require_login(); $s = snap(); $p = $s['prefs'];
    $accTypes = ['bank' => 'Bank account', 'cash' => 'Cash', 'wallet' => 'Wallet', 'card' => 'Card'];
    $accFields = fn(?array $a = null) => account_fields($a, $s);
    $curOpts = []; foreach (CURRENCIES as $k => $sym) { $curOpts[$k] = $k . ' (' . trim($sym) . ')'; }
    layout_start('Settings', 'settings', $s);
    page_header('Settings', 'Currency, accounts, appearance and your data.');
    echo '<div class="grid gap-5 lg:grid-cols-2">';
    card_open('Currency, locale & appearance');
    echo simple_form('prefs-save', [
        ['name' => 'currency', 'label' => 'Currency', 'type' => 'select', 'required' => true, 'half' => true, 'value' => $p['currency'], 'options' => $curOpts],
        ['name' => 'locale', 'label' => 'Number & date format', 'type' => 'select', 'required' => true, 'half' => true, 'value' => $p['locale'], 'options' => LOCALES, 'hint' => 'Preview: ' . fmt(12345678)],
        ['name' => 'theme', 'label' => 'Theme', 'type' => 'select', 'required' => true, 'half' => true, 'value' => $p['theme'], 'options' => ['system' => 'Match my device', 'light' => 'Light', 'dark' => 'Dark']],
        ['name' => 'reminder_days', 'label' => 'Default reminder (days before due)', 'type' => 'number', 'min' => 0, 'max' => 60, 'half' => true, 'value' => $p['reminder_days']],
        ['name' => 'big_purchase_threshold', 'label' => 'Treat purchases above this as big-ticket', 'type' => 'money', 'value' => money_input((int) $p['big_purchase_threshold'])],
    ], 'Save preferences');
    echo '<p class="mt-3 text-xs text-mute">Changing currency or locale only changes how amounts are displayed. Existing amounts are not converted.</p>'; card_close();
    card_open('App & colours', 'Install it on your phone and choose the palette.');
    echo '<p class="mb-2 text-sm font-medium">Colour palette</p>' . jelly(['auto' => ['label' => 'Auto (ember on phones)', 'attrs' => 'data-palette-set="auto"'], 'ember' => ['label' => 'Ember', 'attrs' => 'data-palette-set="ember"'], 'lime' => ['label' => 'Lime', 'attrs' => 'data-palette-set="lime"']], 'auto', ['size' => 'sm', 'label' => 'Colour palette', 'class' => 'palette-jelly']);
    echo ''
        . '<div class="mt-5 flex flex-wrap gap-2"><button type="button" class="btn btn-dark hidden" data-install>' . icon('download', 'size-4') . ' Install app</button><button type="button" class="btn" data-theme-toggle>' . icon('moon', 'size-4') . ' Toggle dark mode</button><button type="button" class="btn" data-reload>' . icon('rotate-cw', 'size-4') . ' Reload app</button><a class="btn" href="' . e(url('/credits')) . '">' . icon('sparkles', 'size-4') . ' Credits</a></div>'
        . '<p class="mt-3 hidden text-xs text-mute" data-ios-hint>On iPhone: tap the Share button, then <strong>Add to Home Screen</strong>.</p>'
        . '<p class="mt-3 text-xs text-mute">Works offline once opened. Changes made offline sync automatically when you reconnect.</p>';
    card_close();
    card_open('Accounts', '', dialog_form('acc-new', 'Add account', 'New account', 'account-save', $accFields(), 'Save', 'btn btn-sm')); echo '<ul class="divide-y divide-line">';
    foreach ($s['accounts'] as $a) {
        echo '<li class="flex items-center justify-between gap-2 py-3 text-sm"><div class="min-w-0"><p class="truncate font-medium">' . e($a['name']) . ($a['archived'] ? ' ' . badge('Archived') : '') . '</p><p class="num text-xs capitalize text-mute">' . e($a['type']) . ' · ' . e(fmt($a['balance'])) . '</p></div><div class="flex shrink-0 gap-1">'
            . dialog_form('acc-' . $a['id'], 'Edit', 'Edit account', 'account-save', $accFields($a), 'Save changes', 'btn btn-ghost btn-sm')
            . ($a['archived'] ? post_btn('Restore', 'account-restore', ['id' => $a['id']], 'btn btn-ghost btn-sm') : confirm_btn('Remove', 'account-delete', ['id' => $a['id']], 'Remove ' . $a['name'] . '?', 'An account with transactions is archived (kept in your history); an empty one is deleted.', 'Remove')) . '</div></li>';
    }
    echo '</ul>'; card_close();
    card_open('Passkeys', 'Sign in with your fingerprint, face or screen lock instead of a password.');
    $pks = all('SELECT id,name,created_at,last_used_at,transports FROM {passkeys} WHERE user_id=? ORDER BY id', [$u['id']]);
    echo '<div id="passkeys">'; if (!$pks) { echo '<p class="text-sm text-mute">No passkeys yet. Add one on this device and you can sign in with a touch next time.</p>'; } else {
        echo '<ul class="divide-y divide-line">'; foreach ($pks as $k) { echo '<li class="flex items-center justify-between gap-3 py-3 text-sm"><div class="min-w-0"><p class="flex items-center gap-2 truncate font-medium">' . icon('key-round', 'size-4') . e($k['name']) . '</p><p class="text-xs text-mute">Added ' . e(date_label(substr($k['created_at'], 0, 10))) . ' · ' . ($k['last_used_at'] ? 'last used ' . e(date_label(substr($k['last_used_at'], 0, 10))) : 'not used yet') . '</p></div>' . confirm_btn('Remove', 'passkey-delete', ['id' => $k['id']], 'Remove this passkey?', 'You will not be able to sign in with it any more. Your password still works.', 'Remove') . '</li>'; } echo '</ul>'; }
    echo '<div data-passkey-ui class="mt-4 hidden flex-wrap items-end gap-2 [&:not(.hidden)]:flex"><div class="min-w-48 flex-1"><label class="label" for="pk-name">Name this passkey</label><input id="pk-name" class="input" maxlength="60" placeholder="e.g. My phone"></div><button type="button" class="btn btn-dark" data-passkey-add>' . icon('plus', 'size-4') . ' Add a passkey</button></div><div id="pk-msg" hidden></div>'
        . '<p data-passkey-ui class="hidden mt-3 text-xs text-mute">Your fingerprint or face never leaves your device. The app stores only a public key.</p><p class="mt-3 hidden text-xs text-mute" id="pk-unsupported">This browser does not support passkeys.</p></div>';
    card_close();
    card_open('Profile & security');
    echo simple_form('profile-save', [['name' => 'name', 'label' => 'Name', 'required' => true, 'value' => $u['name']]], 'Save name') . '<p class="mt-3 text-xs text-mute">Signed in as ' . e($u['email']) . '</p><hr class="my-5 border-line">'
        . simple_form('password-change', [['name' => 'current', 'label' => 'Current password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password'], ['name' => 'next', 'label' => 'New password', 'type' => 'password', 'required' => true, 'hint' => '10 to 72 characters.', 'autocomplete' => 'new-password']], 'Change password')
        . '<p class="mt-4 flex items-start gap-2 text-xs text-mute">' . icon('shield-check', 'size-4 mt-0.5') . 'Passwords are stored as one-way hashes. This app never asks for bank logins and does not connect to any bank.</p>'; card_close();
    card_open('Your data');
    echo '<div class="flex flex-wrap gap-2">' . btn('Transactions (CSV)', url('/transactions/export'), 'btn', 'download') . btn('Full backup (JSON)', url('/settings/backup'), 'btn', 'download') . '</div><p class="mt-2 text-xs text-mute">Both downloads contain only your own data. Amounts in the JSON backup are in paise.</p><hr class="my-5 border-line">'
        . '<p class="text-sm font-semibold">Sample data</p><p class="mb-2 text-xs text-mute">Fills an empty account with six months of example income, expenses, bills, goals and purchases so you can explore. Delete it any time below.</p>' . post_btn('Load sample data', 'demo-load', [], 'btn')
        . '<hr class="my-5 border-line"><p class="text-sm font-semibold text-neg">Danger zone</p><div class="mt-2 flex flex-wrap gap-2">'
        . dialog_form('wipe', 'Delete all financial data', 'Delete all financial data?', 'data-wipe', [['name' => 'confirm', 'label' => 'Type DELETE to confirm', 'required' => true, 'placeholder' => 'DELETE', 'hint' => 'Removes all transactions, bills, goals, purchases and plans. Accounts and categories stay, balances reset to zero.']], 'Delete everything', 'btn btn-danger', true)
        . dialog_form('close-acct', 'Delete my account', 'Delete your account?', 'account-close', [['name' => 'password', 'label' => 'Enter your password', 'type' => 'password', 'required' => true, 'hint' => 'This permanently deletes your login and every record.']], 'Delete account permanently', 'btn btn-danger', true) . '</div>';
    card_close();
    echo '</div>'; layout_end();
}

function page_backup(): void {
    $u = require_login();
    $tables = ['preferences', 'accounts', 'categories', 'budgets', 'transactions', 'recurring_templates', 'bills', 'bill_occurrences', 'goals', 'purchases', 'contributions', 'monthly_plans', 'plan_lines', 'notifications'];
    $out = ['exportedAt' => gmdate('c'), 'user' => ['name' => $u['name'], 'email' => $u['email']], 'note' => 'All money values are integer minor units (paise).'];
    foreach ($tables as $t) { $out[$t] = all("SELECT * FROM {{$t}} WHERE user_id = ?", [$u['id']]); }
    header('Content-Type: application/json'); header('Content-Disposition: attachment; filename="moms-finance-backup.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
