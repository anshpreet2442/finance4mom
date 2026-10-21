<?php
declare(strict_types=1);
/**
 * Deterministic money maths for the AI advisor (EMI, SIP, payoff, India tax regimes). The model never does arithmetic itself: it calls these
 * and gets exact numbers plus a list of steps that is shown to the person as a "how this was calculated" card.
 * All amounts are rupees (floats rounded to 2 places) at this layer.
 * Tax rules below are the individual-resident slabs for FY 2025-26 (AY 2026-27). They are data, not law: the advisor is told to say so.
 */

function r2(float $x): float { return round($x, 2); }
function inr_s(float $x): string { $neg = $x < 0; $x = abs($x); $i = (int) floor($x); $f = (int) round(($x - $i) * 100); if ($f === 100) { $i++; $f = 0; }
    $s = (string) $i; if (strlen($s) > 3) { $s = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($s, 0, -3)) . ',' . substr($s, -3); } return ($neg ? '-' : '') . '₹' . $s . ($f ? '.' . str_pad((string) $f, 2, '0', STR_PAD_LEFT) : ''); }

function calc_emi(float $principal, float $annualRate, int $months): array {
    if ($principal <= 0 || $months <= 0 || $months > 600) { throw new UserError('Principal and months must be positive (max 600 months).'); }
    if ($annualRate < 0 || $annualRate > 60) { throw new UserError('Interest rate must be between 0 and 60% a year.'); }
    $r = $annualRate / 12 / 100;
    $emi = $r == 0.0 ? $principal / $months : $principal * $r * (1 + $r) ** $months / ((1 + $r) ** $months - 1);
    $total = $emi * $months; $interest = $total - $principal;
    return ['emi' => r2($emi), 'total_payment' => r2($total), 'total_interest' => r2($interest), 'interest_share_pct' => r2($interest / $total * 100), 'steps' => [
        ['label' => 'Monthly rate', 'formula' => "$annualRate% ÷ 12 ÷ 100", 'value' => rtrim(rtrim(number_format($r, 8, '.', ''), '0'), '.')],
        ['label' => 'EMI', 'formula' => 'P × r × (1+r)^n ÷ ((1+r)^n − 1)', 'value' => inr_s($emi)],
        ['label' => 'Total you pay', 'formula' => inr_s($emi) . " × $months", 'value' => inr_s($total)],
        ['label' => 'Interest cost', 'formula' => inr_s($total) . ' − ' . inr_s($principal), 'value' => inr_s($interest)]]];
}
function calc_sip(float $monthly, float $annualReturn, float $years, float $stepUpPct = 0): array {
    if ($monthly <= 0 || $years <= 0 || $years > 60) { throw new UserError('Monthly amount and years must be positive (max 60 years).'); }
    if ($annualReturn < -20 || $annualReturn > 40) { throw new UserError('Use a return between -20% and 40% a year.'); }
    $i = $annualReturn / 12 / 100; $n = (int) round($years * 12); $fv = 0.0; $invested = 0.0; $m = $monthly;
    for ($k = 1; $k <= $n; $k++) { $fv = ($fv + $m) * (1 + $i); $invested += $m; if ($stepUpPct > 0 && $k % 12 === 0) { $m *= 1 + $stepUpPct / 100; } }
    $steps = [['label' => 'Monthly rate', 'formula' => "$annualReturn% ÷ 12 ÷ 100", 'value' => rtrim(rtrim(number_format($i, 8, '.', ''), '0'), '.')],
        ['label' => 'Months', 'formula' => "$years years × 12", 'value' => (string) $n]];
    $steps[] = $stepUpPct > 0 ? ['label' => 'Future value (with yearly step-up ' . $stepUpPct . '%)', 'formula' => 'month by month: (balance + SIP) × (1+r)', 'value' => inr_s($fv)] : ['label' => 'Future value', 'formula' => 'SIP × ((1+r)^n − 1) ÷ r × (1+r)', 'value' => inr_s($fv)];
    $steps[] = ['label' => 'Total invested', 'formula' => 'sum of all instalments', 'value' => inr_s($invested)]; $steps[] = ['label' => 'Gain', 'formula' => inr_s($fv) . ' − ' . inr_s($invested), 'value' => inr_s($fv - $invested)];
    return ['future_value' => r2($fv), 'invested' => r2($invested), 'gain' => r2($fv - $invested), 'steps' => $steps];
}
/** Monthly SIP needed to reach a target amount. */
function calc_sip_needed(float $target, float $annualReturn, float $years): array {
    if ($target <= 0 || $years <= 0 || $years > 60) { throw new UserError('Target and years must be positive.'); }
    $i = $annualReturn / 12 / 100; $n = (int) round($years * 12);
    $p = $i == 0.0 ? $target / $n : $target * $i / (((1 + $i) ** $n - 1) * (1 + $i));
    return ['monthly_needed' => r2($p), 'invested' => r2($p * $n), 'steps' => [['label' => 'Monthly rate', 'formula' => "$annualReturn% ÷ 12 ÷ 100", 'value' => (string) round($i, 8)], ['label' => 'Months', 'formula' => "$years × 12", 'value' => (string) $n],
        ['label' => 'SIP needed', 'formula' => 'Target × r ÷ (((1+r)^n − 1) × (1+r))', 'value' => inr_s($p)]]];
}
/** Debt payoff: months and interest for a fixed monthly payment, and the effect of paying extra. */
function calc_payoff(float $balance, float $annualRate, float $payment, float $extra = 0): array {
    if ($balance <= 0 || $payment <= 0) { throw new UserError('Balance and payment must be positive.'); }
    $sim = function (float $pay) use ($balance, $annualRate): ?array {
        $b = $balance; $r = $annualRate / 12 / 100; $int = 0.0; $m = 0;
        while ($b > 0.005 && $m < 1200) { $i = $b * $r; if ($pay <= $i && $m === 0) { return null; } $int += $i; $b = $b + $i - $pay; $m++; if ($b < 0) { $b = 0; } }
        return $m >= 1200 ? null : ['months' => $m, 'interest' => $int, 'total' => $balance + $int];
    };
    $base = $sim($payment); if (!$base) { return ['possible' => false, 'message' => 'The payment does not even cover the monthly interest of ' . inr_s($balance * $annualRate / 1200) . ', so the balance never falls.', 'steps' => [['label' => 'Monthly interest', 'formula' => inr_s($balance) . " × $annualRate% ÷ 12", 'value' => inr_s($balance * $annualRate / 1200)]]]; }
    $o = ['possible' => true, 'months' => $base['months'], 'years' => r2($base['months'] / 12), 'total_interest' => r2($base['interest']), 'total_paid' => r2($base['total']), 'steps' => [
        ['label' => 'Monthly interest at the start', 'formula' => inr_s($balance) . " × $annualRate% ÷ 12", 'value' => inr_s($balance * $annualRate / 1200)],
        ['label' => 'Months to clear at ' . inr_s($payment) . '/month', 'formula' => 'month-by-month simulation', 'value' => $base['months'] . ' months'], ['label' => 'Interest you pay in total', 'formula' => 'sum of monthly interest', 'value' => inr_s($base['interest'])]]];
    if ($extra > 0 && ($fast = $sim($payment + $extra))) { $o['with_extra'] = ['extra' => $extra, 'months' => $fast['months'], 'total_interest' => r2($fast['interest']), 'months_saved' => $base['months'] - $fast['months'], 'interest_saved' => r2($base['interest'] - $fast['interest'])];
        $o['steps'][] = ['label' => 'With ' . inr_s($extra) . ' extra a month', 'formula' => inr_s($payment + $extra) . ' per month', 'value' => $fast['months'] . ' months, saves ' . inr_s($base['interest'] - $fast['interest'])]; }
    return $o;
}
/** Compound growth of a lump sum. */
function calc_lumpsum(float $amount, float $annualReturn, float $years): array {
    if ($amount <= 0 || $years <= 0 || $years > 60) { throw new UserError('Amount and years must be positive.'); }
    $fv = $amount * (1 + $annualReturn / 100) ** $years;
    return ['future_value' => r2($fv), 'gain' => r2($fv - $amount), 'steps' => [['label' => 'Growth', 'formula' => inr_s($amount) . " × (1 + $annualReturn%)^$years", 'value' => inr_s($fv)]]];
}

// ---------------- India income tax (individual, resident, age < 60) ----------------
const TAX_NEW = [[400000, 0.0], [800000, 0.05], [1200000, 0.10], [1600000, 0.15], [2000000, 0.20], [2400000, 0.25], [PHP_INT_MAX, 0.30]];
const TAX_OLD = [[250000, 0.0], [500000, 0.05], [1000000, 0.20], [PHP_INT_MAX, 0.30]];
function slab_tax(float $income, array $slabs, array &$rows = []): float {
    $tax = 0.0; $prev = 0;
    foreach ($slabs as [$limit, $rate]) { if ($income <= $prev) { break; } $part = min($income, $limit) - $prev; $t = $part * $rate; $tax += $t; if ($rate > 0) { $rows[] = ['label' => inr_s($prev) . '–' . ($limit === PHP_INT_MAX ? 'above' : inr_s($limit)) . ' at ' . ($rate * 100) . '%', 'formula' => inr_s($part) . ' × ' . ($rate * 100) . '%', 'value' => inr_s($t)]; } $prev = $limit; }
    return $tax;
}
/** $gross = annual gross income (salary / business profit before deductions). $oldDeductions = total of 80C, 80D, HRA, home-loan interest, NPS etc. you would claim in the old regime. */
function calc_tax_regimes(float $gross, float $oldDeductions = 0, bool $salaried = true): array {
    if ($gross < 0 || $gross > 1e9) { throw new UserError('Enter a sensible annual income.'); }
    $std = $salaried; $res = [];
    foreach (['new' => ['New regime', TAX_NEW, $std ? 75000 : 0, 0, 1200000, 60000], 'old' => ['Old regime', TAX_OLD, $std ? 50000 : 0, max(0, $oldDeductions), 500000, 12500]] as $k => [$name, $slabs, $stdD, $ded, $rebLimit, $rebMax]) {
        $steps = []; $taxable = max(0, $gross - $stdD - $ded);
        $steps[] = ['label' => 'Gross income', 'formula' => '', 'value' => inr_s($gross)]; if ($stdD) { $steps[] = ['label' => 'Standard deduction', 'formula' => '−', 'value' => inr_s($stdD)]; } if ($ded) { $steps[] = ['label' => 'Chapter VI-A / HRA etc. claimed', 'formula' => '−', 'value' => inr_s($ded)]; }
        $steps[] = ['label' => 'Taxable income', 'formula' => 'gross − deductions', 'value' => inr_s($taxable)];
        $rows = []; $base = slab_tax($taxable, $slabs, $rows); foreach ($rows as $r) { $steps[] = $r; }
        $rebate = 0.0; if ($taxable <= $rebLimit) { $rebate = min($base, $rebMax); } elseif ($k === 'new' && $base > $taxable - $rebLimit) { $rebate = $base - ($taxable - $rebLimit); } // marginal relief just above ₹12 lakh
        if ($rebate > 0) { $steps[] = ['label' => 'Section 87A rebate / marginal relief', 'formula' => '−', 'value' => inr_s($rebate)]; }
        $afterRebate = $base - $rebate; $cess = $afterRebate * 0.04; $total = $afterRebate + $cess; $steps[] = ['label' => 'Health & education cess', 'formula' => '4% of ' . inr_s($afterRebate), 'value' => inr_s($cess)]; $steps[] = ['label' => 'Total tax', 'formula' => '', 'value' => inr_s($total)];
        $res[$k] = ['name' => $name, 'taxable_income' => r2($taxable), 'tax_before_cess' => r2($afterRebate), 'cess' => r2($cess), 'total_tax' => r2($total), 'effective_rate_pct' => $gross > 0 ? r2($total / $gross * 100) : 0, 'steps' => $steps];
    }
    $better = $res['new']['total_tax'] <= $res['old']['total_tax'] ? 'new' : 'old'; $save = abs($res['new']['total_tax'] - $res['old']['total_tax']);
    return ['new' => $res['new'], 'old' => $res['old'], 'better' => $better, 'saving' => r2($save), 'note' => 'FY 2025-26 individual slabs, resident under 60. Surcharge (income above ₹50 lakh), capital-gains special rates and employer NPS are not modelled. Confirm against the latest Finance Act before filing.',
        'steps' => array_merge([['label' => '— ' . $res['new']['name'], 'formula' => '', 'value' => '']], $res['new']['steps'], [['label' => '— ' . $res['old']['name'], 'formula' => '', 'value' => '']], $res['old']['steps'], [['label' => 'Cheaper', 'formula' => 'compare totals', 'value' => $res[$better]['name'] . ' by ' . inr_s($save)]])];
}
