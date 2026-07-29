# Barangay Health Center Survey Management System

A web-based survey management system built for **IT 305 – Advance Web Development**
(Student Group Activity, Act 5 – Set B), using **HTML, CSS, JavaScript, PHP, and MySQL**.

## Features

**Resident Module**
- Login using Resident Number (default password = Resident Number)
- Forced password change on first login
- View and answer active surveys (one submission per survey, enforced by the database)
- Confirmation page after submission
- Update personal profile

**Staff/Admin Module**
- Secure login
- Create, edit, and activate/deactivate surveys
- Add questions of type: Multiple Choice, Yes/No, Rating Scale (1–5), Short Answer
- Set survey opening/closing dates
- View respondents and monitor participation
- Automatic computation of survey results with bar-chart style visual breakdowns
- Printable reports and CSV export (opens directly in Excel)
- Manage resident accounts (add residents, reset passwords)
- Manage staff accounts

## Requirements
- PHP 7.4+ (tested against PHP 8.x syntax)
- MySQL / MariaDB
- A local server stack such as **XAMPP**, **WAMP**, or **Laragon**

## Setup Instructions

1. Copy the `barangay_survey_system` folder into your server's web root:
   - XAMPP: `C:\xampp\htdocs\barangay_survey_system`
   - WAMP: `C:\wamp64\www\barangay_survey_system`

2. Start Apache and MySQL from your control panel.

3. Import the database:
   - Open **phpMyAdmin** (`http://localhost/phpmyadmin`)
   - Click **Import**, choose `database/barangay_survey.sql`, and run it.
   - This creates the `barangay_survey_system` database with all tables and sample data.

4. Check `config/db.php` and update `DB_USER` / `DB_PASS` if your MySQL setup requires
   credentials other than the XAMPP default (`root` / no password).

5. Open the system in your browser:
   ```
   http://localhost/barangay_survey_system/
   ```

## Default Login Credentials

| Role     | Username / Resident No. | Password   |
|----------|--------------------------|------------|
| Staff    | `admin`                  | `admin123` |
| Resident | `2026-0001`              | `2026-0001`|
| Resident | `2026-0002`              | `2026-0002`|

Residents are prompted to change their password on first login.

## Folder Structure

```
barangay_survey_system/
├── config/
│   ├── db.php              -- database connection
│   └── functions.php       -- shared helper functions (auth guards, escaping, etc.)
├── assets/
│   ├── css/style.css
│   └── js/script.js
├── includes/
│   ├── header_resident.php
│   ├── header_staff.php
│   └── footer.php
├── resident/                -- Resident Module pages
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── surveys.php
│   ├── survey_form.php
│   ├── submit.php
│   ├── confirmation.php
│   ├── profile.php
│   └── change_password.php
├── staff/                    -- Staff/Admin Module pages
│   ├── login.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── survey_management.php
│   ├── add_survey.php
│   ├── edit_survey.php
│   ├── question_management.php
│   ├── resident_management.php
│   ├── results_dashboard.php
│   ├── reports.php
│   ├── export_csv.php
│   └── user_management.php
├── database/
│   └── barangay_survey.sql
├── index.php
└── README.md
```

## Database Design

- `residents` — resident accounts and profile info
- `staff` — staff/admin accounts
- `surveys` — survey metadata, opening/closing dates, status
- `survey_questions` — questions per survey, with a `question_type` enum
- `survey_choices` — choice options for multiple-choice questions
- `responses` — one row per resident submission (UNIQUE constraint on
  `survey_id` + `resident_id` prevents duplicate submissions at the database level)
- `survey_results` — one row per answered question (choice, rating, or free text)
- `login_history` — optional login audit log

## Notes for Your Submission

Remember the activity's submission requirements:
1. Complete source code (this project).
2. Video presentation showing how to use the system.
3. Documentation of which group member developed which part.
4. This `.sql` file (already included in `database/`).
5. Screenshots: Login Page, Dashboard/Home Page, Update Personal Information Page,
   Successful Update Confirmation.
