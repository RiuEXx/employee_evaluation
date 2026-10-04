# Employee Performance Evaluation System

A web application for peer performance evaluations, built with PHP (no framework), MySQL/MariaDB,
HTML, CSS and a little vanilla JavaScript. It runs on XAMPP, WAMP or LAMP.

- Employees belong to departments, and every employee evaluates the other employees in their own
  department, once per evaluation period. Nobody can evaluate themselves or another department.
- The CEO sees results, statistics, charts, trends, a printable report and CSV exports.
- HR (Admin) manages departments, employees, rating categories, evaluation periods, PINs, roles and
  permissions, settings, and the audit log.
- Login is an Employee Number plus a 4-digit PIN.

---

## Contents

1. [Requirements](#1-requirements)
2. [Installation on XAMPP](#2-installation-on-xampp)
3. [Default logins](#3-default-logins)
4. [User guide: Employee](#4-user-guide-employee)
5. [User guide: CEO](#5-user-guide-ceo)
6. [User guide: Admin (HR)](#6-user-guide-admin-hr)
7. [How scores are calculated](#7-how-scores-are-calculated)
8. [Settings](#8-settings)
9. [Roles and permissions](#9-roles-and-permissions)
10. [Security](#10-security)
11. [Project structure](#11-project-structure)
12. [Troubleshooting](#12-troubleshooting)

---

## 1. Requirements

| Software | Version |
|---|---|
| PHP | 8.0 or newer (tested on 8.2.12, the XAMPP 8.2 build) with the `pdo_mysql` extension |
| Database | MariaDB 10.4 or newer (XAMPP), or MySQL 8.0 or newer |
| Web server | Apache (XAMPP / WAMP / LAMP). `.htaccess` files protect the private folders. |
| Browser | Any current browser. Works on desktop, tablet and phone. |

No internet connection is needed: Chart.js is included in `assets/vendor/`.

## 2. Installation on XAMPP

1. **Get the project** into XAMPP's web folder, so that you have
   `C:\xampp\htdocs\employee_evaluation\index.php`. Either clone it:

   ```
   cd C:\xampp\htdocs
   git clone https://github.com/RiuEXx/employee_evaluation.git
   ```

   or download the ZIP from GitHub (**Code > Download ZIP**), extract it into `C:\xampp\htdocs`,
   and rename the extracted folder `employee_evaluation-main` to `employee_evaluation`.
2. **Start Apache and MySQL** in the XAMPP Control Panel.
3. **Create the database.** Open <http://localhost/phpmyadmin>, choose the **Import** tab, and import:
   1. `database/schema.sql` creates the database `employee_evaluation_db`, all tables, and the
      starting data (roles, permissions, settings, 4 departments, 23 employees, 8 rating
      categories, and an open evaluation period).
   2. `database/seed.sql` (optional, recommended for a demo) adds three closed evaluation periods
      with about 300 sample evaluations, so the dashboards, charts and trends have data.

   Or from a command prompt:

   ```
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\employee_evaluation\database\schema.sql
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\employee_evaluation\database\seed.sql
   ```

   Importing `schema.sql` again deletes and re-creates the whole database (a clean reset).
4. **Check `config/db.php`.** The defaults match a fresh XAMPP install (host `127.0.0.1`, port
   `3306`, user `root`, no password). Change them if your server is different.
5. **Open** <http://localhost/employee_evaluation/> and log in with one of the accounts below.

If you put the project in a folder with another name, it still works; only the two
`ErrorDocument` lines in `.htaccess` need the new folder name for the custom 403/404 pages.

## 3. Default logins

Every account starts with the PIN **1234** and must choose a new PIN at first login.

| Role | Employee Number | Name | Department |
|---|---|---|---|
| CEO | `EMP1001` | Ricardo Villanueva | (none) |
| Admin (HR) | `EMP1002` | Angela Mendoza, HR Manager | Human Resources |
| Employee | `EMP1003` to `EMP1006` | Carlo Bautista, Patricia Lim, Joseph Navarro, Kristine Ramos | Human Resources |
| Employee | `EMP1007` to `EMP1011` | Miguel Torres, Andrea Cruz, Paolo Garcia, Bianca Flores, Daniel Aquino | Finance |
| Employee | `EMP1012` to `EMP1017` | Mark Dela Cruz, Sofia Reyes, Gabriel Santos, Camille Tan, Joshua Castillo, Nicole Gonzales | Information Technology |
| Employee | `EMP1018` to `EMP1022` | Antonio Rivera, Hannah Ocampo, Rafael Domingo, Jasmine Pascual, Vincent Mercado | Operations |
| Inactive | `EMP1023` | Lea Soriano | Operations (left the company; cannot log in, past evaluations kept) |

The Admin is also an evaluator in Human Resources, like any other member of that department.

## 4. User guide: Employee

1. **Log in** with your Employee Number and PIN. The first time, choose a new 4-digit PIN (not
   1234, not four identical digits).
2. **My Evaluations** shows the open evaluation period, a progress bar, and every colleague in your
   department marked *Pending* or *Completed*.
3. Click **Evaluate** next to a colleague (or **Evaluate next**):
   1. *Rate*: give each category (or each criterion inside it) a rating from 1 to 5:
      1 Poor, 2 Needs Improvement, 3 Satisfactory, 4 Good, 5 Excellent. Leadership can be answered
      *Not applicable*. Add an overall comment (optional unless HR requires it).
   2. *Review*: check your answers. Use **Back to edit** to change something.
   3. *Submit*: the evaluation is saved and you see a confirmation, with a button for the next
      pending colleague.

   A submitted evaluation cannot be changed, unless HR has turned on editing until the period
   closes. You can always open it again to view your answers.
4. **My Summary** shows the results you received, once HR allows it (by default: after the period
   is closed). You see your overall score, your average per category next to the department
   average, a chart, your trend over time and, if allowed, anonymous comments. Nothing is shown
   until at least 3 colleagues have evaluated you, and you never see who rated you.
5. **Change PIN** lets you choose a new PIN at any time. If you forget it, ask HR to reset it.

## 5. User guide: CEO

All result pages have an **Evaluation period** picker at the top. The open period is shown by
default; its numbers can still change.

- **Overview**: active employees, departments, completion rate, company average; a bar chart of
  department averages, the company average per category, the top performers, and the company
  trend across periods.
- **Departments**: every department with its average, completion rate, and highest and lowest
  category. Click a department for its category averages next to the company's, the **ranking** of
  its employees, and its trend.
- **Employees**: every employee's score, number of evaluations received, and how many colleagues
  they evaluated, with search, department and status filters, and sorting. Click an employee for:
  - average per category next to the department and company averages (table and radar chart),
  - the number of evaluators,
  - anonymous comments,
  - the trend across periods with the department average at the time.
- **Completion Tracker**: who has not finished evaluating, per department and per employee, with a
  *Not finished* filter and a CSV download.
- **Printable Report**: one page with the summary, departments, category averages and every
  employee's scores, for the whole company or one department. Use **Print report**, or download
  the employee and department results as CSV (opens in Excel).

Scores of an employee with fewer than 3 evaluations stay hidden everywhere (shown as *Not enough
evaluations*), so that no single evaluator can be identified.

## 6. User guide: Admin (HR)

- **Admin Dashboard**: account counts, locked accounts, PINs still to be changed, progress of the
  open period, shortcuts and recent activity.
- **Departments**: add, edit, activate or deactivate. Members of an inactive department do not take
  part in evaluations. A department with employees or evaluations cannot be deleted.
- **Employees**: search and filter; **Add employee**; **Edit** (department, role, status, and so
  on); **Reset PIN** (back to the default PIN, or a random PIN shown once, see Settings);
  **Unlock** a locked account; **Activate / Deactivate** (an inactive employee cannot log in, and
  their past evaluations are kept). An employee with evaluations cannot be deleted. When an
  employee changes department, the evaluations already submitted keep the old department.
- **Import from CSV**: download the template, fill it in, upload it. Columns: `employee_number`,
  `first_name`, `last_name` (required) and `email`, `position`, `department`, `role`,
  `date_hired` (YYYY-MM-DD). The whole file is checked first; if any row has a problem nothing is
  imported and every problem is listed by row. Comma and semicolon files both work.
- **Categories**: add, edit, reorder, set the **weight** and whether *Not applicable* is allowed.
  **Manage criteria** adds specific rating items inside a category. A category or criterion that
  already has scores cannot be deleted; deactivate it to remove it from the form.
- **Evaluation Periods**: create a period (it starts as a *draft*), **Open** it to start the
  evaluations, **Close** it at the end. Only one period can be open at a time. A closed period can
  be reopened. A period with evaluations cannot be deleted.
- **Roles and Permissions**: a grid of roles and permissions. For example, tick `results.view` for
  Admin to let HR see results, or tick `evaluations.receive` for CEO (and give the CEO a
  department) to have the CEO evaluated. You cannot remove `roles.manage` from your own role.
- **Audit Log**: logins, failed logins, lockouts, PIN changes and resets, session timeouts, and
  every change made in Administration, with search, action and date filters.
- **Settings**: see the next section.

## 7. How scores are calculated

1. Inside one evaluation, a **category score** is the rating given to the category, or the average
   of its criteria ratings. *Not applicable* adds nothing.
2. An employee's **category average** is the average of those category scores over all the
   evaluations they received in the period.
3. The **overall score** is the weighted average of the category averages, using each category's
   weight. By default Quality of Work and Productivity weigh 1.5 and the rest 1.0.
4. **Department** and **company averages** use the same steps over all evaluations in the
   department (the department at the time of the evaluation) or the company.
5. **Completion** = evaluations submitted / evaluations expected, where *expected* is every active
   colleague in the evaluator's current department.

Anonymity: the evaluator is stored (to prevent duplicates) but never shown to the person
evaluated. Comments are listed in a fixed random order, not by date. The audit log records that an
evaluation was submitted, but not for whom.

## 8. Settings

| Setting | Default | Meaning |
|---|---|---|
| Company name | Evergreen Solutions Inc. | Shown in the header and on reports |
| Employees can see their own summary | On | Turns My Summary on or off |
| Show employee summaries only after a period is closed | On | When off, employees also see the open period |
| Employees can see individual ratings | Off | Each anonymous evaluation, not only averages |
| Employees can see comments they received | On | Always without names |
| Minimum evaluations before an average is shown | 3 | Anonymity threshold (1 to 20) |
| Require an overall comment | Off | Makes the comment field mandatory |
| Allow editing until the period closes | Off | When off, evaluations are final once submitted |
| Show evaluator names on comments in the results pages | Off | For the CEO pages |
| PIN for new and reset accounts | Default PIN | Or a random PIN shown once to HR |
| Default PIN | 1234 | Users must change it at first login |
| Failed logins before an account is locked | 5 | 3 to 10 |
| Lockout duration (minutes) | 15 | HR can unlock earlier |
| Log out after inactivity (minutes) | 15 | Session timeout |

## 9. Roles and permissions

Pages check **permissions**, which are stored in the database (`permissions` and
`role_permissions`), never role names.

| Permission | CEO | Admin | Employee |
|---|:-:|:-:|:-:|
| `results.view`: view all results and statistics | Yes | | |
| `results.export`: printable report and CSV | Yes | | |
| `completion.view`: completion tracker | Yes | Yes | |
| `evaluations.submit`: evaluate department mates | | Yes | Yes |
| `evaluations.receive`: be evaluated | | Yes | Yes |
| `summary.view_own`: My Summary | | Yes | Yes |
| `departments.manage`, `employees.manage`, `pins.reset`, `categories.manage`, `periods.manage`, `roles.manage`, `audit.view`, `settings.manage` | | Yes | |

## 10. Security

- PINs are stored only as `password_hash()` hashes and checked with `password_verify()`.
- Accounts lock for 15 minutes after 5 wrong PINs in a row (both configurable); HR can unlock.
  An unknown Employee Number takes as long to reject as a wrong PIN, so response times do not
  reveal which numbers exist.
- New and reset PINs must be changed at the next login.
- The session id changes at login, after a PIN change, and every 10 minutes. The cookie is
  HttpOnly and SameSite=Lax. Users are logged out after 15 minutes without activity.
- Every form carries a CSRF token, checked centrally for every POST request. Logout is a POST.
- All queries use PDO prepared statements. All output is escaped with `htmlspecialchars()`.
- Every page checks the user's permissions, and the account is re-checked on every request, so a
  deactivated account is logged out immediately.
- A Content Security Policy blocks inline and third-party scripts. Pages cannot be framed.
- `.htaccess` blocks web access to `config/`, `includes/` and `database/`.
- The database enforces the rules as well: one evaluation per evaluator, evaluatee and period;
  no self-evaluation; scores 1 to 5; only one open period.
- CSV exports are protected against formula injection in Excel.

## 11. Project structure

```
employee_evaluation/
  index.php            Login page
  change_pin.php       Change PIN (forced at first login)
  logout.php           Log out (POST)
  403.php, 404.php     Friendly error pages
  .htaccess            Error pages, no directory listing
  config/
    constants.php      Fixed settings: timezone, rating scale, page size
    db.php             Database connection (PDO)
  includes/
    bootstrap.php      Loaded by every page: session, timeout, CSRF check
    auth.php           Login, lockout, session, permission checks
    csrf.php           CSRF tokens
    functions.php      Escaping, URLs, settings, audit log, sorting, paging
    evaluation.php     Evaluation rules, score calculations, completion
    header.php         Page header and menu (by permission)
    footer.php         Page footer and scripts
  employee/            My Evaluations, evaluation form, My Summary
  ceo/                 Overview, departments, employees, completion, report, CSV export
  admin/               Dashboard, departments, employees, CSV import, categories,
                       criteria, periods, roles, audit log, settings
  assets/
    css/style.css      All styles; pastel colours as CSS variables
    js/app.js          Small helpers: PIN fields, confirmations, mobile menu
    js/charts.js       Draws the charts from JSON written by PHP
    vendor/            Chart.js 4.4.7
    img/favicon.svg
  database/
    schema.sql         Database, tables, indexes, starting data
    seed.sql           Optional sample evaluation history
```

## 12. Troubleshooting

| Problem | Fix |
|---|---|
| "The application cannot reach its database" | Start MySQL in XAMPP, import `database/schema.sql`, and check `config/db.php`. |
| MySQL does not start in XAMPP | Another MySQL server is probably using port 3306. Stop it, or run XAMPP's MySQL on another port and set that port in `config/db.php`. |
| "Account locked" | Wait for the lockout to end, or log in as HR and use **Unlock** on the Employees page. |
| Forgot the HR or CEO PIN and nobody can reset it | Re-import `database/schema.sql` (this resets all data), or set `must_change_pin = 1` and copy another account's `pin_hash` in phpMyAdmin. |
| Charts do not appear | Make sure `assets/vendor/chart.umd.min.js` exists and JavaScript is enabled. The numbers are always in the tables too. |
| "The form has expired" | The page was open too long or you logged in again in another tab. Reload and try again. |
| Times are off by some hours | Set your timezone in `APP_TIMEZONE` in `config/constants.php` (default `Asia/Manila`). |
