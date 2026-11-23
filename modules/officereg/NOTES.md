# Office registers — porting notes

## Ported
- **Property**: counted things each branch holds (item, labels, quantity, where / with whom, notes). Tagged things given
  to a person stay in the Asset register.
- **Library**: books with type, author, language and copies; lend a copy to someone on the roll (or a typed name) with a
  return-by date (30 days unless set), take it back, full history; loans past the date listed at the top.
- **Import** (CSV / .xlsx, columns by their titles, the file is not kept): Check writes nothing; a line already on the
  register (same branch + name / title) is skipped, never overwritten; a book list's type written once for a group is
  carried down; "1COPY" style counts read as numbers.
- Branch scope: a branch-tied login sees and adds only its branches' lines (and must pick one).
- Nothing deleted: a line is taken off with a reason and can be put back; a book with copies out cannot be taken off;
  `office_reg_log` is append-only and keeps field names only.

## Became settings / changed
- Office = the organisation's own branch list (the reference build read office names out of sheet titles).
- The reference build filled these from one company's workbook; here any list can be imported.
