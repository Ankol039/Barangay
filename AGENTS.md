# Project memory

## Project and local environment

- Project: Barangay Health Center Survey System.
- Goal: migrate the existing PHP/HTML/CSS application to React while preserving its appearance and functionality.
- Project folder name: `barangay_survey_system`.
- Project root: `/Applications/XAMPP/xamppfiles/htdocs/barangay_survey_system`.
- Configured PHP site URL: `http://localhost/barangay_survey_system/`.
- URL verification on 2026-11-04: XAMPP Apache configuration specifies `ServerName localhost`, `Listen 80`, and `DocumentRoot "/Applications/XAMPP/xamppfiles/htdocs"`. An HTTP request could not connect to localhost port 80, so live serving is not yet verified. Recheck when XAMPP Apache is available.

## Rules for the whole project

1. Before changing anything, read the existing PHP file(s) the task refers to. Do not guess behaviour from filenames.
2. The original PHP pages are the source of truth for UI and behaviour. Do not redesign, rename labels, reword messages or change flows.
3. Never edit, move or delete the existing PHP pages, `config/`, `includes/`, `assets/`, `uploads/` or `database/` files. Only add new files in `/frontend`, `/api` and `/docs` (paths relative to this project root). The one exception is an `/api` bootstrap that includes `config/db.php` and `config/functions.php`; if a change to an existing file is ever unavoidable, stop and ask the user first. The user explicitly authorized creating this root `AGENTS.md` for initial project memory.
4. Reuse `assets/css/style.css`, the logo and the icon set as-is (see `includes/icons.php` for the SVG icon names).
5. Stack: React 18 + Vite + React Router + Chart.js 4.4.4 (`react-chartjs-2`). Plain CSS, no UI framework. Function components and hooks only.
6. Keep the MySQL schema and `config/functions.php` logic. Business rules stay on the server. Use the existing XAMPP MySQL database; do not recreate or reset it.
7. Break every screen into small reusable components. No component over approximately 200 lines. No duplicated markup.
8. Never print or commit secrets. Do not touch the DB credentials in `config/db.php`.
9. After each task: run the build and lint, fix errors, update `docs/progress.md`, and give the user a short summary of files created or changed and how the result was verified. If a build or lint toolchain does not exist yet, record that limitation; do not scaffold extra files outside the authorized task.

## Progress tracking and current scope

- Read `docs/progress.md` at the beginning of each task and update its log table at the end of every task with the prompt number, what was done, and open issues.
- Initial setup authorizes creating only `AGENTS.md` and `docs/progress.md` (and the containing `docs/` directory). Do not create or delete anything else until Prompt 1.
- Initial setup is Prompt 0. Wait for Prompt 1 before starting migration work.
