# Shifts & weekly offs — porting notes

## Ported (logic only)
- A person's own rule wins over the branch's shift and weekly off: fixed hours, flexible hours, or the branch's hours
  with own weekly offs; one or more weekly offs (e.g. two different days), or none.
- Saving a new rule keeps the earlier one (never deleted); ending a rule needs a reason.
- Attendance reads it everywhere: weekly-off days in the register, late / early against the shift, flexible hours
  (a punch before the day-change hour belongs to the previous day) on the punch screen.

## Changed from the reference build
- Rules carry a **from** date, so earlier months keep the shift they actually had (the reference build applied a
  change to every day, past ones included).
- Set for several people at once from the list.
- Overnight shifts (end before start) are allowed and labelled.

## Became settings / organisation data
- Branch shifts and weekly offs stay on Admin → Organisation; grace minutes on Attendance → Settings.

## Not ported
- Named seeds for particular people or branches, one-off fixes, the bridge to the encrypted HR vault's
  shift screen and its debug page.

## Later
- Late / early allowances (free minutes, permissions, cuts) belong to the Payroll rule pack.
