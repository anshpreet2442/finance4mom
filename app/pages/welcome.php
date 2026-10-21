<?php
declare(strict_types=1);
/** Public landing page (/welcome, and / for signed-out visitors). Standalone layout, dark premium style, no third-party requests. */

function landing_tiers(): array {
    $free = []; $paid = []; $payg = [];
    foreach (features() as $f => $m) { $c = feature_cfg($f);
        if ($c['mode'] === 'free') { $free[] = $m['label']; }
        elseif ($c['mode'] === 'paid') { $paid[] = [$m['label'], fmt_inr($c['price']) . ' / ' . $c['days'] . ' days']; }
        else { $payg[] = [$m['label'], $f === 'ai' ? 'billed by usage' : ($c['unit_micro'] ? micro_to_inr($c['unit_micro']) . ' per message' : 'from your wallet')]; } }
    return ['free' => $free, 'paid' => $paid, 'payg' => $payg];
}

function page_welcome(): void {
    $u = current_user(); $t = landing_tiers(); $open = site_setting('registration_open', '1') === '1'; $cta = $u ? url('/') : url($open ? '/register' : '/login'); $ctaLabel = $u ? 'Open your dashboard' : ($open ? 'Get started' : 'Sign in');
    $title = APP_NAME . ': plan every rupee, know where it goes'; $desc = 'A private money app for families in India: budgets, bills, goals, yearly expenses, dues with online payment, offline mode, and an honest AI finance advisor. Free to start.';
    $canon = abs_url('/welcome'); 
    header('Cache-Control: public, max-age=300'); $star = fn($label, $href, $o = []) => star_border($label, $href, $o + ['color' => '#ffffff', 'duration' => 4, 'trail' => 0.3, 'thickness' => 1, 'radius' => 12, 'glow' => 0.6, 'theme' => 'dark', 'hoverLap' => true, 'clickPulse' => true]);
    $feat = [['piggy-bank', 'Plans that add up', 'A monthly plan built from your bills, budgets and goals. It never lets you allocate more than you earn.'], ['bell-ring', 'Bills that never sneak up', 'Recurring bills, yearly renewals and reminders before the due date, with set-aside amounts worked out for you.'],
        ['goal', 'Goals with a date', 'Emergency fund, school fees, a car. See whether you are on track and exactly what to put away each month.'], ['sparkles', 'An advisor that does not flatter you', 'Ask about EMIs, SIPs, old vs new tax regime or where the money went. It reads your data, shows its working, and tells you the truth.'],
        ['wifi', 'Works offline', 'Add expenses on the bus with no signal. They sync by themselves when you are back online.'], ['credit-card', 'Dues & online payment', 'Pay what is owed through a secure checkout, with clear rules for late fees and a receipt by email or SMS.'],
        ['chart-line', 'Reports that read themselves', 'Spending by category, month-on-month trends and a cash-flow forecast, in plain language.'], ['shield-check', 'Private by design', 'No bank logins, no ads, no selling data. Passkeys and a full export of everything, any time.']];
    $faq = [['Is it free?', 'The core app is free. ' . ($t['paid'] || $t['payg'] ? 'Some extras are paid or pay-as-you-go: see the pricing above.' : 'Everything on this page is included.')], ['Does it connect to my bank?', 'No. You add transactions yourself (or import a CSV). The app never asks for bank logins, so there is nothing to leak.'],
        ['Is the AI advisor safe to use?', 'It only reads your data inside your account, never writes without your Apply button, and every change can be undone from a log. It gives general information and will tell you when you need a chartered accountant. It will not help with tax evasion.'], ['Does it work without internet?', 'Yes. Install it to your home screen and add transactions offline; they sync when you reconnect.'],
        ['Can my family use it?', 'Each person has their own private account. An administrator can post shared dues, such as a monthly family contribution, that members pay online.'], ['Where is my data?', 'On the server of whoever runs this installation. You can download everything as CSV or JSON whenever you like.']];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?></title><meta name="description" content="<?= e($desc) ?>"><link rel="canonical" href="<?= e($canon) ?>"><meta name="robots" content="index, follow"><meta name="theme-color" content="#07070a"><meta name="color-scheme" content="dark">
<meta property="og:type" content="website"><meta property="og:title" content="<?= e($title) ?>"><meta property="og:description" content="<?= e($desc) ?>"><meta property="og:url" content="<?= e($canon) ?>"><meta property="og:site_name" content="<?= e(APP_NAME) ?>"><meta name="twitter:card" content="summary"><meta name="twitter:title" content="<?= e($title) ?>"><meta name="twitter:description" content="<?= e($desc) ?>">
<link rel="icon" type="image/png" href="<?= e(asset('icons/favicon-64.png')) ?>"><link rel="apple-touch-icon" href="<?= e(asset('icons/apple-touch-icon.png')) ?>"><link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<link rel="stylesheet" href="<?= e(asset('landing.css')) ?>"><link rel="stylesheet" href="<?= e(asset('ext.css')) ?>"><link rel="stylesheet" href="<?= e(asset('statusmark.css')) ?>"><meta name="pills-src" content="<?= e(asset('pills.svg')) ?>">
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => APP_NAME, 'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'Web, Android, iOS', 'description' => $desc, 'url' => $canon, 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'INR']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script></head>
<body class="lp">
<a href="#main" class="lp-skip">Skip to content</a>
<header class="lp-nav"><div class="lp-wrap lp-nav__in"><a href="<?= e(url('/welcome')) ?>" class="lp-logo" aria-label="<?= e(APP_NAME) ?> home"><span class="lp-logo__mark"><?= icon('trending-up', 'size-5') ?></span><?= e(APP_NAME) ?></a>
  <nav class="lp-links" aria-label="Sections"><a href="#features">Features</a><a href="#advisor">Advisor</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a></nav>
  <div class="lp-nav__cta"><?php if (!$u): ?><a class="lp-ghost" href="<?= e(url('/login')) ?>">Sign in</a><?php endif; ?><?= $star($ctaLabel, $cta, ['class' => 'lp-star--sm', 'radius' => 10]) ?></div></div></header>
<main id="main">
<section class="lp-hero"><div class="lp-glow" aria-hidden="true"></div><div class="lp-wrap lp-hero__in">
  <div class="lp-hero__copy"><p class="lp-eyebrow">Money, minus the guesswork</p><h1>Plan every rupee.<br><span class="lp-grad">Know where it goes.</span></h1>
    <p class="lp-lead">Budgets, bills, goals and dues in one calm app for the whole family, with an advisor that tells you the truth about your money.</p>
    <div class="lp-actions"><?= $star($ctaLabel, $cta) ?><a class="lp-ghost lp-ghost--big" href="#features">See what is inside</a></div>
    <ul class="lp-points"><li><?= icon('check', 'size-4') ?> Free to start</li><li><?= icon('check', 'size-4') ?> Works offline</li><li><?= icon('check', 'size-4') ?> No bank logins</li></ul></div>
  <div class="lp-phones" aria-label="App preview">
    <div class="lp-phone lp-phone--a"><div class="lp-phone__notch"></div><div class="lp-scr"><p class="lp-scr__k">Balance</p><p class="lp-scr__big">₹3,23,812</p><p class="lp-scr__chip">▲ 4.2% this month</p>
      <p class="lp-scr__h">Recent <small>drag a row</small></p>
      <ul class="lp-rows swipe-list" data-demo>
        <?php foreach ([['Groceries', 'Today · Food', '−₹1,840'], ['Salary', 'Yesterday · Income', '+₹1,40,000'], ['Electricity', '21 Oct · Bills', '−₹2,400'], ['School fees', '19 Oct · Education', '−₹18,500']] as [$n, $s, $a]):
            echo '<li>' . swipe_row('<div class="lp-row"><span class="lp-row__ic">' . icon($a[0] === '+' ? 'arrow-down-left' : 'arrow-up-right', 'size-4') . '</span><span class="lp-row__m"><b>' . e($n) . '</b><small>' . e($s) . '</small></span><span class="lp-row__a' . ($a[0] === '+' ? ' pos' : '') . '">' . e($a) . '</span></div>',
                [['type' => 'attr', 'attrs' => 'data-demo-msg="Edit opens the transaction form"', 'label' => 'Edit', 'icon' => 'pencil', 'tone' => 'primary']], [['type' => 'attr', 'attrs' => 'data-demo-msg="Delete asks nothing, then offers Undo"', 'label' => 'Delete', 'icon' => 'trash-2', 'tone' => 'danger']], $n, ['class' => 'lp-swipe', 'attrs' => 'data-mouse']) . '</li>'; endforeach; ?>
      </ul><p class="lp-demo-msg" data-demo-out role="status" aria-live="polite"></p></div></div>
    <div class="lp-phone lp-phone--b" aria-hidden="true"><div class="lp-phone__notch"></div><div class="lp-scr lp-scr--chat"><div class="lp-chat__h"><i></i><b>Paisa</b></div>
      <p class="lp-bub lp-bub--u">Compare old vs new tax regime on ₹18 lakh</p><div class="lp-bub lp-bub--a"><div class="lp-mini"><span>New regime</span><b>₹1,95,000</b></div><div class="lp-mini lp-mini--w"><span>Old regime</span><b>₹2,34,000</b></div></div><p class="lp-bub lp-bub--a">New wins by ₹39,000. Stop agonising and file.</p></div></div>
  </div></div></section>

<section class="lp-sec" id="features"><div class="lp-wrap"><p class="lp-eyebrow">Everything in one place</p><h2>Built for how families actually handle money</h2>
  <div class="lp-grid"><?php foreach ($feat as [$ic, $h, $p]): ?><article class="lp-card"><span class="lp-card__ic"><?= icon($ic, 'size-5') ?></span><h3><?= e($h) ?></h3><p><?= e($p) ?></p></article><?php endforeach; ?></div></div></section>

<section class="lp-sec lp-sec--alt" id="advisor"><div class="lp-wrap lp-split">
  <div><span class="pills-mark lp-pills" data-pills data-size="96" data-label="The AI advisor"></span><p class="lp-eyebrow">The AI advisor</p><h2>Advice that does not flatter you</h2><p class="lp-lead">It reads your accounts, bills and goals, does the maths with real calculators (EMI, SIP, payoff, tax regimes), shows every step, and proposes changes you can preview, apply and undo.</p>
    <ul class="lp-list"><li><?= icon('check', 'size-4') ?> Blunt, numbers-first, no filler</li><li><?= icon('check', 'size-4') ?> Calculators with visible step-by-step working</li><li><?= icon('check', 'size-4') ?> Changes only after you press Apply, all logged with Undo</li><li><?= icon('check', 'size-4') ?> Refuses to help with tax evasion</li></ul></div>
  <div class="lp-dockwrap"><p class="lp-dockhint">Your favourite pages in a dock. Hover it.</p>
    <div class="mf-dock mf-dock--demo" data-dock data-base="50" data-magnify="70" data-panel="68" data-gap="10" data-round="0.5"><div class="mf-dock__panel" role="toolbar" aria-label="Dock preview">
      <?php foreach ([['Dashboard', 'layout-dashboard', 1], ['Transactions', 'arrow-left-right', 0], ['Bills', 'bell-ring', 3], ['Goals', 'goal', 0], ['AI advisor', 'pills', 0], ['Reports', 'chart-line', 0]] as $i => [$l, $ic, $n]): ?>
        <a class="mf-dock__item<?= $i === 0 ? ' is-active' : '' ?>" href="#features" data-key="<?= $i ?>" aria-label="<?= e($l) ?>"><span class="mf-dock__tile"><?= icon($ic, 'mf-dock__icon') ?><?= $n ? '<span class="mf-dock__badge" aria-hidden="true">' . $n . '</span>' : '' ?></span><span class="mf-dock__label" aria-hidden="true"><?= e($l) ?></span><span class="mf-dock__dot" aria-hidden="true"></span></a><?php endforeach; ?>
    </div></div></div></div></section>

<section class="lp-sec" id="pricing"><div class="lp-wrap"><p class="lp-eyebrow">Pricing</p><h2>Start free. Pay only for what you use.</h2><p class="lp-sub">Straight from this site's current settings.</p>
  <div class="lp-tiers">
    <article class="lp-tier"><h3>Free</h3><p class="lp-price">₹0</p><ul><li><?= icon('check', 'size-4') ?> Budgets, bills, goals, reports</li><li><?= icon('check', 'size-4') ?> Offline mode &amp; installable app</li><li><?= icon('check', 'size-4') ?> Dues &amp; online payment</li><?php foreach ($t['free'] as $l): ?><li><?= icon('check', 'size-4') ?> <?= e($l) ?></li><?php endforeach; ?></ul><?= $star($ctaLabel, $cta, ['class' => 'lp-star--full', 'duration' => 6]) ?></article>
    <article class="lp-tier lp-tier--hi"><h3>Plans</h3><p class="lp-price"><?= $t['paid'] ? e($t['paid'][0][1]) : 'Nothing to buy' ?></p><ul><?php if (!$t['paid']): ?><li>No paid plans right now. Everything above is free.</li><?php endif; foreach ($t['paid'] as [$l, $p]): ?><li><?= icon('check', 'size-4') ?> <?= e($l) ?> <small><?= e($p) ?></small></li><?php endforeach; ?></ul><a class="lp-ghost lp-ghost--full" href="<?= e($cta) ?>"><?= e($ctaLabel) ?></a></article>
    <article class="lp-tier"><h3>Pay as you go</h3><p class="lp-price"><?= $t['payg'] ? 'Wallet' : 'None' ?></p><ul><?php if (!$t['payg']): ?><li>Nothing is metered right now.</li><?php endif; foreach ($t['payg'] as [$l, $p]): ?><li><?= icon('check', 'size-4') ?> <?= e($l) ?> <small><?= e($p) ?></small></li><?php endforeach; ?></ul><?php if ($t['payg']): ?><p class="lp-fine">Top up from ₹10. Usage is deducted automatically; nothing renews on its own.</p><?php endif; ?></article>
  </div></div></section>

<section class="lp-sec lp-sec--alt" id="faq"><div class="lp-wrap lp-faqw"><p class="lp-eyebrow">FAQ</p><h2>Straight answers</h2>
  <div class="lp-faq"><?php foreach ($faq as [$q, $a]): ?><details><summary><?= e($q) ?><?= icon('chevron-down', 'size-4') ?></summary><p><?= e($a) ?></p></details><?php endforeach; ?></div></div></section>

<section class="lp-cta"><div class="lp-wrap"><h2>Your money, finally in order.</h2><p class="lp-lead">Set up in a few minutes. Add sample data to look around first.</p><div class="lp-actions lp-actions--c"><?= $star($ctaLabel, $cta) ?></div></div></section>
</main>
<footer class="lp-foot"><div class="lp-wrap lp-foot__in"><span>© <?= date('Y') ?> <?= e(APP_NAME) ?></span><nav aria-label="Footer"><a href="#features">Features</a><a href="#pricing">Pricing</a><a href="#faq">FAQ</a><a href="<?= e(url('/login')) ?>">Sign in</a><a href="<?= e(url('/credits')) ?>">Credits</a></nav></div></footer>
<script src="<?= e(asset('motion.js')) ?>"></script><script src="<?= e(asset('pillsmark.js')) ?>" defer></script><script src="<?= e(asset('starborder.js')) ?>" defer></script><script src="<?= e(asset('swiperow.js')) ?>" defer></script><script src="<?= e(asset('dock.js')) ?>" defer></script>
<script>document.addEventListener('click',function(e){var b=e.target.closest('[data-demo-msg]');if(!b)return;var o=document.querySelector('[data-demo-out]');if(o){o.textContent='Demo: '+b.getAttribute('data-demo-msg')+'.';clearTimeout(o._t);o._t=setTimeout(function(){o.textContent=''},2600)}});
if('IntersectionObserver'in window&&!matchMedia('(prefers-reduced-motion: reduce)').matches){var io=new IntersectionObserver(function(es){es.forEach(function(x){if(x.isIntersecting){x.target.classList.add('in');io.unobserve(x.target)}})},{threshold:.12});document.querySelectorAll('.lp-card,.lp-tier,.lp-faq details').forEach(function(n){n.classList.add('lp-rv');io.observe(n)})}</script>
</body></html>
<?php
}
