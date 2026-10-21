<?php
declare(strict_types=1);

function page_budget(): void {
    require_login(); $s = snap(); $month = month_param();
    [$in, $proposal] = proposal_for($s, $month);
    $plan = get_plan($s['uid'], $month);
    $essential = ['bills', 'yearly', 'household'];
    $propAmt = []; foreach ($proposal as $l) { $propAmt[$l['key']] = $l['amount']; }
    $lines = []; $savedIds = [];
    if ($plan) {
        foreach ($plan['lines'] as $l) {
            $key = $l['section'] . ':' . $l['ref_id'];
            $lines[] = ['key' => $key, 'section' => $l['section'], 'refType' => $l['ref_type'], 'refId' => $l['ref_id'] !== null ? (int) $l['ref_id'] : null, 'label' => $l['label'], 'amount' => (int) $l['amount'], 'proposal' => $propAmt[$key] ?? 0, 'priority' => $l['priority'], 'essential' => in_array($l['section'], $essential, true) || $l['priority'] === 'high'];
            $savedIds[$key] = ['id' => (int) $l['id'], 'applied' => (int) $l['applied']];
        }
    } else { foreach ($proposal as $l) { $lines[] = $l + ['proposal' => $l['amount']]; } }
    $status = $plan['status'] ?? 'none';
    $applyable = ['yearly', 'occasional', 'goals', 'house', 'personalFund', 'big'];
    $help = ['bills' => 'Bills due this month (and any overdue). Pay them on the Bills page; they are not deducted twice.', 'yearly' => 'Yearly amount ÷ 12, so the money is ready when each premium or tax is due.', 'household' => "Your category budgets, or the last three months' average when no budget is set.",
        'personal' => 'Discretionary. These are the first lines suggested for trimming if the plan is short.', 'occasional' => 'Money to set aside for expenses that do not happen every month.', 'goals' => 'Monthly contribution to each long-term goal.',
        'house' => 'Furniture, appliances and other household purchases.', 'personalFund' => 'Your own controlled spending money.', 'big' => 'Contributions toward big-ticket purchases.'];
    $cfg = ['available' => $in['available'], 'locale' => $s['prefs']['locale'], 'symbol' => cur_sym(), 'sections' => PLAN_SECTIONS, 'lines' => $lines];
    $incomeDate = $month . '-' . str_pad((string) min((int) substr($s['today'], 8), 28), 2, '0', STR_PAD_LEFT);

    layout_start('Monthly Budget', 'budget', $s);
    page_header('Monthly Budget', 'Start each month by deciding where every rupee goes. The proposal is built from your bills, budgets and savings plans; edit anything before confirming.', month_nav($month, '/budget'));
    ?>
<div id="planner" data-config="<?= json_attr($cfg) ?>" class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_350px]">
  <div class="min-w-0 space-y-5">
    <?php $i = 0; foreach (PLAN_SECTIONS as $sec => $label):
        $has = array_filter($lines, fn($l) => $l['section'] === $sec); if (!$has) { continue; } ?>
      <section class="card" aria-labelledby="sec-<?= $sec ?>">
        <header class="flex items-baseline justify-between gap-3 px-6 pt-5"><h2 id="sec-<?= $sec ?>" class="text-lg font-semibold tracking-tight"><?= e($label) ?></h2><span class="num text-sm text-mute" data-sub="<?= $sec ?>"></span></header>
        <p class="px-6 text-xs text-mute"><?= e($help[$sec]) ?></p>
        <ul class="divide-y divide-line px-6 pb-3 pt-2">
          <?php foreach ($lines as $idx => $l): if ($l['section'] !== $sec) { continue; } $sv = $savedIds[$l['key']] ?? null; ?>
            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 py-2.5">
              <label for="l-<?= $idx ?>" class="min-w-0 flex-1 truncate text-sm"><?= e($l['label']) ?></label>
              <?php if ($status === 'confirmed' && $sv && in_array($sec, $applyable, true)) { echo $sv['applied'] ? badge('Set aside', 'pos') : post_btn('Set aside now', 'plan-apply', ['id' => $sv['id']], 'btn btn-sm', 'data-apply-line="1"'); } ?>
              <div class="relative w-40"><span aria-hidden="true" class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-sm text-mute"><?= e(cur_sym()) ?></span>
                <input id="l-<?= $idx ?>" data-line="<?= $idx ?>" inputmode="decimal" autocomplete="off" class="input num pl-9 text-right"></div></li>
          <?php endforeach; ?></ul></section>
    <?php endforeach; ?>
    <?php if (!$lines): ?><div class="card p-6 text-sm text-mute">There is nothing to plan yet. Add bills, goals, budgets or purchases first, then come back to see a proposal.</div><?php endif; ?>
  </div>

  <aside class="space-y-4 lg:sticky lg:top-8 lg:self-start" aria-label="Plan summary">
    <div class="card p-6">
      <div class="mb-4 flex items-center justify-between"><h2 class="text-lg font-semibold"><?= e(month_label($month)) ?></h2><?= $status === 'confirmed' ? badge('Confirmed', 'pos') : ($status === 'draft' ? badge('Draft', 'warn') : badge('Proposal')) ?></div>
      <dl class="num space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-mute">1. Carried forward (spendable)</dt><dd><?= e(fmt($in['opening_spendable'])) ?></dd></div>
        <div class="flex justify-between"><dt class="text-mute">2. Income received</dt><dd><?= e(fmt($in['income'])) ?></dd></div>
        <div class="flex justify-between"><dt class="text-mute">Income expected</dt><dd><?= e(fmt($in['income_expected'])) ?></dd></div>
        <div class="flex justify-between border-t border-line pt-2 font-semibold"><dt>Available to plan</dt><dd><?= e(fmt($in['available'])) ?></dd></div>
        <div class="flex justify-between"><dt class="text-mute">Planned allocations</dt><dd id="pl-alloc"></dd></div>
        <div class="flex justify-between border-t border-line pt-2 text-base font-semibold"><dt id="pl-un-label">Unallocated</dt><dd id="pl-un"></dd></div>
      </dl>
      <p class="mt-3 text-xs text-mute">Carried forward is cash at the start of the month minus money already reserved in savings. Income can be added on the Income page.</p>
      <button type="button" class="btn btn-sm mt-3 w-full" data-tx-new="income" data-date="<?= e($incomeDate) ?>" data-planned="1"><?= icon('plus', 'size-4') ?> Add expected income</button>
    </div>

    <div id="pl-deficit" class="card hidden border border-neg/40 p-6" role="alert">
      <p class="flex items-center gap-2 text-sm font-semibold text-neg"><?= icon('triangle-alert', 'size-4') ?> This plan is <span id="pl-def-amt"></span> over what is available</p>
      <p class="mt-1 text-xs text-mute">Nothing has been changed for you. These are suggestions; apply the ones you agree with, or edit any line yourself.</p>
      <ul id="pl-sugg" class="mt-3 space-y-2"></ul><p id="pl-still" class="mt-3 text-sm text-neg"></p>
    </div>
    <div id="pl-buffer" class="card hidden p-5 text-sm"><p class="font-medium"><span id="pl-buffer-amt"></span> is not assigned yet</p><p class="mt-1 text-xs text-mute">Keep it as a buffer, or raise a goal or fund contribution above.</p></div>
    <div id="pl-ok" class="card hidden items-center gap-2 p-5 text-sm text-lime-dk"><?= icon('circle-check', 'size-4') ?> Every rupee is allocated.</div>

    <form method="post" action="<?= e(url('/do/plan-save')) ?>" class="space-y-2"><?= csrf_field() ?><input type="hidden" name="_back" value="<?= e(current_url()) ?>"><input type="hidden" name="month" value="<?= e($month) ?>"><input type="hidden" name="lines" id="pl-lines" value="[]">
      <div class="grid grid-cols-2 gap-2"><button name="status" value="draft" id="pl-draft" class="btn">Save draft</button><button name="status" value="confirmed" id="pl-confirm" class="btn btn-dark">Confirm plan</button></div></form>
    <div class="flex flex-wrap gap-2"><button type="button" class="btn btn-ghost btn-sm" data-reset-edits>Reset edits to proposal</button><?= $status !== 'none' ? post_btn('Discard saved plan', 'plan-reset', ['month' => $month], 'btn btn-ghost btn-sm') : '' ?></div>
    <p class="text-xs text-mute">Confirming records your plan only. Savings lines turn into real contributions when you press “Set aside now”. Available funds are re-checked on the server when you save.</p>
  </aside>
</div>
<?php layout_end();
}
