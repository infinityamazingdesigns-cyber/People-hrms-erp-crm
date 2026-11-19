# Modules

Generated from `config/modules.php` by `php tools/gen_docs.php`.

**56 modules** — 42 ready · 2 porting · 12 planned

## For me

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|

## Administration

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Organisation settings** `org` | Company profile, logo, colours, time zone, currency, branches, departments, designations. | — | Super Admin | Ready |
| **Modules** `modules` | Switch modules on / off and choose which roles and people may use each one. | — | Super Admin | Ready |
| **Users & access** `users` | Logins, roles, branch scope, password resets, deactivation. The owner can never be removed. | — | Super Admin, HR Manager | Ready |
| **Audit log** `audit` | Every sign-in and change, sensitive actions on a tamper-evident hash chain. | — | Super Admin | Ready |
| **Project guide** `guide` | Setup checklist, module catalog and how each module works. | — | Super Admin, HR Manager | Ready |
| **Email & 2-step** `email` | Outgoing mail (Google Workspace or SMTP), outbox with retries, 2-step sign-in codes. | — | Super Admin, IT Admin | Ready |

## People

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Employee master** `employees` | One record per person: code, name, department, designation, branch, joining, status, leavers. | — | Super Admin, HR Manager, HR Operations | Ready |
| **Org chart** `orgchart` | Reporting lines for everyone; vacant seats and exits visible to HR only; drag to re-arrange. | employees | Super Admin, HR Manager, CEO / Director, Manager, Employee | Ready |
| **My profile** `profile` | Each person fills their own details; HR verifies before anything changes. | employees | Employee, Manager, HR Manager, HR Operations, Super Admin | Ready |
| **KYC & bank** `kyc` | Tax ID, national ID (last 4 only), social-security / PF number, bank — sealed at rest, masked, verified by HR. | profile | Employee, HR Manager, HR Operations, Super Admin | Ready |
| **Letter desk** `letters` | Offer, appointment, confirmation, increment, relieving letters; approval, signed PDF, verify code, e-acceptance. | employees | Super Admin, HR Manager, HR Operations, CEO / Director | Ready |
| **Group insurance** `insurance` | Health / accident / life policies with their copy and renewal reminder; who is covered, card numbers, nominees; insurer's list matched to codes; policy copy requests. | employees | Super Admin, HR Manager | Ready |

## Attract & hire

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Recruitment pipeline** `recruitment` | Requisitions, vacancies, candidate pipeline, interviews, offers, ATS match score. | employees | Super Admin, HR Manager, HR Recruitment, CEO / Director, Manager | Ready |
| **Careers page** `careers` | Public job board + application form, CV reading on the server, consent, spam checks, employer ratings. | recruitment | Super Admin, HR Manager, HR Recruitment | Ready |
| **Candidate portal** `candidates` | Private link per applicant: stage, interviews, letters, documents — no password. | recruitment, letters | Super Admin, HR Manager, HR Recruitment | Ready |
| **Recruitment intelligence** `recintel` | Institutes, consultants and contractors with list import, companies, recruitment projects, vacancies with job codes, outside hiring trends. | recruitment | Super Admin, HR Manager, HR Recruitment | Ready |

## Onboard & grow

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Onboarding** `onboarding` | Checklist per joiner from offer to first week; convert candidate to employee. | employees | Super Admin, HR Manager, HR Operations | Ready |
| **Probation** `probation` | Check-ins, manager review before the end date, HR confirms / extends. | employees | Super Admin, HR Manager, HR Operations, Manager | Ready |
| **Induction academy** `induction` | Levels with reading, video and a quiz, in order, for everyone or a department; progress per person, shown on onboarding. | employees | Employee, Manager, HR Manager, Super Admin | Ready |
| **Aptitude test** `aptitude` | Papers drawn from HR's question bank by job family and level, timed and scored on the server; the result goes to HR only. | induction | HR Manager, Super Admin, CEO / Director | Ready |
| **Training register** `training` | Trainings verified by HR, certificates, requests (manager → HR), feedback, peer sessions, yearly report + CSV. | employees | Employee, Manager, HR Manager, Super Admin | Ready |

## Time & attendance

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Attendance** `attendance` | Phone punch with live selfie + precise GPS, office geofence, register, corrections, month close. | employees, holidays, orgchart | Employee, Manager, HR Manager, Super Admin, CEO / Director | Ready |
| **Biometric machines** `biometric` | ADMS push from machines of any make, vendor cloud APIs, key-based punch API. | attendance | Super Admin | Porting |
| **Shifts & weekly offs** `shifts` | Shift, flexible hours and weekly offs per person from a date, over the branch's; drives attendance late / early. | attendance | Super Admin, HR Manager, HR Operations | Ready |
| **Holiday calendar** `holidays` | Holidays by branch; drives attendance, payroll and the announcement board. | employees | Super Admin, HR Manager, Employee | Ready |
| **Leave** `leave` | Apply (day / half / time), manager → HR approval, balances, withdraw, handover, categories. | employees | Employee, Manager, HR Manager, HR Operations, Super Admin | Ready |
| **Staff movement** `movement` | Gate log of staff going out during working hours, late-return alerts. | attendance | Super Admin, Reception / Security, Production Head | Planned |

## Pay & expenses

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Payroll** `payroll` | CTC structures, month run from attendance, statutory deductions by country/state rule packs, HR → CEO approval, bank file. | attendance, leave | Super Admin, CEO / Director | Ready |
| **Payslips** `payslips` | Released payslips, PIN-locked, print / save as PDF; the Super Admin chooses who sees their own. | payroll | Super Admin | Ready |
| **Full & final** `fnf` | Settlement for leavers, leave adjustment, documents, approval steps. | payroll | Super Admin, HR Manager, CEO / Director | Ready |
| **Statutory compliance** `statutory` | Portals, registrations, due dates, challans, month close, insurance cover — driven by a country rule pack. | payroll | Super Admin, HR Manager, Accounts / Payment | Ready |
| **Expense claims** `claims` | Multi-line claims with bills, HR audit → CEO approval → Accounts pay; drafts; duplicate checks. | — | Employee, HR Manager, CEO / Director, Accounts / Payment, Super Admin | Ready |
| **Branch day book** `daybook` | Daily cash sheet per branch: bills, dispatch, money received, petty cash, close the day. | — | Employee, HR Manager, CEO / Director, Super Admin | Planned |
| **Vendor bills (HO)** `vendorbills` | Rent, utilities and other branch bills paid from head office; repeating bills. | — | HR Manager, CEO / Director, Accounts / Payment, Super Admin | Planned |

## Performance & conduct

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Performance (KRA)** `pms` | Role sheets with weighted KRAs and KPIs, holders, self-evaluation, manager review, HR close, console + CSV. | employees | Employee, Manager, HR Manager, HR Performance & Training, Super Admin | Ready |
| **Feedback & conduct** `conduct` | Feedback notes, monthly check-ins, conduct cases with HR review and approval, replies, appeals, lapse, code of conduct. | employees | Employee, Manager, HR Manager, CEO / Director, Super Admin | Ready |

## Assets & IT

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Asset register** `assets` | Laptops, phones, SIMs, office assets; allot / return; no deletion; yearly audit; IT accounts. | employees | Super Admin, HR Manager, IT Admin | Ready |

## Communication

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Announcement board** `board` | Announcements, pictures, birthdays, who is on leave, holidays, daily challenge. | — | Employee, Manager, HR Manager, Super Admin, CEO / Director | Ready |
| **Team chat** `talk` | One-to-one and group chat, files, read receipts, admin policies; nobody reads chats they are not in. | — | Employee, Manager, HR Manager, Super Admin, CEO / Director | Ready |
| **Video meetings** `meet` | Browser-to-browser video, screen share, waiting room, transcript, minutes. | talk | Employee, Manager, HR Manager, Super Admin, CEO / Director | Planned |
| **Tasks** `tasks` | Personal and assigned tasks, approval loop, reminders, repeat, voice entry, calendar. | — | Employee, Manager, HR Manager, Super Admin, CEO / Director | Ready |
| **Help desk** `helpdesk` | Answers on policies and screens in several languages, team contacts, office locations. | — | Employee, Manager, HR Manager, Super Admin | Planned |
| **Browser notifications** `notify` | Web push per device; text fetched with the person's own sign-in. | — | Employee, Manager, HR Manager, Super Admin | Porting |

## Front desk & security

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Visitors** `visitors` | Desk, expected visitors, groups, host answer (desk or host), pass, register + reports, retention + removal. | — | Super Admin, HR Manager, Reception / Security, CEO / Director | Ready |

## Sales & CRM

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **CRM & orders** `crm` | Customers, products, duplicate check, imports; orders, readiness blockers, dispatch, consignments, customer tracking link, same-day score. | — | Super Admin, CEO / Director, Sales, Store | Ready |
| **Enquiry register** `enquiries` | Enquiries with stages, quotations, follow-ups due, owners, notes, repeat check, reports, import; won → CRM order. | crm | Super Admin, CEO / Director, Sales, Marketing, Manager | Ready |
| **Quotations** `quotes` | Numbered quotations per branch, tax split by country rules, other charges, revisions, print / PDF, won → order, enquiry kept in step. | crm, enquiries | Super Admin, CEO / Director, Sales | Ready |
| **Marketing & ROAS** `marketing` | Campaigns, ad spend imports, leads, consent, return on ad spend. | crm | Super Admin, CEO / Director, Marketing | Planned |
| **Complaints & CAPA** `quality` | Customer complaints, repeats, root cause (5 whys), actions, effectiveness check. | crm | Super Admin, Quality, CEO / Director | Planned |

## Operations & supply

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **Stock & inventory** `stock` | Items, insert-only ledger, goods in / out, transfers, counts, reversals, bills of materials, production, CRM dispatches off stock, statement, reorder. | — | Super Admin, Store, CEO / Director | Ready |
| **Purchase & procurement** `purchase` | Requisitions, vendor master, comparisons, PO approval, goods received + QC, vendor scorecards. | quotes, stock | Super Admin, Buyer, CEO / Director, Store, Quality, Accounts / Payment, Manager, Production Head, Employee | Ready |
| **Production workers** `production` | Hourly / piece-rate worker register, hours grid, separate pay run, advances. | — | Super Admin, Production Head | Planned |
| **Third parties & contracts** `contracts` | Vendors, contractors, agreements with versioned PDFs, approval bound to file hash, e-signing. | — | Super Admin, HR Manager, CEO / Director | Planned |

## Insights

| Module | What it does | Needs | Default roles | Status |
|---|---|---|---|---|
| **HR dashboard (MIS)** `mis` | Headcount, joiners / exits, attendance totals, pending work by desk, statutory dues. | employees | Super Admin, HR Manager, CEO / Director | Planned |
| **My day** `myday` | Each person's own day: punches, requests tracker, letters, quick actions. | — | Employee, Manager, HR Manager, Super Admin | Planned |
| **Lite mode** `lite` | Tiny server-built pages for slow internet: punch, leave, requests, letters. | — | Employee, Manager | Planned |
