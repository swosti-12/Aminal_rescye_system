# Case Lifecycle — Implementation Guide

RescueNet uses **PHP + MySQL** for the admin app. Flask (`app.py`) remains the ML API only.

## 1. Database migration

Run once in phpMyAdmin:

```sql
SOURCE database/migrate_case_lifecycle.sql;
```

Or paste `database/migrate_case_lifecycle.sql` into the SQL tab.

**Adds to `rescue_cases` and `rescue_requests`:**

| Column | Type | Purpose |
|--------|------|---------|
| `is_archived` | TINYINT(1) | `0` = active queue, `1` = history only |
| `archived_at` | TIMESTAMP | When archived |
| `marked_as_read` | TINYINT(1) | Admin “read” on archive |
| `previous_status` | VARCHAR(32) | Last status before change (audit) |

**Status flow:** `pending` → `assigned` → `in_progress` → `completed` / `rescued` → archived

Legacy values `accepted` and `resolved` are normalized automatically.

## 2. Active queue SQL

```sql
SELECT r.*, c.status AS case_status
FROM rescue_requests r
JOIN users u ON r.user_id = u.id
LEFT JOIN rescue_cases c ON r.case_id = c.id
WHERE COALESCE(r.is_archived, 0) = 0
  AND (c.id IS NULL OR (COALESCE(c.is_archived, 0) = 0
       AND c.status IN ('pending','under_review','assigned','in_progress','accepted')))
ORDER BY r.created_at DESC;
```

## 3. History SQL

```sql
SELECT c.*, u.name AS reporter_name, rep.name AS rescuer_name
FROM rescue_cases c
JOIN users u ON c.reporter_id = u.id
LEFT JOIN users rep ON c.assigned_rescuer_id = rep.id
WHERE COALESCE(c.is_archived, 0) = 1
ORDER BY COALESCE(c.archived_at, c.resolved_at, c.created_at) DESC;
```

## 4. Backend files

| File | Role |
|------|------|
| `backend/Services/CaseLifecycleService.php` | Archive rules, notifications, audit, queries |
| `backend/api/admin_active_queue.php` | JSON active queue |
| `backend/api/admin_case_history.php` | JSON history + filters + pagination |
| `backend/api/admin_update_case_status.php` | POST status update (AJAX) |
| `backend/api/admin_queue_stats.php` | Dashboard counters |

## 5. Frontend files

| File | Role |
|------|------|
| `admin_dashboard.php` | Active queue UI, history tab, archive modal |
| `assets/js/admin-queue.js` | Remove card on archive, counters, history load |
| `assets/css/admin-queue.css` | Badges, sub-tabs, modal |

## 6. Admin workflow

1. Open **AI & requests** tab.
2. **Active rescue queue** — only pending / under review / assigned / in progress.
3. **Update status** → active statuses keep the card; terminal statuses open **“Archive this case?”** modal.
4. On confirm: case archived, card removed without reload, counters refresh.
5. **Archived / history** — search, date, status, rescuer filters + pagination.

## 7. Notifications on archive

- Reporter: `user_notifications` — “Your rescue case has been completed successfully.”
- Rescuer: `admin_notifications` (broadcast) with case summary.

## 8. Activity log

`admin_activity_log.action_type = 'case_status_change'` with JSON `details`:

```json
{"previous_status":"pending","new_status":"completed","archived":true,"timestamp":"..."}
```

## 9. Rescuer completion

`rescuer_dashboard.php` → **Mark completed** calls `CaseLifecycleService::updateCaseStatus(..., 'rescued')` so the case leaves the admin active queue automatically.

## 10. Flask note

No Flask changes required. Optional future endpoint could mirror `admin_update_case_status.php` for external tools.
