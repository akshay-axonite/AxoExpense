# AxonitExpense - Enterprise Expense Tracking System

A full-featured, high-performance financial ledger, expense management, and analytics platform. AxonitExpense pairs a modern **Vue 3 Single Page Application (SPA)** with a lightweight, native **PHP 8+ REST API Engine** and **MySQL** database.

Designed to be 100% compatible with the **ezBookkeeping** client specification, this backend can be deployed on standard Apache/PHP shared hosting (cPanel, StackCP, etc.) or run locally without needing Go compilers, Docker containers, or background daemon processes.

---

## Table of Contents
1. [System Architecture](#1-system-architecture)
2. [Directory & File Structure](#2-directory--file-structure)
3. [Database Architecture & Data Models](#3-database-architecture--data-models)
4. [Security, Authentication & Password Hashing](#4-security-authentication--password-hashing)
5. [Complete REST API Reference](#5-complete-rest-api-reference)
   - [Authentication & User Profile](#51-authentication--user-profile)
   - [Accounts & Balances](#52-accounts--balances)
   - [Categories](#53-categories)
   - [Tags & Tag Groups](#54-tags--tag-groups)
   - [Transactions Ledger](#55-transactions-ledger)
   - [Receipts & Picture Attachments](#56-receipts--picture-attachments)
   - [Dashboard & Overview Aggregates](#57-dashboard--overview-aggregates)
   - [Analytics & Statistics](#58-analytics--statistics)
   - [Exchange Rates & System](#59-exchange-rates--system)
6. [Business Workflows](#6-business-workflows)
   - [Employee Paid Expenses & Reimbursements](#61-employee-paid-expenses--reimbursements)
   - [Balance Rollback & Reversals](#62-balance-rollback--reversals)
   - [Multi-Currency Financial Calculations](#63-multi-currency-financial-calculations)
7. [Local Development & Production Deployment](#7-local-development--production-deployment)
8. [Troubleshooting & FAQs](#8-troubleshooting--faqs)

---

## 1. System Architecture

```
[ User Browser / Mobile Client ]
              │
              ▼
    [ router.php / .htaccess ]
     ├── Static Assets (.css, .js, .png, .woff2) -> Direct file serve
     ├── Storage (/storage/transaction/{uid}/{file}) -> Image file stream
     ├── SPA Shell (/desktop, /mobile, /login, /) -> desktop.html / mobile.html
     └── REST API (/api/v1/*) -> api/index.php
                                     │
                                     ├── api/config.php (Database & Security Secret)
                                     ├── api/db.php (PDO MySQL Singleton)
                                     ├── api/auth.php (HMAC Token Middleware)
                                     ├── api/utils.php (Crypto, Snowflake IDs, JSON)
                                     │
                                     └── controllers/
                                          ├── AuthController.php
                                          ├── AccountController.php
                                          ├── CategoryController.php
                                          ├── TagController.php
                                          ├── TransactionController.php
                                          ├── PictureController.php
                                          ├── StatsController.php
                                          └── RatesController.php
                                                  │
                                                  ▼
                                       [ MySQL (ezbookkeeping) ]
```

### Key Architectural Tenets
* **Stateless REST**: Every API call is independently verified using HMAC SHA-256 signed bearer tokens matching active rows in `token_record`.
* **Zero External Dependencies**: Pure native PHP 8+ using PDO. No external vendor packages or Composer runtime dependencies required on the server.
* **Storage Isolation**: Receipts and proof images are stored per-user under `storage/transaction/{uid}/` to prevent collision and guarantee privacy.
* **Atomic Financial Integrity**: Transfer operations and transaction modifications execute with transactional balance rollback before new balances are applied.

---

## 2. Directory & File Structure

```
company system/
├── AxonitExpense/                   # Self-contained Deployable Application
│   ├── .htaccess                   # Root Apache rewrite rules (CORS, SPA fallback, API forwarding)
│   ├── index.html                  # Root landing redirect
│   ├── desktop.html                # Compiled Vue 3 Desktop Single Page Application
│   ├── mobile.html                 # Compiled Vue 3 Mobile Single Page Application
│   ├── server_settings.js          # Dynamic feature flags, map proxies, client capabilities
│   ├── router.php                  # PHP built-in web server router (local dev)
│   ├── start_local.bat             # One-click Windows runner for local dev server
│   ├── css/                        # Compiled Vuetify / application stylesheet bundles
│   ├── js/                         # Compiled Vue 3 runtime, Pinia stores, views
│   ├── img/, fonts/                # Static application icons and fonts
│   ├── storage/                    # User uploaded receipts, proofs, and avatars
│   │   └── transaction/
│   │       └── <uid>/              # Stored pictures: <picture_id>.<ext>
│   └── api/                        # Native PHP Backend REST API Engine
│       ├── .htaccess               # Forward all API calls to index.php
│       ├── index.php               # Central router & dispatcher table
│       ├── config.php              # Database credentials, salt, app secret
│       ├── db.php                  # PDO database singleton connection manager
│       ├── auth.php                # Token extraction and authentication middleware
│       ├── utils.php               # Snowflake ID generator, PBKDF2 crypto, IP helper
│       └── controllers/            # API business logic controllers
│           ├── AuthController.php        # Register, login, profile, user tokens
│           ├── AccountController.php     # Accounts, balances, hide/reorder/delete
│           ├── CategoryController.php    # Income/Expense categories & subcategories
│           ├── TagController.php         # Tags & tag groups management
│           ├── TransactionController.php # Add, edit, delete, month list, transfers
│           ├── PictureController.php     # Picture uploads & orphaned proof cleanup
│           ├── StatsController.php       # Dashboard sums, Categorical/Trend/Asset charts
│           └── RatesController.php       # Live rates & user custom currency rates
│
├── ezbookkeeping/                  # Vue 3 TypeScript Frontend Source Code
│   ├── src/
│   │   ├── views/                  # Desktop & mobile page components
│   │   ├── stores/                 # Pinia state stores (overview, transaction, statistics)
│   │   ├── models/                 # TypeScript interfaces and data contracts
│   │   ├── locales/                # Internationalization strings (en, zh, etc.)
│   │   └── lib/                    # HTTP client (Axios), date/time, math utilities
│   └── package.json
└── README.md                       # Master System Documentation
```

---

## 3. Database Architecture & Data Models

The system connects to a MySQL database (typically named `ezbookkeeping`) via UTF-8 MB4 charset.

### 3.1 Primary Tables & Schema

| Table Name | Description | Key Columns |
| :--- | :--- | :--- |
| `user` | User profiles & authentication credentials | `uid`, `username`, `email`, `password_hash`, `salt`, `default_currency`, `first_day_of_week` |
| `token_record` | Active user sessions & bearer token tracking | `uid`, `user_token_id`, `secret`, `user_agent`, `created_unix_time` |
| `account` | Financial accounts (Bank, Cash, Wallets, Claims) | `account_id`, `uid`, `name`, `category`, `type`, `currency`, `balance`, `deleted` |
| `transaction_category` | Categorical tree for income and expense classification | `category_id`, `uid`, `name`, `type`, `parent_id`, `icon`, `color`, `deleted` |
| `transaction` | Master financial ledger entries | `transaction_id`, `uid`, `type`, `category_id`, `account_id`, `amount`, `related_id`, `related_account_id`, `related_account_amount`, `transaction_time`, `comment`, `payee_name`, `currency`, `deleted` |
| `transaction_tag` | Transaction tags | `tag_id`, `uid`, `name`, `group_id`, `deleted` |
| `transaction_tag_group` | Categorical groupings for tags | `tag_group_id`, `uid`, `name`, `deleted` |
| `transaction_tag_index` | Many-to-many relationship mapping transactions to tags | `transaction_id`, `tag_id`, `transaction_time`, `uid` |
| `transaction_picture` | Uploaded receipt proof photo metadata | `picture_id`, `uid`, `original_file_name`, `format`, `created_unix_time` |
| `transaction_picture_index` | Many-to-many link between transactions and pictures | `transaction_id`, `picture_id`, `uid` |
| `exchange_rates` | Currency conversion rates | `source_currency`, `target_currency`, `rate`, `updated_unix_time` |

### 3.2 Data Conventions & Number Formats
* **Monetary Amounts**: Currency amounts are stored as **64-bit integers in cents/paisa** to prevent floating point inaccuracies. For example, `₹1,250.50` is stored as `125050`. The frontend formats this according to the account currency decimal places.
* **Timestamps**:
  * `transaction_time`: Stored in **milliseconds** (Unix epoch seconds $\times 1000$).
  * `created_unix_time`, `updated_unix_time`: Stored in **seconds** (Unix epoch seconds).
* **Snowflake Unique Identifiers**: All primary keys (`uid`, `transaction_id`, `account_id`, `picture_id`) are generated as 64-bit integer values represented as strings in JSON to avoid JavaScript 53-bit integer overflow.
* **Transaction Types (`type`)**:
  * `1` = Modify Balance (`TRANSACTION_TYPE_MODIFY_BALANCE`)
  * `2` = Income (`TRANSACTION_TYPE_INCOME`)
  * `3` = Expense (`TRANSACTION_TYPE_EXPENSE`)
  * `4` = Transfer Out (`TRANSACTION_TYPE_TRANSFER_OUT`)
  * `5` = Transfer In (`TRANSACTION_TYPE_TRANSFER_IN`)

---

## 4. Security, Authentication & Password Hashing

### 4.1 PBKDF2-SHA256 Password Hashing
The backend matches ezBookkeeping's Go implementation:
* **Algorithm**: PBKDF2 (`hash_pbkdf2`)
* **Digest**: SHA-256
* **Iterations**: 10,000
* **Output Length**: 48 bytes (raw binary)
* **Storage**: Base64 encoded, trailing `=` padding stripped.

```php
$salt = Utils::generateRandomString(16);
$hash = hash_pbkdf2('sha256', $password, $salt, 10000, 48, true);
$passwordHash = rtrim(base64_encode($hash), '=');
```

### 4.2 Bearer Token Authentication
On login, a session record is inserted into `token_record`. A cryptographically signed token string is returned:
* **Format**: `ebk-<Base64URL(uid:userTokenId:secret:createdUnixTime:HMAC_SHA256)>`
* **Signing Secret**: Configured in `api/config.php` (`Config::$settings['security']['secret_key']`).
* **Header**: Sent on every authenticated request as:
  ```http
  Authorization: Bearer ebk-Mzg1ND...
  ```
* **Query Parameter**: For image previews and downloads in `<img>` tags where custom headers cannot be set:
  ```http
  GET /api/v1/storage/transaction/385430.../179527.jpg?token=ebk-Mzg1ND...
  ```

---

## 5. Complete REST API Reference

All requests accept and return JSON. Endpoints are rooted at `/api/v1/` and automatically accept optional `.json` extensions.

### 5.1 Authentication & User Profile

| Endpoint | Method | Description | Parameters / Body |
| :--- | :--- | :--- | :--- |
| `users/login.json` | `POST` | Authenticate user & issue token | `{"username": "...", "password": "..."}` |
| `users/register.json` | `POST` | Register new user account | `{"username": "...", "email": "...", "password": "...", "defaultCurrency": "INR"}` |
| `users/profile/get.json` | `GET` | Retrieve logged in user profile | None |
| `users/profile/update.json` | `POST` | Update user profile & preferences | `{"email": "...", "defaultCurrency": "INR", "firstDayOfWeek": 1}` |
| `users/tokens/list.json` | `GET` | List active sessions and devices | None |
| `users/tokens/revoke.json` | `POST` | Invalidate specific session | `{"tokenId": "..."}` |
| `users/tokens/revoke_all.json` | `POST` | Invalidate all sessions except current | None |

### 5.2 Accounts & Balances

| Endpoint | Method | Description | Parameters / Body |
| :--- | :--- | :--- | :--- |
| `accounts/list.json` | `GET` | List all accounts with balances | `visible_only` (optional boolean) |
| `accounts/get.json` | `GET` | Get account details by ID | `id` (query param) |
| `accounts/add.json` | `POST` | Create new account & opening balance | `{"name": "HDFC", "category": 2, "type": 1, "currency": "INR", "balance": 100000}` |
| `accounts/modify.json` | `POST` | Update account name/icon/color | `{"id": "...", "name": "...", "color": "..."}` |
| `accounts/hide.json` | `POST` | Toggle account visibility | `{"id": "...", "hidden": true}` |
| `accounts/move.json` | `POST` | Reorder accounts display index | `{"newDisplayOrders": [{"id": "...", "displayOrder": 1}]}` |
| `accounts/delete.json` | `POST` | Soft delete account | `{"id": "..."}` |

### 5.3 Categories

| Endpoint | Method | Description | Parameters / Body |
| :--- | :--- | :--- | :--- |
| `transaction/categories/list.json` | `GET` | List category hierarchy tree | None |
| `transaction/categories/get.json` | `GET` | Get single category | `id` (query param) |
| `transaction/categories/add.json` | `POST` | Add single category | `{"name": "Travel", "type": 2, "parentId": "0", "icon": "...", "color": "..."}` |
| `transaction/categories/add_batch.json` | `POST` | Batch import categories | `{"categories": [...]}` |
| `transaction/categories/modify.json` | `POST` | Update category details | `{"id": "...", "name": "...", "color": "..."}` |
| `transaction/categories/hide.json` | `POST` | Hide / unhide category | `{"id": "...", "hidden": true}` |
| `transaction/categories/move.json` | `POST` | Reorder category display | `{"newDisplayOrders": [...]}` |
| `transaction/categories/delete.json` | `POST` | Soft delete category | `{"id": "..."}` |

### 5.4 Tags & Tag Groups

| Endpoint | Method | Description | Parameters / Body |
| :--- | :--- | :--- | :--- |
| `transaction/tags/list.json` | `GET` | List all tags | None |
| `transaction/tags/get.json` | `GET` | Get tag by ID | `id` (query param) |
| `transaction/tags/add.json` | `POST` | Create a new tag | `{"name": "Client Dinner", "groupId": "..."}` |
| `transaction/tags/modify.json` | `POST` | Modify tag | `{"id": "...", "name": "..."}` |
| `transaction/tags/hide.json` | `POST` | Hide tag | `{"id": "...", "hidden": true}` |
| `transaction/tags/delete.json` | `POST` | Soft delete tag | `{"id": "..."}` |
| `transaction/tag_groups/list.json` | `GET` | List tag groups | None |

### 5.5 Transactions Ledger

| Endpoint | Method | Description | Key Details |
| :--- | :--- | :--- | :--- |
| `transactions/list.json` | `GET` | Filtered transaction list | Supports `max_time`, `min_time`, `type`, `category_ids`, `account_ids`, `tag_filter`, `keyword`, `page`, `count` |
| `transactions/list/all.json` | `GET` | Unpaginated complete transaction dump | Used for client caches |
| `transactions/list/by_month.json` | `GET` | Monthly ledger items | `year=2026&month=11` |
| `transactions/count.json` | `GET` | Count transactions matching criteria | Returns `{ "totalCount": N }` |
| `transactions/get.json` | `GET` | Retrieve single transaction | Returns full tags, pictures array, counterpart transfer details |
| `transactions/add.json` | `POST` | Create new transaction | Updates account balances, handles tags in `transaction_tag_index`, links picture receipts, creates twin counterpart for transfers |
| `transactions/modify.json` | `POST` | Modify existing transaction | Reverses old balance impact, updates record & tags, updates counterpart transfer, applies new balance impact |
| `transactions/delete.json` | `POST` | Soft delete transaction | Reverses balance impact and deletes transfer counterpart |

### 5.6 Receipts & Picture Attachments

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `transaction/pictures/upload.json` | `POST` | Upload receipt / invoice / payment proof (`multipart/form-data`). Accepts `picture` or `file` field. Stores file to `storage/transaction/{uid}/{picture_id}.{ext}`. Returns picture ID. |
| `/storage/transaction/{uid}/{file}` | `GET` | Direct public/authorized serving of uploaded images. |
| `transaction/pictures/remove_unused.json` | `POST` | Cleans up uploaded pictures that were never saved to a transaction. |

### 5.7 Dashboard & Overview Aggregates

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `transactions/amounts.json` | `GET` | Aggregates income and expense sums for period slices. Receives `query=today_<s>_<e>\|thisWeek_<s>_<e>\|thisMonth_<s>_<e>\|thisYear_<s>_<e>\|lastMonth_...` and returns keyed object with multi-currency sums. |
| `transactions/amounts/daily.json` | `GET` | Returns daily breakdown of income and expense grouped by date and currency for activity heatmap and daily charts. |

### 5.8 Analytics & Statistics

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `transactions/statistics.json` | `GET` | **Categorical Analysis**: Returns category and account totals for income, expense, and transfers with `amount`, `categoryId`, `accountId`, `relatedAccountId`, and `relatedAccountType`. |
| `transactions/statistics/trends.json` | `GET` | **Trend Analysis**: Accepts `start_year_month` and `end_year_month` (e.g. `202610` to `202611`). Returns 12-month array of `{ year, month, items: [...] }`. |
| `transactions/statistics/asset_trends.json` | `GET` | **Asset Trends**: Accepts `start_time` and `end_time`. Computes running daily opening and closing balances for all user accounts across the selected window. |

### 5.9 Exchange Rates & System

| Endpoint | Method | Description |
| :--- | :--- | :--- |
| `exchange_rates/latest.json` | `GET` | Current currency exchange rates table |
| `exchange_rates/user_custom/update.json` | `POST` | Update user custom conversion rates |
| `data/statistics.json` | `GET` | Total system counts (accounts, transactions, categories, tags) |
| `system/version.json` | `GET` | Returns API version and build metadata |

---

## 6. Business Workflows

### 6.1 Employee Paid Expenses & Reimbursements
In corporate workflows where employees spend personal funds and file for company reimbursement:
1. **Creation**:
   - The transaction is recorded as an **Expense** (Type `3`).
   - Source Account is set to the employee's claims clearing account (e.g., `Employee Claims`) or company bank account.
   - Payee name is recorded as the employee (e.g., `Akshay`).
   - Receipt / invoice photo is uploaded via `transaction/pictures/upload.json` and attached.
   - Comment metadata is recorded (e.g. `[ReimbursedDate: 2026-11-18] [Location: Pune]`).
2. **Settlement / Reimbursement Execution**:
   - When the claim is settled or modified in the Edit Dialog, `transactions/modify.json` is called.
   - The system automatically rolls back the previous balance effect on the claims account, applies the new expense amounts, updates tag relationships, and records payment proof attachments.

### 6.2 Balance Rollback & Reversals
When modifying or deleting any existing transaction, `TransactionController.php` executes strict double-entry reversals:
* **Income (Type 2)**: Old amount is deducted from account balance (`balance - old_amount`); new amount is added (`balance + new_amount`).
* **Expense (Type 3)**: Old amount is credited back (`balance + old_amount`); new amount is deducted (`balance - new_amount`).
* **Transfers (Type 4 & 5)**: Source account is credited back (`balance + old_source_amount`), destination account is debited back (`balance - old_dest_amount`), then new amounts are applied to both sides atomically.

### 6.3 Multi-Currency Financial Calculations
Transactions retain their native transaction currency (`currency`) alongside the account currency (`account.currency`). If a transaction is logged in `HKD` against an `INR` account, both the dashboard (`transactions/amounts.json`) and the statistics controllers group amounts per currency code so the Vue frontend can convert using user-defined exchange rates.

---

## 7. Local Development & Production Deployment

### 7.1 Local Development (Windows / XAMPP)

1. **Start Apache & MySQL** in XAMPP Control Panel.
2. Ensure database `ezbookkeeping` exists and MySQL is running on `127.0.0.1:3306`.
3. Open terminal in the project directory:
   ```powershell
   cd "c:\Users\Admin\Desktop\company system\AxonitExpense"
   ```
4. Start the local PHP development server:
   ```powershell
   C:\xampp\php\php.exe -S 127.0.0.1:8000 -t . router.php
   ```
   *(Alternatively, run `start_local.bat`)*
5. Open your browser and navigate to:
   ```
   http://127.0.0.1:8000/desktop
   ```
   *Login with your credentials (e.g., username `Akshay`).*

### 7.2 Production Shared Hosting Deployment (Apache / cPanel / StackCP)

1. Upload the entire contents of `AxonitExpense/` to your web root (`public_html/` or subdomain root).
2. Create your MySQL database via cPanel and import your database schema.
3. Edit `api/config.php` and set your production database credentials:
   ```php
   'database' => [
       'host'     => 'localhost',
       'port'     => 3306,
       'dbname'   => 'your_cpanel_dbname',
       'username' => 'your_cpanel_dbuser',
       'password' => 'your_db_password',
       'charset'  => 'utf8mb4'
   ],
   'security' => [
       'secret_key' => 'generate-a-strong-random-32-byte-secret-here'
   ]
   ```
4. Ensure the `storage/` directory has write permissions:
   ```bash
   chmod -R 775 storage/
   ```
5. Ensure `mod_rewrite` is enabled. The included `.htaccess` handles API forwarding and Vue SPA routing automatically.

---

## 8. Troubleshooting & FAQs

### Q: Why did receipt upload fail with "Unable to upload transaction picture"?
* **Cause**: Some Vue client components send the multipart file under field `picture` while other import tools send it under `file`.
* **Fix**: Handled in `PictureController.php` via `$_FILES['picture'] ?? $_FILES['file']`. Also ensure `upload_max_filesize` and `post_max_size` in `php.ini` are at least `10M`.

### Q: Why did MySQL throw "Field 'transaction_time' doesn't have a default value"?
* **Cause**: MySQL strict mode requires `transaction_time` in `transaction_tag_index` for every inserted tag relationship.
* **Fix**: Handled in `TransactionController::addTransaction()` and `modifyTransaction()` where `:tx_time` is bound to all tag index inserts.

### Q: Why were Dashboard Month/Today/Trends showing "No data" or "-"?
* **Cause**: The frontend sends query string segments separated by underscores (`today_1795..._1795...|thisMonth_...`). If backend code splits on colons (`:`), segment keys become unrecognized strings rather than `today` or `thisMonth`.
* **Fix**: Handled in `StatsController::transactionAmounts()` by checking `str_contains($part, '_') ? explode('_', $part) : explode(':', $part)`.

### Q: Why were Categorical Analysis pie charts not rendering?
* **Cause**: The Vue 3 frontend model `TransactionStatisticResponseItem` expects the numeric string in property `amount`. Outputting `totalAmount` causes `BigDecimal(item.amount)` to evaluate to `NaN`.
* **Fix**: Handled in `StatsController::transactionStatistics()` by mapping output to `'amount' => (string)$row['total_amount']`.

---

## License & Credits
* **Engine Architecture**: Axonite Team
* **Frontend UI**: Built upon ezBookkeeping (MIT License)
* **Backend Engine**: Native PHP 8+ REST Engine (Proprietary / AxonitExpense)
