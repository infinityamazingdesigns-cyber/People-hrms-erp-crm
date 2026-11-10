# Statutory rule packs

Country and state rules live in rule packs, never inside module code. A pack is chosen by `org('country')`; state-level
rules by each branch's `state`.

Planned layout:
```
rulepacks/<COUNTRY>/pack.php      // returns rules with effective-from months
rulepacks/<COUNTRY>/states/*.php  // state slabs (e.g. professional tax, labour welfare fund)
```
Each rule has an effective month, so a confirmed payroll month is never recalculated with newer rules.

## India (IN) — first pack, ported from the reference build
Provident fund (employee / employer shares, wage ceiling, pension split, admin and insurance charges), state
insurance (eligibility ceiling, both shares, contribution periods), professional tax by state (monthly, half-yearly
and yearly states), labour welfare fund, statutory bonus, gratuity estimate, minimum wage floors by skill / zone,
income-tax deduction at source with regime choice, leave encashment. Figures are settings the Super Admin confirms,
with the source and effective month shown.

## Others
UAE (gratuity, WPS file), UK (PAYE / NI placeholders), US (placeholders). `XX` = no statutory rules (pay as entered).
