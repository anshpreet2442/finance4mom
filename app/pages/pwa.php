<?php
declare(strict_types=1);

function page_manifest(): void {
    $b = base_path();
    header('Content-Type: application/manifest+json; charset=utf-8'); header('Cache-Control: no-cache');
    echo json_encode([
        'name' => APP_NAME, 'short_name' => 'MomsFin', 'description' => 'Plan every rupee: income, expenses, savings, bills and dues.', 'id' => ($b ?: '') . '/', 'start_url' => ($b ?: '') . '/?source=pwa', 'scope' => ($b ?: '') . '/',
        'display' => 'standalone', 'display_override' => ['standalone', 'minimal-ui'], 'orientation' => 'portrait-primary', 'background_color' => '#8a1100', 'theme_color' => '#d23d09', 'lang' => 'en-IN', 'categories' => ['finance', 'productivity'],
        'icons' => [['src' => $b . '/assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'], ['src' => $b . '/assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'], ['src' => $b . '/assets/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable']],
        'shortcuts' => [['name' => 'Add transaction', 'url' => ($b ?: '') . '/transactions?add=1', 'icons' => [['src' => $b . '/assets/icons/icon-192.png', 'sizes' => '192x192']]], ['name' => 'Analysis', 'url' => ($b ?: '') . '/analysis'], ['name' => 'Dues', 'url' => ($b ?: '') . '/dues']],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
function page_sw(): void {
    $a = glob(__DIR__ . '/../../assets/*.{css,js}', GLOB_BRACE) ?: []; $build = substr(md5(implode('|', array_map(fn($f) => basename($f) . filemtime($f), $a)) . filemtime(__DIR__ . '/../sw.js') . SCHEMA_VERSION), 0, 10);
    header('Content-Type: application/javascript; charset=utf-8'); header('Cache-Control: no-cache'); header('Service-Worker-Allowed: ' . (base_path() ?: '') . '/');
    echo str_replace(['__BUILD__', '__BASE__'], [$build, base_path()], (string) file_get_contents(__DIR__ . '/../sw.js'));
}
function page_offline(): void {
    header('Cache-Control: no-cache'); ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline · <?= e(APP_NAME) ?></title><meta name="theme-color" content="#d23d09">
<script>try{if(matchMedia('(prefers-color-scheme: dark)').matches)document.documentElement.classList.add('dark')}catch(e){}</script><link rel="stylesheet" href="<?= e(asset('app.css')) ?>"></head>
<body class="bg-page text-ink"><main class="m-hero grid min-h-dvh place-items-center rounded-none px-6 text-center"><div class="max-w-sm">
<span class="mx-auto grid size-24 place-items-center rounded-full m-glass"><?= icon('cloud-off', 'size-11') ?></span><h1 class="mt-6 text-3xl font-semibold tracking-tight">You are offline</h1>
<p class="mt-3 text-white/85">This page was not saved on your phone yet. Pages you have opened before still work, and anything you add offline will sync by itself when you reconnect.</p>
<div class="mt-7 flex justify-center gap-3"><button class="btn border-0 bg-white font-semibold text-[#7a1300]" onclick="location.reload()">Try again</button><a class="btn border-white/30 bg-white/10 text-white" href="<?= e(url('/')) ?>">Home</a></div></div></main></body></html><?php
}
function page_credits(): void {
    $lines = [['h', 'Moms Finance'], ['big', 'Plan every rupee'], ['h', 'Designed & developed by'], ['big', 'Anshpreet Singh'], ['p', 'Product design · Interface · Front-end'], ['p', 'Back-end · Database · Accounting rules'], ['p', 'Payments integration · Offline sync · Testing'],
        ['h', 'Built with'], ['p', 'PHP · MySQL · Tailwind CSS · Lucide icons'], ['p', 'PayU payment gateway'], ['h', 'Principles'], ['p', 'Every rupee accounted for, never counted twice'], ['p', 'Your data stays yours'], ['p', 'Clear rules, no surprises'],
        ['h', 'Special thanks'], ['big', 'Mom'], ['p', 'for the reasons this app exists'], ['p', 'No bank was harmed in the making of this app'], ['h', ''], ['p', '© ' . date('Y') . ' Anshpreet Singh']];
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Credits · <?= e(APP_NAME) ?></title><meta name="theme-color" content="#0b0504">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>"></head><body class="bg-black text-white">
<main class="credits-stage" id="stage" aria-label="End credits"><div class="credits-roll" id="roll">
<?php foreach ($lines as [$k, $t]): if ($k === 'h'): ?><h2><?= e($t) ?></h2><?php elseif ($k === 'big'): ?><p class="big"><?= e($t) ?></p><?php else: ?><p class="text-white/80"><?= e($t) ?></p><?php endif; endforeach; ?>
<div style="height:40vh"></div></div><div class="credits-fade t"></div><div class="credits-fade b"></div>
<div class="absolute inset-x-0 top-0 z-10 flex items-center justify-between p-4"><a href="<?= e(url('/')) ?>" class="btn border-white/25 bg-white/10 text-white">← Back to app</a><div class="flex gap-2"><button id="pause" class="btn border-white/25 bg-white/10 text-white">Pause</button><button id="replay" class="btn border-white/25 bg-white/10 text-white">Replay</button></div></div></main>
<script>(function(){var s=document.getElementById('stage'),r=document.getElementById('roll'),p=document.getElementById('pause');var rm=matchMedia('(prefers-reduced-motion: reduce)').matches;
function size(){r.style.setProperty('--roll',Math.max(30,Math.round(r.scrollHeight/46))+'s')}size();addEventListener('resize',size);
p.onclick=function(){s.classList.toggle('is-paused');p.textContent=s.classList.contains('is-paused')?'Resume':'Pause'};
document.getElementById('replay').onclick=function(){r.style.animation='none';void r.offsetWidth;r.style.animation='';s.classList.remove('is-paused');p.textContent='Pause'};
s.addEventListener('touchstart',function(){s.classList.add('is-paused')},{passive:true});s.addEventListener('touchend',function(){if(p.textContent==='Pause')s.classList.remove('is-paused')},{passive:true});
r.addEventListener('animationend',function(){if(!rm)r.style.opacity='.0'})})();</script></body></html><?php
}

/** Tiny JSON snapshot polled by the page every ~45 s so numbers and badges stay live without a reload. */
function page_pulse(): void {
    $u = require_login(); $s = snap(); billing_sync((int) $u['id']); header('Content-Type: application/json'); header('Cache-Control: no-store');
    $cash = total_cash($s['accounts'], $s['txs'], $s['today']); $overdue = 0; foreach (occurrence_views($s) as $o) { if ($o['overdue'] && !$o['skipped']) { $overdue++; } }
    echo json_encode(['cash' => $cash, 'spendable' => $cash - reserved_total($s['contribs']), 'owed' => outstanding_total((int) $u['id']), 'dues' => (int) val("SELECT COUNT(*) FROM {charges} WHERE user_id=? AND status='pending' AND due_date <= ?", [$u['id'], day_after_n(today(), 3)]),
        'overdue' => $overdue, 'unread' => (int) val('SELECT COUNT(*) FROM {notifications} WHERE user_id=? AND is_read=0', [$u['id']]), 'level' => billing_level($u), 't' => time()]);
}
