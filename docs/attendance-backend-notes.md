# Attendance backend notes

Decisions made while implementing `docs/api-contract-attendance.md`. This file records backend
behavior that the frontend contract does not fully pin down.

## Endpoint

- `POST /simak_api/api/user.php`
- Body: `{ "action": "get_my_attendance", "user_id": <optional> }`
- `user_id` is accepted for compatibility but must match the session user.
- Only role `user` (congregant) may call this action.
- Response shape follows contract §2.2 exactly; `data` is always an array.

## Authentication

- `api/login.php` now starts a PHP session (`PHPSESSID`) after a successful password check and
  stores `user_id`, `role`, and `zona`.
- `api/auth.php` provides `startApiSession()` and `getAuthenticatedUser(PDO)`. The user row is
  re-read from the database on every authenticated request.
- Cookies are `HttpOnly`, `path=/`, `SameSite=Lax` over HTTP and `SameSite=None; Secure` over
  HTTPS. Cross-site production deployments therefore require HTTPS.
- The frontend **must** send credentials (`withCredentials: true` on the axios instance) for both
  login and `get_my_attendance`; otherwise the browser discards the session cookie and this
  action returns `failed`.
- CORS for `user.php` and `login.php` reflects the request `Origin` (with `Vary: Origin`) because
  a wildcard origin cannot be combined with `Access-Control-Allow-Credentials: true`.

## Failure strategy

Application failures return **HTTP 200** with `status: "failed"` and an Indonesian `message`, per
contract §2.4. This keeps the frontend's existing `error.message` handling meaningful. Database
errors are logged with `error_log()`; SQL details are never returned to the client.

Cases covered:

- missing/invalid session
- non-congregant role
- body `user_id` differs from the session user
- unexpected query failure

## Attendance data semantics

- Only events with `status = 'selesai'` are returned.
- Event scope mirrors `UserController::getEvents`: `jenis_event = 'jamaah' OR zona_target = user.zona`.
- An event the user actually attended is also included even if their current `zona` no longer
  matches (preserves history after a zone change).
- Absent rows are derived at query time by joining the user against completed events and
  left-joining `attendances`; no row is written for absences.
- Users registered after an event still receive an absent row for that event (no
  `users.created_at` filter), per PRD §5.3.
- `SET time_zone = '+07:00'` is applied per request so `waktu_absen` is formatted in WIB.
- `eventDate` is `YYYY-MM-DD`; `checkInTime` is `HH:mm` for `hadir` and `null` for `absen`.
- `id` is `attendances.id` for present rows and `"abs-<event_id>"` for derived absent rows.

## Schema status

No schema change was required for this endpoint. `attendances` already enforces
`UNIQUE(user_id, event_id)`.

Future admin/leader recap endpoints cannot honor PRD §5.4 (historical region) with the current
schema: `attendances` has no region snapshot and `users.zona` is mutable. A separate approved
migration is required before those endpoints are implemented.
