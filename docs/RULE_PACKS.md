# Statutory rule packs

Country and state rules live in rule packs, never inside module code. A pack is chosen by `org('country')`; state-level
rules by each branch's `state`.

Layout (blocked from the web by `.htaccess`):
```
rulepacks/<COUNTRY>/pack.php      // returns ['label', 'fn', 'defaults']; fn = statutory function
```
`fn(array $earned, float $fullGross, array $emp, array $rules, string $state, string $ym)` returns
`['emp' => [label => amount], 'er' => [label => amount], 'notes' => [...]]`. Defaults are merged into the payroll rules,
which the Super Admin / CEO change on Payroll → Rules from a month (`pay_rule`). State slabs (professional tax) are
entered there per state — none are assumed. Built so far: `IN` (provident fund, state insurance, professional tax)
and `XX` (no statutory rules). Labour welfare fund, bonus, gratuity, minimum wages and TDS projection come next.
Each rule has an effective month, so a confirmed payroll month is never recalculated with newer rules.

## India (IN) — first pack, ported from the reference build
Provident fund (employee / employer shares, wage ceiling, pension split, admin and insurance charges), state
insurance (eligibility ceiling, both shares, contribution periods), professional tax by state (monthly, half-yearly
and yearly states), labour welfare fund, statutory bonus, gratuity estimate, minimum wage floors by skill / zone,
income-tax deduction at source with regime choice, leave encashment. Figures are settings the Super Admin confirms,
with the source and effective month shown.

## Others
UAE (gratuity, WPS file), UK (PAYE / NI placeholders), US (placeholders). `XX` = no statutory rules (pay as entered).
