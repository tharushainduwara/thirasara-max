# Thirasara Max Mobile — Business Automation System

A web-based e-commerce, repair tracking, and warranty management system built for **Thirasara Max Mobile**, a mobile accessories retail and repair shop located in Wadduwa, Sri Lanka.

---

## Developer

**K.D.T Iduwara**
Registration No: SEU/IS/21/MIT/007
Department of Management and Information Technology
Faculty of Management and Commerce
South Eastern University of Sri Lanka

---

## About the Project

This system handles the complete day-to-day operations of the shop digitally — customers can browse and purchase products online, book device repairs, track repair status using a code, and verify warranty coverage. Staff members manage assigned repairs and update statuses. The admin has full control over products, inventory, orders, users, warranties, reports, and customer inquiries.

---

## Functional Modules

**Module 1.0 — Authentication & User Management**
Registration, login (email + Google OAuth), profile management, and role-based access control for Customer, Staff, and Admin roles.

**Module 2.0 — Product & Inventory Management**
Product catalog with full CRUD operations, repairable product flag, real-time stock levels, and automated low-stock threshold alerts.

**Module 3.0 — Order & Payment Management**
Shopping cart, checkout with PayHere (Sri Lanka gateway) or Cash on Delivery, order confirmation, and transaction history.

**Module 4.0 — Repair Management**
Online repair booking with image uploads, technician assignment, parts tracking, and live status updates (Pending → In Progress → Ready for Collection → Completed).

**Module 5.0 — Warranty Management**
Automatic warranty generation for product sales and completed repairs, digital warranty code verification, status monitoring, and claim handling.

**Module 6.0 — Invoice & Report Generation**
PDF invoices and receipts generated automatically using dompdf. Monthly business reports covering sales, repairs, and inventory.

**Module 7.0 — Notification Management**
Automated email notifications via PHPMailer for order confirmations, repair status changes, warranty expiration reminders, and inquiry replies.

**Module 8.0 — Inquiry & Contact Management**
Customer inquiry submission through the contact form, admin-side reply system with email notification to the customer, status tracking (Pending / Resolved), and in-app notifications for registered users.

---

## Tech Stack

- **Frontend:** HTML5, Vanilla JavaScript (AJAX / Fetch API), Tailwind CSS, Lucide Icons
- **Backend:** PHP 8.0+ (Layered Modular Architecture)
- **Database:** MySQL / MariaDB 10.4+ (PDO)
- **PDF Generation:** dompdf/dompdf
- **Email:** PHPMailer (SMTP via Gmail)
- **Auth:** PHP Sessions + Google OAuth 2.0
- **Payments:** PayHere (Sri Lanka) / Cash on Delivery
- **Environment Config:** vlucas/phpdotenv
- **Server:** XAMPP (Apache + MySQL)

---

## Project Structure

```
thirasara-max/
│
├── admin/
│   ├── dashboard.php
│   ├── notifications.php
│   ├── inquiries/           — Customer inquiry management & replies
│   ├── inventory/           — Stock levels & low-stock alerts
│   ├── orders/              — Order list & detailed view
│   ├── products/            — Add, edit, list products
│   ├── repairs/             — Add, view, manage repairs
│   ├── reports/             — Sales, repairs, inventory reports
│   ├── users/               — Add/edit customers & staff
│   └── warranties/          — Add & manage warranties
│
├── api/
│   ├── cart_actions.php      — Cart add/update/remove (AJAX)
│   ├── check_warranty.php    — Warranty code verification
│   ├── get_repair_status.php — Repair tracking lookup
│   └── stock_check.php       — Product stock availability
│
├── assets/
│   ├── css/
│   ├── images/
│   ├── js/
│   └── uploads/             — Product images, repair photos, invoices, payment slips
│
├── config/
│   ├── config.php            — App constants & settings
│   └── database.php          — PDO connection (Singleton)
│
├── customer/                 — Customer account pages
│
├── database/
│   ├── schema.sql            — Clean database schema
│   └── thirasara_max_db.sql  — Full dump with sample data
│
├── includes/
│   ├── auth.php              — Authentication & RBAC
│   ├── functions.php         — Utility & helper functions
│   ├── header.php
│   └── footer.php
│
├── payments/                 — PayHere callback handlers
│
├── staff/
│   ├── dashboard.php
│   ├── profile.php
│   ├── repairs.php
│   ├── repair-detail.php
│   └── warranties.php
│
├── vendor/                   — Composer dependencies
├── .env                      — Environment config
├── composer.json
├── index.php                 — Homepage
├── login.php
├── register.php
├── products.php              — Product catalog
├── product-details.php
├── cart.php
├── checkout.php
├── order-confirmation.php
├── repair-booking.php
├── repair-track.php
├── warranty-check.php
├── contact.php               — Inquiry / contact form
├── about.php
├── privacy.php
└── terms.php
```

---

## Default User Accounts

The database comes with the following test accounts pre-loaded. Passwords are bcrypt-hashed in the database.

### Admin

| | |
|---|---|
| Name | Tharusha Iduwara |
| Email | `tharushaiduwara1234@gmail.com` |
| Password | `AdminPass123` |
| Role | admin |
| Access | Full system — dashboard, products, orders, inventory, users, repairs, warranties, reports, inquiries, notifications |

### Staff

| | |
|---|---|
| Name | Nimal Perera |
| Email | `staff@thirasaramax.lk` |
| Password | `StaffPass123` |
| Role | staff |
| Access | Staff dashboard, assigned repairs, repair status updates, warranty management, profile |

### Customer

| | |
|---|---|
| Name | Tharusha Iduwara |
| Email | `tiduwara26@gmail.com` |
| Password | `Password123` |
| Role | customer |
| Access | Browse & buy products, cart, checkout, order history, repair booking, repair tracking, warranty check, inquiries, profile |

---

## Role-Based Access

| Feature | Admin | Staff | Customer |
|---|---|---|---|
| Dashboard | Admin panel | Staff panel | — |
| Products | Full CRUD | — | Browse & purchase |
| Orders | Manage all | — | Own orders only |
| Inventory | Full control | — | — |
| Repairs | Manage all | Assigned repairs | Book & track own |
| Warranties | Full CRUD | View & update | Check by code |
| Reports | Generate all | — | — |
| Users | Add/edit/view | — | — |
| Inquiries | Reply & manage | — | Submit |
| Notifications | View all | — | — |

---

## Database Tables

| Table | Purpose |
|---|---|
| users | Accounts with roles (customer, staff, admin) |
| products | Product catalog — name, category, brand, price, images |
| inventory | Stock quantities and low-stock thresholds |
| inventory_logs | Stock change audit trail |
| orders | Customer orders with payment and delivery status |
| order_items | Individual items within each order |
| repairs | Repair bookings with status and technician assignment |
| repair_parts | Parts and labour lines per repair |
| warranties | Digital warranty records for products and repairs |
| invoices | Generated PDF invoice/receipt references |
| inquiries | Customer messages from the contact form |
| notifications | In-app notification records |

---

## API Endpoints

| Endpoint | Method | Purpose |
|---|---|---|
| /api/cart_actions.php | POST | Add, update, or remove cart items |
| /api/check_warranty.php | GET/POST | Verify warranty status by code |
| /api/get_repair_status.php | GET | Look up repair status by tracking code |
| /api/stock_check.php | GET | Check product stock in real time |

---

## Contact

K.D.T Iduwara
Email: tharushaiduwara1234@gmail.com
South Eastern University of Sri Lanka
