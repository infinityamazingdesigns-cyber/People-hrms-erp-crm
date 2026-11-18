# Performance (KRA) — porting notes

## Ported
- Role sheets: title, code, department, grade, summary and KRAs (weights total 100) each with KPIs — how it is
  measured, unit, target, polarity (higher / lower / zero is the target) and a relative weight. Draft → active →
  retired; every change to the KRAs keeps the earlier version (pms_sheet_rev).
- Holders: a sheet linked to people on the roll with a share (a person's shares never pass 100%) from a month;
  unlinking keeps earlier evaluations. People without a sheet ask HR for one; linking closes the request.
- One evaluation per person, sheet and review period (monthly / quarterly / half-yearly / yearly, from the year's
  first month in Organisation). The sheet as it was is kept with the evaluation.
- Flow: the person gives actuals and comments, saves, submits (can take it back until the manager acts) → the
  reporting manager (employee master) agrees or adjusts each actual with a comment and an overall comment, or sends it
  back → HR closes (or sends back / opens a closed one again with a note). Nobody acts on their own evaluation.
  Emails to the next person when Email is on (never scores).
- Score: KPI achievement (capped, 120% by default) weighted inside the KRA, KRAs by weight over those with actuals;
  bands from the settings. A screen shows how much of the weight has actuals.
- My team (direct and indirect, read only beyond direct reports), HR console per period with counts, submissions
  waiting in other periods, CSV. Log pms_log: ids and steps only.

## Became settings
- HR roles, review cycle, achievement cap, whether HR closes each review, emails, bands.

## Not ported (later)
- Competencies and behaviours, the KRA library import from files, calibration / normalisation, links to increment
  letters and probation, reminders before a period ends.
