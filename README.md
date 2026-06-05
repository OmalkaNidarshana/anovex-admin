# Anovx Technology — Admin Dashboard Backend

PHP + MySQL REST API powering the Anovx Admin Dashboard.

---

## Stack

| Layer      | Technology              |
|------------|-------------------------|
| Language   | PHP 8.1+                |
| Database   | MySQL 8.0+              |
| Auth       | Bearer token (SHA-256)  |
| Server     | Apache (with mod_rewrite) |

---

## Project Structure

```
anovx-backend/
├── .htaccess               ← Apache routing & security rules
├── database.sql            ← Full schema + seed data
├── config/
│   └── db.php              ← PDO connection singleton
├── middleware/
│   ├── auth.php            ← Bearer token guard
│   └── helpers.php         ← jsonResponse(), getBody(), etc.
└── api/
    ├── auth.php            ← Login / logout / me
    ├── dashboard.php       ← Aggregated KPIs
    ├── proposals.php       ← Proposal CRUD
    ├── invoices.php        ← Invoice CRUD + status
    └── clients.php         ← Client CRUD
```

---

## Setup

### 1. Database

```bash
mysql -u root -p < database.sql
```

This creates the `anovx_admin` database with all tables and seed data.

Default admin account:
- **Email:** admin@anovx.com
- **Password:** admin123

### 2. Configure DB credentials

Edit `config/db.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'your_mysql_user');
define('DB_PASS', 'your_mysql_password');
```

### 3. Deploy

Copy the entire folder to your Apache web root (e.g. `/var/www/html/anovx/`).

Enable `mod_rewrite` if not already active:

```bash
sudo a2enmod rewrite
sudo systemctl restart apache2
```

---

## Authentication

All endpoints (except login) require a Bearer token in the request header:

```
Authorization: Bearer <token>
```

### Login

```
POST /api/auth?action=login
Content-Type: application/json

{
  "email": "admin@anovx.com",
  "password": "admin123"
}
```

**Response:**
```json
{
  "token": "abc123...",
  "expires_at": "2025-06-06 14:00:00",
  "user": { "id": 1, "name": "Alex Torres", "email": "...", "role": "admin" }
}
```

### Logout

```
POST /api/auth?action=logout
Authorization: Bearer <token>
```

### Current User

```
GET /api/auth?action=me
Authorization: Bearer <token>
```

---

## API Reference

### Dashboard

| Method | Endpoint       | Description               |
|--------|----------------|---------------------------|
| GET    | /api/dashboard | KPIs, recent activity     |

---

### Proposals

| Method | Endpoint              | Description               |
|--------|-----------------------|---------------------------|
| GET    | /api/proposals        | List all proposals        |
| GET    | /api/proposals?id=1   | Single proposal           |
| POST   | /api/proposals        | Create proposal           |
| PUT    | /api/proposals?id=1   | Full update               |
| PATCH  | /api/proposals?id=1   | Partial update            |
| DELETE | /api/proposals?id=1   | Delete (admin only)       |

**Query filters (GET list):**
- `?status=Approved`
- `?search=brand`
- `?page=1&per_page=20`

**Create body:**
```json
{
  "client_id": 1,
  "title": "Website Redesign",
  "description": "Full redesign of the corporate website.",
  "value": 15000,
  "status": "Pending",
  "deadline": "2025-09-01"
}
```

---

### Invoices

| Method | Endpoint                         | Description               |
|--------|----------------------------------|---------------------------|
| GET    | /api/invoices                    | List invoices             |
| GET    | /api/invoices?id=1               | Single invoice + items    |
| POST   | /api/invoices                    | Create invoice            |
| PUT    | /api/invoices?id=1               | Full update + replace items |
| PATCH  | /api/invoices?id=1&action=status | Change status             |
| DELETE | /api/invoices?id=1               | Delete draft (admin only) |

**Create body:**
```json
{
  "client_id": 2,
  "issue_date": "2025-06-05",
  "due_date": "2025-07-05",
  "tax_rate": 8,
  "notes": "Net 30 payment terms.",
  "items": [
    { "description": "UI/UX Design", "quantity": 1, "unit_price": 4500 },
    { "description": "Frontend Development", "quantity": 40, "unit_price": 85 }
  ]
}
```

**Mark as paid:**
```
PATCH /api/invoices?id=1&action=status
{ "status": "Paid" }
```

---

### Clients

| Method | Endpoint            | Description           |
|--------|---------------------|-----------------------|
| GET    | /api/clients        | List clients          |
| GET    | /api/clients?id=1   | Client + stats        |
| POST   | /api/clients        | Create client         |
| PUT    | /api/clients?id=1   | Update client         |
| DELETE | /api/clients?id=1   | Delete (admin only)   |

---

## Frontend Integration Example (JavaScript)

```js
// Login
const res = await fetch('/api/auth?action=login', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ email: 'admin@anovx.com', password: 'admin123' })
});
const { token } = await res.json();

// Authenticated request
const proposals = await fetch('/api/proposals', {
  headers: { Authorization: `Bearer ${token}` }
}).then(r => r.json());

// Create a proposal
await fetch('/api/proposals', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    Authorization: `Bearer ${token}`
  },
  body: JSON.stringify({
    client_id: 1,
    title: 'New Campaign',
    value: 12000,
    deadline: '2025-08-01'
  })
});
```

---

## Security Notes

- Passwords are stored as **bcrypt hashes** (`password_hash` with `PASSWORD_BCRYPT`)
- Tokens are stored as **SHA-256 hashes** — raw token never persisted
- Sessions expire after **8 hours**
- An hourly MySQL event auto-purges expired sessions
- Direct access to `config/` and `middleware/` is blocked via `.htaccess`
- Only `Draft` or `Cancelled` invoices can be deleted
- Only `admin` role can delete proposals, invoices, and clients

---

## Regenerate Admin Password

```php
echo password_hash('your_new_password', PASSWORD_BCRYPT, ['cost' => 12]);
```

Then update directly in the database:

```sql
UPDATE users SET password = '<hash>' WHERE email = 'admin@anovx.com';
```
