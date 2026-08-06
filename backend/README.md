# Backend PHP/MySQL for FacTo

This backend is a minimal API layer for the FacTo frontend.

## Setup
1. Create a MySQL database named `facto`.
2. Create a MySQL user and grant access to the `facto` database.
3. Import `database.sql` into the new database.
4. Update `config.php` with your MySQL credentials.
5. Run from the project root so the frontend and backend are served together:
   ```powershell
   cd "c:\Users\Espendi Piocel\Desktop\FacTo"
   php -S localhost:8000
   ```
6. Open `http://localhost:8000/index.html` in your browser.

## Endpoints
- `POST /backend/auth.php?action=register`
- `POST /backend/auth.php?action=login`
- `GET /backend/dashboard.php`
- `GET /backend/invoices.php`
- `GET /backend/notifications.php`
- `GET /backend/subscription.php`
- `POST /backend/support.php`
- `GET /backend/health.php`

## Notes
- The backend uses PDO with prepared statements.
- Sessions are used for authenticated dashboard and user-specific data endpoints.
- Frontend integration requires replacing static form submissions with AJAX requests to these endpoints.
