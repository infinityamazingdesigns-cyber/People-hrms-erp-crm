# Training register — porting notes

## Ported
- Trainings per person: kind, name, enrolled / completed, dates, hours or days, trainer, venue, objectives, outcome,
  how it was evaluated, result, skills, https link, fee (HR, by setting), financial year from Organisation's first
  month. Added by the person, a manager above them on the org chart, or HR. HR's entry for someone else counts as
  verified; everyone else's waits for HR (To verify); nobody verifies their own; HR may ask for details or proof and
  the person's change sends it back; a change or new certificate by anyone but HR sends a verified one back.
- Certificates (PDF, JPEG, PNG, WebP by their bytes; 10 MB, 12 a training) in the database, opened by whoever sees
  the training. Taken off with a reason, never deleted; HR brings a training back. History per training (tr_log).
- Requests: the person → reporting manager → HR (HR may decide at either step; a manager asking for someone in their
  team goes straight to HR); declining needs a note; nobody decides their own or one they made; approved → an
  enrolled, verified training; withdrawn while open.
- Feedback after a completed training (rating, recommend, learned, how it will be used) by the person, once, for the
  days set.
- Peer sessions: anyone with a code arranges one (HR may name the trainer), colleagues join (seats) or leave, the
  trainer / HR marks it held → each attendee gets a Peer training record waiting for HR; cancel with a note.
- Year report (HR / see-all everyone in scope, managers their team): verified completed trainings, hours, people,
  kinds, skills, certificates; CSV.

## Became settings
- HR and see-all roles, feedback days, hours in a training day, whether HR records fees.

## Not ported (later)
- Copying a register exported from another tool, links from conduct notes and induction levels, training calendars
  and reminders, budgets per department.
