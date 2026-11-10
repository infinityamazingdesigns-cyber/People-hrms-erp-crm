# Employee master — porting notes

## Ported (logic only)
- One record per code; passphrase-like codes refused (autofill typing a saved password into the code box).
- Status as on a day: on the roll → on notice (resignation / last day ahead) → left (last working day passed).
  Leavers keep their record and drop off every picker (`emp_on_roll()`).
- Same-person matching for names spelled differently (first word, surname within 2 letters).
- Code change with history; manager links and the linked login follow the new code.
- No deletion: a wrong record is switched off with a reason; every field change logged (old → new, who, when, kind).
- Imports: Check writes nothing; New / Update / Same / Possible duplicate / Error per row; a code held by a different
  person is never merged; blank cells never wipe a field; the file is never kept.
- CSV export with formula-injection protection; branch scope for logins tied to branches.

## Became settings / organisation data
- Branches and their other spellings → Admin → Organisation (`branch_label()`), not a fixed spelling map.
- Departments and designations → Admin → Organisation lists (offered as suggestions).
- Who may edit → roles in `EMP_EDIT_ROLES`; who may open → Admin → Modules.

## Not ported (organisation-specific in the reference build)
- Fixed leaver lists, fixed name spellings, one-off code splits and seeds for named people.
- Reading people from salary sheets / biometric files / two org charts and reconciling them — here the master is
  the single source; other modules read it.

## Later
- Profile photo, personal details beyond DOB / gender (→ My profile module), documents (→ KYC module).
