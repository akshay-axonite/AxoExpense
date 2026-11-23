# AxonitExpense - PHP Backend API Engine

Welcome to the **AxonitExpense** backend documentation. This backend was engineered to provide a high-performance, native PHP REST API for the **Vue 3 Single Page Application (SPA)** frontend, enabling seamless deployment on any standard PHP shared hosting (e.g. StackCP, cPanel, Apache) without requiring Docker, VPS root access, or background daemon processes.

---

## 1. System Architecture

```
[ User Browser / Mobile App ]
             │
             ▼
[ Apache Web Server / .htaccess ]
     │                         │
     │ /login, /desktop, css, js│ /api/v1/*
     ▼                         ▼
[ Vue 3 SPA Frontend ]     [ api/index.php (Central Router) ]
                               │
                               ├── auth.php (Token Authentication Middleware)
                               │
                               ├── controllers/
                               │    ├── AuthController.php       (Login, Register, Profile, Tokens)
                               │    ├── AccountController.php    (Bank, Cash, Wallets, Balance)
                               │    ├── CategoryController.php   (Income, Expense, Hierarchy)
                               │    ├── TagController.php        (Tags & Groups)
                               │    ├── TransactionController.php(Ledger, Payee, Multi-Currency)
                               │    ├── PictureController.php    (Receipts & Proof Uploads)
                               │    ├── RatesController.php      (Multi-Currency Exchange Rates)
                               │    └── StatsController.php      (Monthly Summaries & Counts)
                               │
                               ▼
                    [ MySQL Database (ezbookkeeping) ]
                               │
                               ▼
               [ storage/transaction/<uid>/... (Receipts & Proofs) ]
```

---

## 2. Directory Structure

```
AxonitExpense/
├── index.html                   # Vue frontend root entry
├── desktop.html                 # Desktop layout SPA entry
├── mobile.html                  # Mobile layout SPA entry
├── server_settings.js           # Client-side feature flags & server configuration
├── .htaccess                    # Root rewrite rules (Clean URLs + API forwarding)
├── css/, js/, img/, fonts/      # Frontend compiled static assets
├── storage/                     # Uploaded receipts, invoices, and payment proof photos
│   └── transaction/
│       └── <uid>/
│           └── <picture_id>.<ext>
│
└── api/                         # PHP Backend REST API Engine
    ├── .htaccess                # API directory request rewriting to index.php
    ├── config.php               # Database credentials & token security key
    ├── db.php                   # PDO MySQL Database singleton
    ├── utils.php                # PBKDF2 password encryption, token generator, JSON helpers
    ├── auth.php                 # Bearer token verification middleware
    ├── index.php                # Central REST router and dispatcher
    ├── README.md                # This developer documentation
    └── controllers/             # Business logic controllers
        ├── AuthController.php   # Login, registration, profile, logout, session tokens
        ├── AccountController.php# Bank, cash, and holding accounts & sub-accounts
        ├── CategoryController.php# Expense and income categories & sub-categories
        ├── TagController.php    # Transaction tags and tag groups
        ├── TransactionController.php # Transactions (INR/USD, payee, attachments)
        ├── PictureController.php# Upload/preview receipt & payment proof images
        ├── RatesController.php  # Live currency rates & user custom rates
        └── StatsController.php  # Monthly summary analytics & data statistics
```

---

## 3. Security & Password Compatibility

### PBKDF2-SHA256 Password Hashing
The backend uses the exact same password encoding algorithm as ezBookkeeping's Go backend:
* **Algorithm:** PBKDF2 with SHA-256
* **Iterations:** 10,000
* **Output Key Length:** 48 bytes (raw binary)
* **Final Format:** Base64 encoded string with trimmed trailing `=` padding.

```php
// Byte-for-byte identical to Go's EncodePassword
$hash = hash_pbkdf2('sha256', $password, $salt, 10000, 48, true);
return rtrim(base64_encode($hash), '=');
```
> **Notice:** Existing users in MySQL (e.g. `Akshay`) can log in immediately without password resets.

### Token Session Management
* On successful login, a session record is inserted into `token_record`.
* A signed bearer token is generated with format `ebk-<base64url(payload:signature)>`.
* On each request, the frontend sends `Authorization: Bearer <token>`.
* `AuthMiddleware::authenticate()` verifies the signature and validates token expiration against `token_record`.

---

## 4. Complete API Endpoints Catalog

| Route | Method | Controller | Description |
| :--- | :--- | :--- | :--- |
| `/api/v1/users/login.json` | `POST` | `AuthController` | Authenticate user with `loginName` and `password` |
| `/api/v1/authorize.json` | `POST` | `AuthController` | Alternative login endpoint matching Go router |
| `/api/v1/users/register.json` | `POST` | `AuthController` | Register new user account with default currency |
| `/api/v1/users/profile/get.json` | `GET` | `AuthController` | Fetch profile details of authenticated user |
| `/api/v1/users/profile/update.json`| `POST` | `AuthController` | Update email, nickname, currency, first day of week |
| `/api/v1/tokens/list.json` | `GET` | `AuthController` | List active sessions |
| `/api/v1/tokens/refresh.json` | `POST` | `AuthController` | Refresh session token expiration |
| `/api/v1/tokens/revoke.json` | `POST` | `AuthController` | Log out and revoke active session |
| `/api/v1/accounts/list.json` | `GET` | `AccountController` | Retrieve user accounts with nested sub-accounts & balances |
| `/api/v1/accounts/get.json` | `GET` | `AccountController` | Get single account by ID |
| `/api/v1/accounts/add.json` | `POST` | `AccountController` | Create new account or parent account with sub-accounts |
| `/api/v1/accounts/modify.json` | `POST` | `AccountController` | Update account details (name, color, icon, comment) |
| `/api/v1/accounts/hide.json` | `POST` | `AccountController` | Toggle account visibility |
| `/api/v1/accounts/move.json` | `POST` | `AccountController` | Reorder accounts display sequence |
| `/api/v1/accounts/delete.json` | `POST` | `AccountController` | Soft delete account and its sub-accounts |
| `/api/v1/transaction/categories/list.json` | `GET` | `CategoryController` | Get categories grouped by type (Income, Expense, Transfer) |
| `/api/v1/transaction/categories/get.json` | `GET` | `CategoryController` | Get single category by ID |
| `/api/v1/transaction/categories/add.json` | `POST` | `CategoryController` | Create single category |
| `/api/v1/transaction/categories/add_batch.json`| `POST` | `CategoryController` | Batch create categories with sub-categories |
| `/api/v1/transaction/categories/modify.json` | `POST` | `CategoryController` | Update category details |
| `/api/v1/transaction/categories/hide.json` | `POST` | `CategoryController` | Toggle category visibility |
| `/api/v1/transaction/categories/move.json` | `POST` | `CategoryController` | Reorder categories sequence |
| `/api/v1/transaction/categories/delete.json` | `POST` | `CategoryController` | Soft delete category and its children |
| `/api/v1/transaction/tags/list.json` | `GET` | `TagController` | Retrieve all tags for current user |
| `/api/v1/transaction/tags/add.json` | `POST` | `TagController` | Create a new tag |
| `/api/v1/transaction/tags/modify.json` | `POST` | `TagController` | Modify tag |
| `/api/v1/transaction/tags/delete.json` | `POST` | `TagController` | Delete tag |
| `/api/v1/transaction/tags/groups/list.json` | `GET` | `TagController` | Retrieve tag groups |
| `/api/v1/transactions/list.json` | `GET` | `TransactionController` | List transactions with filters, payee, currency, receipts |
| `/api/v1/transactions/list/by_month.json` | `GET` | `TransactionController` | List transactions within a given month |
| `/api/v1/transactions/count.json` | `GET` | `TransactionController` | Total count of filtered transactions |
| `/api/v1/transactions/get.json` | `GET` | `TransactionController` | Get single transaction detail |
| `/api/v1/transactions/add.json` | `POST` | `TransactionController` | Create income, expense, transfer, or balance update |
| `/api/v1/transactions/delete.json` | `POST` | `TransactionController` | Delete transaction and revert balance effect |
| `/api/v1/transactions/statistics.json` | `GET` | `StatsController` | Income and expense totals by category/account |
| `/api/v1/transactions/amounts.json` | `GET` | `StatsController` | Income & expense sums for dashboard overview |
| `/api/v1/transaction/pictures/upload.json` | `POST` | `PictureController` | Upload receipt or payment proof image |
| `/api/v1/transaction/pictures/remove_unused.json` | `POST` | `PictureController` | Clean up unlinked picture upload |
| `/api/v1/exchange_rates/latest.json` | `GET` | `RatesController` | Multi-currency conversion rates (INR, USD, EUR, etc.) |
| `/api/v1/data/statistics.json` | `GET` | `StatsController` | Database record counts (accounts, transactions, categories) |
| `/api/v1/systems/version.json` | `GET` | `StatsController` | Backend engine version |

---

## 5. Deployment Instructions (Shared Hosting / FileZilla)

1. Open **FileZilla** and connect to your hosting server via FTP/SFTP.
2. Navigate to your domain's web root (`public_html/`).
3. Upload all contents of the `AxonitExpense/` directory directly into `public_html/`.
4. Open `api/config.php` on the server and update your MySQL database credentials:
   ```php
   'db' => [
       'host'     => '127.0.0.1', // or localhost
       'port'     => 3306,
       'dbname'   => 'your_cpanel_db_name',
       'username' => 'your_cpanel_db_user',
       'password' => 'your_cpanel_db_password'
   ]
   ```
5. Ensure the `storage/` folder has write permissions (`0755` or `0775`).
6. Visit your domain in browser: `https://yourdomain.com/login`. All features will be active immediately.
