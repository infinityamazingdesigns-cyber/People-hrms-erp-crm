# Complaints & CAPA — porting notes

## Ported
- One case per occurrence: customer complaint or internal / billing / dispatch / supplier / service problem; branch,
  CRM customer and product (or typed), contact, serial, batch, invoice, how much is faulty (whole lot or some pieces of a
  lot), category, title, description, first seen, reported on, what was done straight away.
- A draft needs only a branch and one of customer / product / description, gets its number at once
  (CC/<branch code>/<year>/0001, per branch and year) and is seen by its reporter only; the same request id never makes a
  second case. Sending checks customer, product, description, category and the faulty quantity.
- Status flow Draft → Submitted → Under review / Investigation → Action in progress → Pending verification → Closed, with
  Rejected and Reopened; each move behind its right; rejecting, reopening and going back need a reason. Target close date
  from the severity on sending; moving it needs a reason. A safety risk makes the case Critical and tells the approvers.
- Closing: with a CAPA only after its root cause is confirmed and its effectiveness check passed; without one only for a
  severity the settings allow, with a note. Kept apart: an action owner never confirms the root cause; an action owner or
  the confirmer never checks effectiveness or closes. A failed check sends the case back to Action in progress; reopening
  resets a passed check.
- CAPA: method, problem statement, 5 whys, contributing causes, root cause (locked once confirmed; opening it again needs a
  reason), effectiveness criteria and review date; containment / corrective / preventive actions with owner, priority and
  due date; done needs how it was done; drop / reopen / moving the date need a reason. My actions list.
- Repeats are suggested (same serial, batch + product, invoice + product, a quoted case number, or the same product with
  words alike above the setting) and decided by a handler (repeat / related / not the same — never offered again);
  confirmed repeats point at the earliest case of the whole chain.
- Evidence by its own bytes (photos, PDF, MP4 / MOV), in the database, opened sandboxed, each look audited; taken off with
  a reason. Timeline insert-only, keeping the description before every change. Audit family `qc_` chained.
- Dashboard (open, past target, no update, repeats, safety, average days to close, by severity / status / category), CSV
  of the register with 5 whys and root cause. Reminders (`php tools/qc_alerts.php`, hourly): overdue actions, cases with no
  update for the severity's days, sent cases nobody took after a day — once a day each.

## Became a setting
- Who handles, who approves, who sees every case; severities that may close without a CAPA; days to close and days
  without an update per severity; categories; departments; repeat match level; case number prefix.

## Left out
- Named people seeded into the Quality role; reporter screens in other languages; one CAPA shared by several cases.
