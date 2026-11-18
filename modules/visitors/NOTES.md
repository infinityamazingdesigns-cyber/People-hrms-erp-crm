# Visitors — porting notes

## Ported
- One record per visitor (looked up by mobile; up to 5 more mobiles / emails), one row per visit at a branch: category,
  purpose, whom they meet (picked from the employee list — name and department, never codes — or typed), host answer,
  check-in / check-out on server time, duration. One open visit per visitor. The same form sent twice saves once.
- Host answer recorded by the desk (phone, in person …) or given by the host on **Visitors for me** (self page);
  the host is emailed when someone waits for them and Email is on. Check-in only after the answer (or none needed).
- Groups: people who came together get one visit each, saved all or nothing; group check-in / check-out.
- Expected visitors (today up to 60 days ahead, time optional): counted nowhere until they arrive; Arrived starts the
  visit on that day (checked in at once when no answer is needed) or Did not come (kept).
- Left without entering (reason), time corrections and detail edits with a reason — the earlier values are kept and
  shown. Overdue mark after the hours set. Printed pass with the privacy line.
- Mobile lookup: full details only for a visitor known at the login's branches, else name, company and visit count;
  counted per login per hour. Branch scope: Reception works only in its ticked branches (none ticked = none).
- Register with filters, reports (by category, branch, host, average minutes), CSV (contacts only for the settings
  right) after the password again (10 minutes; 5 wrong tries → 15 minutes wait).
- Retention: details of visitors not seen for N months are removed only after a backup CSV in the last 24 hours;
  visit counts stay. No visitor photographs, no ID documents.

## Became settings
- Roles for desk / reports / settings / removal, months kept, overdue hours, lookup limit, host email, the privacy line,
  categories (renamed or switched off, never deleted).

## Not ported (later)
- Reading visiting cards on the device (OCR), WhatsApp thank-you messages, intercom extensions, gates and contractor
  safety sign-off, weekly retention reminders, the desk notice in other languages.
