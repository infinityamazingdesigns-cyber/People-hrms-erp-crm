# Help desk — porting notes

## Ported
- Ask in your own words: matched in the browser (same rules as `hd_score()`), so nothing typed is sent; without
  scripts the page matches on the server. Greets the login by first name. Topics list below.
- Answers are kept by the help desk editors in the database, in any language from the settings (an answer with the
  same short name in another language is its translation; English shows where a language has none). Each answer can
  open a screen — shown only when that login may open the module; an answer about a module the login has no access to
  is left out. Starting answers describe this product's own screens only (never one organisation's policy) and are
  put in once; an edited or switched-off one is left alone.
- Team contacts (official mobile / email only, WhatsApp button, LinkedIn, locations), fill from the employee list;
  locations (type, branch, address, map link, Google review link, phone); the organisation's social links, each only
  on its own site (all or nothing).
- Signed-in logins with the module only. Nothing is deleted: rows switch off; every change is in `hd_log` (field names).

## Became settings
- Roles that keep the help desk, the languages, the dialling code for WhatsApp buttons on numbers written without one.
- The reference's policy texts (shifts, late rules, probation …) are the organisation's own: editors write them here.

## Not ported (later)
- The 3D mascot, answers from the person's own records ("my requests", "my punches today"), the Lite page,
  live refresh of the lists while the page is open.
