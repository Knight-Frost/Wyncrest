# Glossary

Plain-English definitions of the terms used across these docs.

Who this is for: anyone new to the project, technical or not.

| Term | Meaning |
|---|---|
| Tenant | A person renting, or trying to rent, a home through Wyncrest |
| Landlord | A person listing and managing rental properties on Wyncrest |
| Admin | A staff account that can moderate and govern the platform, with only the permissions it has been granted |
| Super admin | An admin with every permission by default, who also controls what other admins can do |
| Scoped admin | An admin that only holds the specific permissions a super admin has granted it |
| Capability | One specific permission an admin can hold, such as "moderate listings" or "view the audit log" |
| Property | A building or piece of land owned by a landlord |
| Unit | An individual rentable space inside a property, such as an apartment or a room |
| Listing | A unit that is being advertised for rent, with its own approval status |
| Application | A tenant's request to rent a specific listing |
| Contract | The lease agreement between a landlord and a tenant, once an application has been accepted |
| Ledger | The permanent, append-only record of every rent charge, payment, and fee for a lease |
| Audit log | A permanent record of privileged actions taken on the platform, such as suspending a user or approving a listing |
| Notification | An in-app, email, or SMS alert sent to a user when something relevant happens |
| Seed data | Data automatically created to fill a database, either realistic demo data (development) or a minimal safe baseline (production) |
| Dev mode | The local development setup, which creates realistic demo accounts and data for testing |
| Production mode | The real, live setup, which never creates fake people, properties, or money |
| 401 | A response meaning "you are not logged in," or your login has expired |
| 403 | A response meaning "you are logged in, but you are not allowed to do this" |
