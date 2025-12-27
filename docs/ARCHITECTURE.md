# Architectural Decision Records (ADR)

## Phase 1 Foundation

This document explains key architectural decisions made in Phase 1.

---

## ADR-001: Separate Authentication Tables for Users and Admins

**Date**: December 2024  
**Status**: Accepted  
**Context**: Nexus requires strict role separation between tenants/landlords and administrators.

**Decision**: Use completely separate tables (`users` and `admins`) with separate authentication flows.

**Rationale**:
- Prevents accidental privilege escalation
- Allows different authentication rules (e.g., MFA requirements for admins)
- Enables separate password policies
- Makes role checks explicit and impossible to bypass
- Simplifies queries (no complex role filtering)

**Consequences**:
- Two authentication guards required
- Cannot reuse authentication middleware
- Admin and user sessions are completely isolated
- Clear security boundary

---

## ADR-002: Feature Gating via Database Tables

**Date**: December 2024  
**Status**: Accepted  
**Context**: Landlords should not access all features immediately. Features should be enabled based on verification status and admin approval.

**Decision**: Use `features` table (master list) and `landlord_features` pivot table (per-landlord enablement).

**Rationale**:
- Queryable: Can easily find all landlords with feature X
- Auditable: Full history of who enabled/disabled features and when
- Enforceable: Backend checks via `FeatureGatingService`
- Flexible: Can add feature dependencies and prerequisites
- Not config-driven: Feature gates are business data, not deployment config

**Consequences**:
- Database queries required to check features
- Must seed features table on deployment
- Cannot change feature availability via .env (intentional)
- Full audit trail of feature changes

---

## ADR-003: Event-Driven Email System

**Date**: December 2024  
**Status**: Accepted  
**Context**: Emails must be triggered by business events, not manually sent from controllers.

**Decision**: All emails are triggered by events, handled by queued listeners.

**Rationale**:
- Decouples email sending from business logic
- Enables async processing (performance)
- Makes email triggers explicit and searchable
- Allows multiple side effects per event (email + audit log)
- Facilitates testing (can disable listeners)

**Consequences**:
- Queue workers required in production
- Email sending is not immediate (eventual consistency)
- Event-listener relationship must be maintained
- All email intents logged to `email_logs`

---

## ADR-004: Immutable Audit Logs

**Date**: December 2024  
**Status**: Accepted  
**Context**: Audit logs are required for compliance and cannot be tampered with.

**Decision**: `audit_logs` table has no `updated_at` column and no update methods. Logs are insert-only.

**Rationale**:
- Guarantees audit trail integrity
- Prevents backdating or hiding actions
- Satisfies compliance requirements
- Makes intent clear (logs are permanent)

**Consequences**:
- Cannot correct typos in audit logs
- Disk space grows indefinitely (archiving strategy needed later)
- Deleting audit logs requires database-level access (intentional)

---

## ADR-005: Soft Deletes for User-Generated Content

**Date**: December 2024  
**Status**: Accepted  
**Context**: Users, properties, listings contain legal and financial data.

**Decision**: Use soft deletes (`deleted_at`) for `users`, `properties`, `units`, `listings`, `conversations`, `messages`.

**Rationale**:
- Preserves legal history
- Enables "undelete" functionality
- Maintains referential integrity in audit logs
- Prevents accidental data loss
- Satisfies data retention requirements

**Consequences**:
- Queries must explicitly exclude soft-deleted records
- Database growth (deleted records remain)
- Must handle soft-deleted records in relationships

---

## ADR-006: Polymorphic Relationships for Conversations

**Date**: December 2024  
**Status**: Accepted  
**Context**: Messaging system needs to support tenant-landlord, tenant-admin, landlord-admin conversations.

**Decision**: Use polymorphic relationships for conversation participants and subjects.

**Rationale**:
- Future-proof (can add new participant types)
- Single conversation table (simplifies queries)
- Supports any message context (listing, application, lease, etc.)

**Consequences**:
- Slightly more complex queries
- Cannot use foreign key constraints on polymorphic columns
- Must validate participant/subject types in application code

---

## ADR-007: Service Layer for Business Logic

**Date**: December 2024  
**Status**: Accepted  
**Context**: Controllers should not contain business logic.

**Decision**: All business logic lives in service classes (`ListingService`, `FeatureGatingService`, `AuditService`, etc.).

**Rationale**:
- Testable without HTTP layer
- Reusable across controllers, commands, jobs
- Clear separation of concerns
- Easier to locate business rules
- Enforces single responsibility

**Consequences**:
- More files/classes
- Controllers become thin (good)
- Services must be dependency-injected
- Business logic is centralized and discoverable

---

## ADR-008: Enums for State Management

**Date**: December 2024  
**Status**: Accepted  
**Context**: States (user types, listing status, property types) should be type-safe.

**Decision**: Use PHP 8.1+ enums for all state fields.

**Rationale**:
- Type safety (cannot assign invalid states)
- IDE autocomplete support
- Centralized state definitions
- Methods on enums (e.g., `isPublic()`, `canBeListed()`)
- Database stores string values (readable)

**Consequences**:
- Requires PHP 8.1+
- Enum definitions must be maintained
- Database stores enum values as strings (slightly more space)

---

## ADR-009: Admin-Only Listing Creation in Phase 1

**Date**: December 2024  
**Status**: Accepted (Temporary)  
**Context**: Phase 1 needs testable listings but landlord UI is Phase 2.

**Decision**: Listings can be created by admins via `ListingService::createListingAsAdmin()` or seeder.

**Rationale**:
- Enables testing of search, filters, public listings
- Avoids building incomplete landlord UI
- Phase 2 will add proper landlord creation flow
- Keeps Phase 1 scope focused

**Consequences**:
- Admin interface needed for listing creation (can be Tinker for Phase 1)
- Landlords cannot create listings yet (expected)
- This decision will be deprecated in Phase 2

---

## ADR-010: Messaging Schema Only in Phase 1

**Date**: December 2024  
**Status**: Accepted  
**Context**: Messaging is inevitable but not critical for Phase 1.

**Decision**: Create `conversations` and `messages` tables but no sending/UI endpoints.

**Rationale**:
- Prevents schema churn (messaging structure defined early)
- Phase 2 implementation will be cleaner
- No risk of incomplete messaging features confusing users

**Consequences**:
- Tables exist but are unused in Phase 1
- No messaging UI or API endpoints yet
- Phase 2 will implement full messaging functionality

---

## Future ADRs

Phase 2 and beyond will add decisions for:
- Lease template management
- Payment processing integration
- Maintenance request workflows
- Admin RBAC system
- Application review process

---

**Last Updated**: December 2024
