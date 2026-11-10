# Project guide

## 1. What WorkNest HR is
One web application that an organisation sets up for itself: HR and people records, hiring, attendance and leave,
payroll and statutory work, expenses, performance, assets, internal chat and tasks, visitors, CRM and orders, stock,
purchase and contracts. Each organisation switches on only the modules it uses.

## 2. Who does what
| Who | Does |
|---|---|
| **Owner** | Installs the product. Permanent Super Admin — cannot be switched off, demoted or deleted. |
| **Super Admin** | Organisation settings, modules on / off, which roles use each module, per-person exceptions, all logins, audit. |
| **CEO / Director** | Final approvals (payroll release, claims, high-value orders, letters). |
| **HR Manager** | Runs HR; creates employee and manager logins. |
| **HR Operations / Recruitment / Performance** | Their own HR desks. |
| **Manager** | Their team's leave and requests. |
| **Employee** | Their own records only. |
| **Accounts, IT Admin, Reception, Production Head, Sales, Marketing, Quality, Store, Buyer** | Their module's desk. |

## 3. Setting up an organisation (Super Admin)
Follow **Project guide → Setup checklist** in the app; each line ticks itself when done.
1. **Organisation** — display and legal name, logo, colours, country (picks the statutory rule pack), time zone,
   currency, date format, week start, year start month.
2. **Branches** — code, name, other spellings people use (so imports and old records match), state, shift,
   weekly offs. Departments and designations.
3. **Modules** — switch on what this organisation uses. Dependencies come on with them. Tick the roles per module.
4. **Users & access** — create the HR Manager and a CEO / second Super Admin; tie logins to branches if needed.
5. Hand over to HR to load employees (Employee master module).

## 4. How access is decided
```
module ON for the organisation?  ──no──▶ refused (owner still opens Administration modules)
            │yes
per-person deny?                 ──yes─▶ refused
per-person allow?                ──yes─▶ open
holds an allowed role?           ──yes─▶ open, else refused
```
Inside a module: branch scope (logins tied to branches see only those), data classes (pay, bank, ID numbers only for
the roles listed), and Employee-only logins see their own records only.

## 5. Modules
Full catalog with status: [MODULES.md](MODULES.md). Each module's specification: `modules/<key>/README.md`.

## 6. Keeping it safe
See [SECURITY.md](SECURITY.md). Short version: sealed ID / bank data, masked everywhere, hash-chained audit,
2-step sign-in (Email module), no hard deletes, no organisation data in the repository.

## 7. Updates
Each change goes through a pull request; CI runs PHP syntax checks, the tests and the guard. New database changes
arrive as migrations and apply themselves on the next page load after deployment.
