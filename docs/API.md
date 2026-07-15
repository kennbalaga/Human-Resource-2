# Workforce REST API

The API is versioned under `/api/v1` and uses Laravel Sanctum bearer tokens. It is designed for Postman, trusted internal clients, and future first-party front ends. Browser login continues to use Breeze session authentication.

## Authentication

Create a token:

```http
POST /api/v1/auth/token
Content-Type: application/json

{
  "employee_id": "HR-0001",
  "password": "ChangeMe123!",
  "device_name": "Postman"
}
```

The response contains `data.token`. Send it on protected requests:

```http
Authorization: Bearer YOUR_TOKEN
Accept: application/json
```

Revoke the current token with `DELETE /api/v1/auth/token`. Tokens expire after `SANCTUM_EXPIRATION` minutes; the default is 1,440 minutes. Do not put tokens in source control or screenshots.

## Abilities and access

| User type | Token abilities | Scope |
|---|---|---|
| Administrator, HR manager, department head | `workforce:read`, `workforce:write`, `analytics:read` | Workforce read/write and analytics |
| Employee | `workforce:read`, `leave:write`, `timesheet:write` | Own records and employee actions |

Role checks are enforced in addition to token abilities. Regular employees cannot list the full employee directory or approve records.

## Endpoints

| Method | Endpoint | Purpose |
|---|---|---|
| POST | `/auth/token` | Create bearer token |
| DELETE | `/auth/token` | Revoke current token |
| GET | `/employees` | List employees; manager only |
| GET | `/employees/{employee}` | Show permitted employee |
| GET | `/attendance` | List permitted attendance |
| GET | `/attendance/{attendance}` | Show attendance record |
| POST | `/attendance/{attendance}/approve` | Approve attendance; manager only |
| GET | `/schedules` | List permitted schedules |
| POST | `/schedules` | Create schedule; manager only |
| PATCH | `/schedules/{schedule}` | Update schedule; manager only |
| DELETE | `/schedules/{schedule}` | Delete schedule; manager only |
| GET | `/timesheets` | List permitted timesheets |
| GET | `/timesheets/{timesheet}` | Show timesheet |
| POST | `/timesheets/{timesheet}/submit` | Submit own timesheet |
| POST | `/timesheets/{timesheet}/approve` | Approve timesheet; manager only |
| POST | `/timesheets/{timesheet}/reject` | Reject timesheet; manager only |
| GET | `/leaves` | List permitted leave requests |
| POST | `/leaves` | Create leave request; supports multipart attachments |
| GET | `/leaves/{leave}` | Show leave request |
| POST | `/leaves/{leave}/approve` | Approve leave; manager only |
| POST | `/leaves/{leave}/reject` | Reject leave; manager only |
| POST | `/leaves/{leave}/cancel` | Cancel a permitted leave request |
| GET | `/analytics` | Aggregate workforce metrics; manager only |

All paths above are relative to `/api/v1`.

## Filtering and pagination

List endpoints return Laravel pagination metadata. Use `per_page` from 1 to 100. Supported filters are discoverable in the included Postman requests and include status, employee, department, and date filters where applicable.

## Response and error conventions

Resources are returned under `data`. Collections include `links` and `meta`. Validation errors return HTTP 422 with an `errors` object. Other common statuses are 401 unauthenticated, 403 forbidden, 404 not found, 429 rate limited, and 500 unexpected server error. Production 500 responses never expose exception details.

API requests are limited to 60 per minute per authenticated user/IP. Token creation is limited to 5 attempts per minute per IP.

## Postman

Import both files:

1. `docs/postman/HRMS-Workforce-API.postman_collection.json`
2. `docs/postman/HRMS-Local.postman_environment.json`

Select **HRMS Local**, run **Create token**, then use the other requests. The token test script stores the bearer token in the active environment automatically.
