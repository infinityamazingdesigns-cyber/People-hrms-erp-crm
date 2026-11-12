# Probation — porting notes

## Ported (logic only)
- People on probation come from the employee master (joining date, not decided); end = the confirmation date set on
  the master, else joining date + months.
- Check-ins (On track / Needs support / Concern, note, actions) at the months set; they open a few days early.
- Final review opens by itself before the end (or HR opens it): ratings 1–5, strengths, to improve, training, salary
  revision recommendation (yes / %, from — never a pay figure), role change, recommendation with a reason.
- No manager login → HR fills it in. HR decides Confirm / Extend / Do not confirm; a different decision needs a note;
  the date goes to the employee master (logged). Extension starts a new round. HR can send a review back.
- Nobody reviews, checks in on or decides their own probation.

## Became settings / organisation data
- Probation months, when the review and check-ins open, check-in months, rating areas, training needs,
  "track people who joined from". "Mark as already confirmed" for staff confirmed before tracking.

## Not ported
- Holds from the conduct module (open warnings block confirmation) — comes with Feedback & conduct.
- One company's rating texts and training list.
