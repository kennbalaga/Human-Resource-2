# HIMS-HR — conventions
- Laravel + MySQL. Services in app/Services, thin controllers.
- NEVER run migrate:fresh, migrate:refresh, or db:seed. Ask me to run migrations.
- Business rules live in services, not controllers or models.
- ScheduleAssignment = published shifts only. Drafts go through RosterDraftService.
- AI must never write directly to schedule_assignments.
