<?php
declare(strict_types=1);

function pct_change(int $now, int $prev): ?float { return $prev > 0 ? ($now - $prev) / $prev * 100 : null; }
function change_chip(?float $c, bool $upIsGood = true): string {
    if ($c === null) { return '<span class="text-xs text-mute">no earlier data</span>'; }
    $good = ($c >= 0) === $upIsGood;
    return '<span class="inline-flex items-center gap-1 text-xs ' . ($good ? 'text-lime-dk' : 'text-orange-dk') . '">' . icon($c >= 0 ? 'trending-up' : 'trending-down', 'size-3.5') . number_format(abs($c), 1) . '% <span class="text-mute">from last month</span></span>';
}

function page_dashboard(): void {
    $u = require_login(); $s = snap(); $ctx = lw_ctx($u, $s); $month = $ctx['month']; $d = $ctx['d'];
    $isEmpty = !$s['txs'] && !$s['goals'] && !$s['bills'];
    layout_start('Dashboard', 'dashboard', $s, ['hero' => true]);
    echo m_hero($s, $u, 'home');
    echo lw_page_sheet($u, $ctx); // phones: the quick-add sheet (part of the mobile layout once it is customised)
    echo '<div class="mt-5 px-4 lg:mt-0 lg:px-0">';
    $hour = (int) date('G'); $hello = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $custom = lw_enabled($u) ? '<a class="btn btn-sm" href="' . e(url('/layout')) . '">' . icon('layers', 'size-4') . 'Customize</a>' : '';
    echo '<div class="hidden lg:block">'; page_header($hello . ', ' . explode(' ', $u['name'])[0], 'Your money in ' . month_label($month), $custom); echo '</div>';
    echo telegram_dashboard_prompt($u);
    if ($isEmpty) { echo '<div class="mb-5">' . empty_state("Let's get started", 'Add your first income or expense with <strong>Add transaction</strong>, set up bills and savings goals, or load sample data from Settings to explore.') . '</div>'; }
    echo lw_render_page($u, $ctx);
    if ($custom) { echo '<p class="mt-6 text-center lg:hidden"><a class="btn btn-sm" href="' . e(url('/layout')) . '">' . icon('layers', 'size-4') . 'Customize this page</a></p>'; }
    echo '</div>'; layout_end();
}
