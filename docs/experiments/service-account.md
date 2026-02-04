# Service Accounts

## Summary
Adds a service account user type for automated tools and programmatic access. The experiment registers a dedicated role, customizes the wp-admin user creation flow to auto-generate usernames/emails, and provides a service-focused edit screen with ownership metadata. Service accounts are flagged via user meta so the role can be changed without losing service account behavior. Application Passwords are created manually after the account is saved.

## Key Hooks & Entry Points
- `WordPress\AI\Experiments\Service_Account\Service_Account::register()` initializes the manager, admin UI, and REST routes.
- `Service_Account_Manager::init()` registers the role, filters capabilities, and excludes service accounts from default user queries and counts.
- `Admin_UI::enqueue_new_user_scripts()` injects inline JS on `user-new.php` to show the Service Account name field and auto-populate username/email.
- `Admin_UI::add_service_account_fields()` adds service-specific fields (owner user selector, system, reference) and reorders native fields on the edit screen.
- `Admin_UI::handle_user_register()` sets service account meta for admin-created users.
- `REST_Service_Accounts_Controller` exposes `/wp-json/ai/v1/service-accounts` for CRUD and app-password regeneration.

## Assets & Data Flow
1. Admin UI enqueues inline CSS and JS only on user-related screens (`users.php`, `user-new.php`, `user-edit.php`).
2. On `user-new.php`, selecting the Service Account role reveals the name field and fills username/email using the site domain.
3. During submission, PHP fills missing username/email as a fallback and writes service account meta.
4. On `user-edit.php`, the service account details section moves the key fields (username, email, role, purpose) to the top and adds owner (WordPress user), system, and reference fields stored in user meta. Owner selection requires `list_users`.
5. Service account meta keys (`service_account_owner_id`, `service_account_system`, `service_account_reference`) are registered via `register_meta()` with sanitization and permission callbacks.
6. Application Passwords are created from the user edit screen after the account is saved.

## Testing
1. Enable the **Service Accounts** experiment.
2. Go to `Users > Add New`, select the **Service Account** role, and enter a name.
3. Confirm username/email are populated and the password field remains visible.
4. Submit the form and open the created user to generate Application Passwords in the section below.
5. Edit the created user and confirm it is labeled as a service account and has Application Passwords available.

## Notes
- Application Passwords are generated from the user edit screen (they are not auto-generated on creation).
- Service accounts are excluded from standard user counts and queries unless explicitly requested.
