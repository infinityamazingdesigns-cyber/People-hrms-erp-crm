# Asset register — porting notes

## Ported
- Categories kept by the organisation (name, tag code, kind: computer / phone / SIM / card / general decides which
  details are asked); a category in use stays on; switched off, never deleted.
- Assets with the next tag <prefix>-<code>-NNN; make, model, serial, IMEI 1 / 2 (15 digits), SIM number and operator,
  card last 4 digits only (never a full card number), bought on / from, invoice no., cost, warranty, branch, notes.
  Invoice copies by their own bytes in the database; earlier copies kept.
- Give to a person on the roll (one open allotment per asset, accessories), take back with date, condition and note.
- No deletion: propose for scrap with a reason → only a confirmer scraps or keeps it; scrapped stays; in use follows
  allotments; lost / stolen come from a resolved issue (which closes the allotment).
- My assets (own records only): items held, confirm them, report an issue (damaged, lost, stolen …), own reports,
  returned items. Yearly audit: start, progress and who is still to confirm, close keeps the result.
- Possible duplicates flagged (same serial, IMEI in either slot, SIM number). Warranty ending / over flagged.
- Change history (before → after) append-only per asset.

## Became settings
- Tag prefix, warranty warning days, roles that edit and roles that confirm scrap / categories / audit.

## Not ported (one company's data or tools in the reference build)
- Its workbook import (column names of one company's sheets), its IT logins / mail ID registers, branch office
  registers and named access grants.

## Later
- Import from a spreadsheet by column headings, merging duplicates, IT accounts & 2-step register, office assets with
  quantities, equipment-access letters.
