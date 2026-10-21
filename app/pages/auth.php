<?php
declare(strict_types=1);

function auth_shell(string $title, string $heading, string $sub, string $form, string $footer): void {
    $nonce = '';
    ?><!doctype html><html lang="en" data-theme="system"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<script>try{if(matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.classList.add('dark')}catch(e){}</script>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>"><link rel="stylesheet" href="<?= e(asset('polish.css')) ?>"></head>
<body class="bg-page text-ink antialiased" data-base="<?= e(base_path()) ?>" data-csrf="<?= e(csrf_token()) ?>"><main class="grid min-h-dvh place-items-center px-4 py-10"><div class="w-full max-w-md">
<div class="mb-6 flex items-center justify-center gap-2.5 text-2xl font-bold tracking-tight"><span class="grid size-10 place-items-center rounded-2xl bg-lime text-ink"><?= icon('trending-up', 'size-6') ?></span><?= e(APP_NAME) ?></div>
<div class="card p-7 sm:p-8"><h1 class="text-2xl font-semibold tracking-tight"><?= e($heading) ?></h1><p class="mb-6 mt-1 text-sm text-mute"><?= e($sub) ?></p>
<?php foreach (take_flash() as [$t, $m]): ?><div class="mb-4 rounded-2xl px-4 py-3 text-sm <?= $t === 'err' ? 'bg-neg/10 text-neg' : 'bg-lime/20 text-lime-dk' ?>" role="<?= $t === 'err' ? 'alert' : 'status' ?>"><?= e($m) ?></div><?php endforeach; ?>
<?= $form ?></div><p class="mt-5 text-center text-sm text-mute"><?= $footer ?></p></div></main><script src="<?= e(asset('passkey.js')) ?>" defer></script></body></html><?php
}
function page_login(): void {
    if (current_user()) { redirect(url('/')); }
    $first = (int) val('SELECT COUNT(*) FROM {users}') === 0;
    if ($first) { redirect(url('/register')); }
    $f = form_open('login', 'grid grid-cols-2 gap-4') . field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email']) . field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'autocomplete' => 'current-password'])
        . '<button class="btn btn-dark col-span-2 mt-1 w-full">Sign in</button></form>'
        . '<div data-passkey-ui class="hidden"><div class="my-5 flex items-center gap-3 text-xs text-mute"><span class="h-px flex-1 bg-line"></span>or<span class="h-px flex-1 bg-line"></span></div>'
        . '<button type="button" data-passkey-login class="btn w-full">' . icon('key-round', 'size-5') . ' Sign in with a passkey</button><p class="mt-2 text-center text-xs text-mute">Use your fingerprint, face or screen lock. No password needed.</p><div id="pk-msg" hidden></div></div>';
    $foot = site_setting('registration_open', '1') === '1' ? 'New here? <a class="font-medium text-ink underline" href="' . e(url('/register')) . '">Create an account</a>' : 'Registration is closed. Ask the administrator for an account.';
    auth_shell('Sign in', 'Welcome back', 'Sign in to see where your money stands.', str_replace('grid-cols-2 gap-x-3 gap-y-4', '', $f), $foot);
}
function page_register(): void {
    if (current_user()) { redirect(url('/')); }
    $first = (int) val('SELECT COUNT(*) FROM {users}') === 0;
    if (!$first && site_setting('registration_open', '1') !== '1') { auth_shell('Registration closed', 'Registration is closed', 'Ask the administrator to create an account for you.', '', '<a class="font-medium text-ink underline" href="' . e(url('/login')) . '">Back to sign in</a>'); return; }
    $f = form_open('register', 'grid grid-cols-2 gap-4') . field(['name' => 'name', 'label' => 'Your name', 'required' => true, 'autocomplete' => 'name']) . field(['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'autocomplete' => 'email'])
        . field(['name' => 'password', 'label' => 'Password', 'type' => 'password', 'required' => true, 'hint' => 'At least 10 characters.', 'autocomplete' => 'new-password']) . field(['name' => 'confirm', 'label' => 'Confirm password', 'type' => 'password', 'required' => true, 'autocomplete' => 'new-password'])
        . '<button class="btn btn-dark col-span-2 mt-1 w-full">' . ($first ? 'Create administrator account' : 'Create account') . '</button></form>';
    auth_shell('Create account', $first ? 'Set up your installation' : 'Create your account', $first ? 'The first account becomes the administrator. Database tables are already created.' : 'Your data stays in your own database. No bank credentials are ever requested.', $f,
        $first ? '' : 'Already have an account? <a class="font-medium text-ink underline" href="' . e(url('/login')) . '">Sign in</a>');
}
