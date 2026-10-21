<?php
declare(strict_types=1);

function page_analysis(): void {
    $u = require_login(); $s = snap(); $month = month_param(); $type = get_str('type', 10) === 'income' ? 'income' : 'expense'; $isExp = $type === 'expense';
    $a = analysis_data($s, $month, $type); $label = $isExp ? 'Expenses' : 'Income'; $cur = month_of($s['today']);
    $q = fn(array $x) => url('/analysis', array_merge(['month' => $month === $cur ? null : $month, 'type' => $isExp ? null : 'income'], $x));
    layout_start('Analysis', 'analysis', $s, ['hero' => true]);
    ?>
<h1 class="sr-only">Analysis: <?= e(strtolower($label)) ?> for <?= e(month_label($month)) ?></h1>
<div class="lg:grid lg:grid-cols-[minmax(0,1fr)_400px] lg:gap-6">
  <section class="m-hero pb-14 lg:rounded-[36px] lg:pb-8" aria-label="Spending shape">
    <div class="flex items-center justify-between gap-3 px-5 pt-[calc(env(safe-area-inset-top)+18px)] lg:pt-6">
      <a href="<?= e(url('/transactions')) ?>" class="grid size-12 place-items-center rounded-full m-glass" aria-label="Back to transactions"><?= icon('arrow-left', 'size-5') ?></a>
      <div class="flex items-center gap-1 rounded-full m-glass px-1.5 py-1.5 text-sm" role="group" aria-label="Choose month">
        <a class="grid size-9 place-items-center rounded-full hover:bg-white/15" aria-label="Previous month" href="<?= e(url('/analysis', ['month' => add_months($month, -1), 'type' => $isExp ? null : 'income'])) ?>"><?= icon('chevron-left', 'size-4') ?></a>
        <span class="min-w-28 px-1 text-center font-medium"><?= e(month_label($month)) ?></span>
        <a class="grid size-9 place-items-center rounded-full hover:bg-white/15" aria-label="Next month" href="<?= e(url('/analysis', ['month' => add_months($month, 1), 'type' => $isExp ? null : 'income'])) ?>"><?= icon('chevron-right', 'size-4') ?></a>
      </div>
      <button type="button" class="grid size-12 place-items-center rounded-full m-glass" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon', 'size-5') ?></button>
    </div>
    <div class="mx-5 mt-4"><?= jelly(['expense' => ['label' => 'Expenses', 'href' => url('/analysis', ['month' => $month === $cur ? null : $month])], 'income' => ['label' => 'Income', 'href' => url('/analysis', ['month' => $month === $cur ? null : $month, 'type' => 'income'])]], $isExp ? 'expense' : 'income', ['size' => 'sm', 'variant' => 'glass', 'label' => 'Direction']) ?></div>
    <div class="mx-auto mt-4 max-w-[430px] px-4"><?= chart_bubbles($a['cats']) ?></div>
  </section>

  <div class="m-sheet pb-8 lg:mt-0 lg:rounded-[32px] lg:shadow-none lg:ring-1 lg:ring-line">
    <div class="px-6 pt-6">
      <div class="flex items-center justify-between"><h2 class="text-xl font-semibold tracking-tight"><?= $label ?></h2><a href="<?= e(url('/transactions', ['type' => $isExp ? 'expense' : 'income', 'from' => month_start($month), 'to' => month_end($month)])) ?>" class="text-sm text-mute">View All</a></div>
      <p class="num mt-3 text-center text-4xl font-semibold tracking-tight"><?php [$m1, $d1] = fmt_parts($a['total']); echo e($m1) . '<span class="text-mute">' . e($d1) . '</span>'; ?></p>
      <p class="mt-1 text-center text-sm text-mute"><?php if ($a['change'] === null): ?>No earlier month to compare<?php else: ?><span class="<?= ($a['change'] <= 0) === $isExp ? 'text-lime-dk' : 'text-orange-dk' ?>"><?= $a['change'] >= 0 ? '↑' : '↓' ?> <?= number_format(abs($a['change']), 1) ?>%</span> vs <?= e(month_label(add_months($month, -1), true)) ?> (<?= e(fmt($a['prev'])) ?>)<?php endif; ?></p>
      <?php $top = array_slice($a['cats'], 0, 2); if ($top): ?>
      <div class="mt-5 grid grid-cols-2 gap-3"><?php foreach ($top as $c): ?>
        <a href="<?= e(url('/transactions', ['type' => $isExp ? 'expense' : 'income', 'from' => month_start($month), 'to' => month_end($month)])) ?>" class="rounded-3xl bg-soft p-4 transition hover:scale-[1.02]"><div class="flex items-center justify-between text-xs text-mute"><span class="truncate"><?= e($c['name']) ?></span><?= icon(cat_icon($c['name']), 'size-4') ?></div><p class="num mt-3 text-lg font-semibold"><?= e(fmt($c['value'])) ?></p></a><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
    <dl class="mx-6 mt-5 grid grid-cols-3 gap-2 text-center text-sm">
      <div class="rounded-2xl bg-soft px-2 py-3"><dt class="text-xs text-mute">Per day</dt><dd class="num mt-1 font-semibold"><?= e(fmt_short($a['avg_day'])) ?></dd></div>
      <div class="rounded-2xl bg-soft px-2 py-3"><dt class="text-xs text-mute">Entries</dt><dd class="num mt-1 font-semibold"><?= $a['count'] ?></dd></div>
      <div class="rounded-2xl bg-soft px-2 py-3"><dt class="text-xs text-mute">Largest</dt><dd class="num mt-1 font-semibold"><?= $a['biggest'] ? e(fmt_short($a['biggest'][0]['amount'])) : '—' ?></dd></div>
    </dl>
    <div class="mt-6 px-6"><h3 class="mb-2 text-sm font-semibold">Share by category</h3>
      <ul class="divide-y divide-line"><?php foreach (array_slice($a['cats'], 0, 8) as $c): $pct = $a['total'] > 0 ? $c['value'] / $a['total'] * 100 : 0; ?>
        <li class="py-2.5 text-sm"><div class="mb-1.5 flex items-center justify-between gap-2"><span class="flex min-w-0 items-center gap-2"><span class="grid size-8 shrink-0 place-items-center rounded-full bg-soft"><?= icon(cat_icon($c['name']), 'size-4') ?></span><span class="truncate"><?= e($c['name']) ?></span></span><span class="num shrink-0 text-xs text-mute"><?= number_format($pct, 1) ?>% · <?= e(fmt($c['value'])) ?></span></div><?= progress($pct, 'lime', $c['name'] . ' share') ?></li><?php endforeach; ?>
        <?php if (!$a['cats']): ?><li class="py-6 text-center text-sm text-mute">No <?= strtolower($label) ?> recorded for <?= e(month_label($month)) ?>.</li><?php endif; ?></ul></div>
  </div>
</div>

<div class="mt-5 grid gap-5 px-4 lg:grid-cols-2 lg:px-0">
  <?php card_open('Day by day', e(month_label($month))); echo chart_bars(array_map(fn($v, $i) => ['label' => ($i + 1) % 5 === 1 || $i + 1 === count($a['series']) ? (string) ($i + 1) : '', 'values' => [max($v, 0)]], $a['series'], array_keys($a['series'])), [['name' => $label, 'color' => $isExp ? 'orange' : 'lime']], 200, false); card_close(); ?>
  <?php card_open('Which weekdays?', 'Average per day, last 90 days'); echo chart_bars($a['weekday'], [['name' => 'Average', 'color' => $isExp ? 'orange' : 'lime']], 200); card_close(); ?>
  <?php card_open('Top ' . ($isExp ? 'payees & items' : 'sources'), 'Grouped by description'); ?>
    <?php if (!$a['descs']): ?><p class="text-sm text-mute">Nothing yet.</p><?php else: ?><ul class="divide-y divide-line text-sm"><?php foreach ($a['descs'] as $name => $v): ?><li class="flex items-center justify-between gap-2 py-2.5"><span class="min-w-0 truncate"><?= e($name) ?> <span class="text-xs text-mute">× <?= $v['n'] ?></span></span><span class="num shrink-0 font-medium"><?= e(fmt($v['v'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <?php card_close(); ?>
  <?php card_open('How it was paid', 'Payment method split'); echo chart_segbar($a['methods']); card_close(); ?>
  <?php card_open('Largest ' . strtolower($label), '', '', 'lg:col-span-2'); echo tx_list($s, $a['biggest'], 'Nothing yet.'); card_close(); ?>
</div>
<?php layout_end();
}
