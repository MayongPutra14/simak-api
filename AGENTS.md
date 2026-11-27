# SIMAK API — agent notes

Plain PHP + MySQL backend (no framework, Composer, build step, or test suite). Code comments mix Indonesian and English; keep that style.

## Run and verify

- Served by XAMPP Apache from `C:\xampp\htdocs\simak_api`, so endpoints are `http://localhost/simak_api/api/<file>.php`.
- MySQL `simak_db` on `localhost:3306`, user `root`, empty password. Connection lives in `api/koneksi.php`, which is gitignored — create it from `api/koneksi-example.php` and fill in DB + external service credentials.
- There is no install/build/lint/test command. Verify changes by calling the affected endpoint against a running Apache + MySQL; request/response history is appended to `logs/api_request_YYYY-MM-DD.log`.

## Architecture

- `api/*.php` are entrypoints. Each handles CORS, calls `logIncomingRequest()`, requires `api/koneksi.php` + one controller, then routes `switch ($action)`, where `action` comes from `?action=` or the JSON/form body. Adding an endpoint means adding a `case` in the matching entrypoint **and** a controller method.
- Role split: `api/user.php` is shared (plus public hadith/doa proxy endpoints), `api/admin.php` and `api/leader.php` hold role-specific actions. `login.php` and `register.php` are standalone: no controller, no request logger.
- Controllers are `controllers/{Admin,Leader,User}Controller.php`. Admin and Leader controllers are largely duplicated — apply shared fixes to both. Each returns JSON through a private `response($statusCode, $statusText, $message, $data)` helper and exits.
- Event status (`mendatang`/`live`/`selesai`) is derived from `NOW()` and synced in two places: `syncEventStatuses()` inside the controllers and `api/cron_update_event.php`. Change both.
- Images are Cloudinary URLs stored in `users.gambar`; event materials are uploaded through multipart `$_FILES['material']` into `uploads/` and stored as `uploads/<filename>` in `events.file_path`.

## Gotchas

- Session auth exists only for `get_my_attendance`: `login.php` stores the identity in `$_SESSION`, and `api/auth.php` validates it. Every other endpoint still trusts `user_id` from the payload, and only some queries filter by `role` — never assume they are authorized.
- Production entrypoints set `error_reporting(0)` and `display_errors=0`, so fatals surface as empty 500s. Temporarily enable errors locally, then revert; use `logs/` and `error_log` for diagnosis.
- Known broken route: `update_user_profile` in `api/user.php` calls `$adminController`, which is never defined there. That action only works through `admin.php`/`leader.php`.
- Known broken script: `api/cron_update_event.php` requires `__DIR__ . '/api/koneksi.php'`, resolving to `api/api/koneksi.php`. Fix the path before relying on it.
- Secrets are hardcoded in several places (`api/koneksi.php`, the external API-key constants, `scripts/cleanup_cloudinary.php`, prompt files). Do not add real credentials to tracked files. `api/koneksi.php`, `prompts/`, `scripts/`, `uploads/`, and `logs/` are gitignored.
- `prompts/` contains stale Indonesian working notes from earlier sessions, not documentation of record. Trust the code and route tables instead.
- Requests may be JSON or form-data, and the entrypoints fall back to `$_POST`; file endpoints require multipart so `$_FILES` is populated.
- CORS is not centralized: the allowlist (`localhost:5173`, `127.0.0.1:5173`, `localhost:3000`, otherwise `*`) is duplicated per entrypoint, while `login.php`/`register.php` echo the request `Origin`. Update every file when the frontend origin changes.
- Commit messages follow Conventional Commits (`feat:`, `fix(scope):`).

## Backend Architecture & Routing Conventions

- **Action-Based POST Routing**: All API requests use HTTP `POST` routed via a `switch ($action)` dispatcher (e.g., `case 'get_profile':`, `case 'update_password':`). Use `snake_case` for all `action` names.
- **Controller & Request Handling**: Delegate business logic from the `switch ($action)` router directly to the appropriate controller method (e.g., `$userController->getUserAttendance($requestData)`).
- **Security & Auth**: Always extract the authenticated `user_id` from the verified session/token middleware—never trust raw user IDs passed in `$requestData` payloads.
- **API Contract Alignment**: Follow `docs/api-contract-attendance.md` (and any future feature contracts in `docs/`) strictly for JSON response wrappers, field names (`camelCase` or project standard), data types, and nullability rules.
- **Documentation & Task Tracking**: Refer to `docs/` for PRDs and API contracts before writing endpoint code.
- **Database Schema Reference**: Always inspect `database/schema.sql` to verify exact table names, column names, and data types before writing SQL queries.

## Database Schema & Migration Safety Rules

- **Schema Reference**: Always read `database/schema.sql` before writing SQL queries to match exact table and column names.
- **No Silent Schema Changes**: NEVER modify, drop, or alter existing production tables/columns silently. Always ask for explicit approval before proposing any database schema change.
- **Migration Log (`database/migrations.sql`)**: Whenever a schema change (new table, new column, or altered column) is approved, append the exact `ALTER TABLE` / `CREATE TABLE` query to `database/migrations.sql` with a date comment, update `database/schema.sql`, and explicitly list every SQL change in your final response so it can be safely applied to production.
