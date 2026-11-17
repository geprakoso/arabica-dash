# Arabica ERP — Technical Architecture Documentation

> **Scope:** This document details the architectural decisions, data models, and system design patterns implemented in the Arabica ERP & POS system. It is intended for technical reviewers, potential collaborators, and hiring managers evaluating the depth of engineering work.

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Application Layers](#2-application-layers)
3. [Database Design](#3-database-design)
4. [Inventory Engine](#4-inventory-engine)
5. [Document State Machine](#5-document-state-machine)
6. [WooCommerce Sync Pipeline](#6-woocommerce-sync-pipeline)
7. [Authentication & Authorization](#7-authentication--authorization)
8. [Background Processing](#8-background-processing)
9. [Infrastructure & Deployment](#9-infrastructure--deployment)
10. [Testing Strategy](#10-testing-strategy)

---

## 1. System Overview

Arabica is a **monolithic Laravel application** following a service-oriented internal architecture. The decision to use a monolith (rather than microservices) was intentional — the client context favors simplicity of deployment, low operational overhead, and ease of maintenance by a small team.

### Design Principles

| Principle | Application |
|---|---|
| **Single Source of Truth** | All inventory state lives in the database; no in-memory counters |
| **Audit Everything** | Every mutation to stock or financial data creates an immutable log entry |
| **Fail Safely** | Pessimistic locking and DB transactions prevent data corruption under concurrency |
| **Decouple Side Effects** | External API calls (WooCommerce, email, PDF) always run as background jobs |
| **Role-Based Access** | Every page, action, and filter is gated by granular Filament Shield policies |

---

## 2. Application Layers

```
┌─────────────────────────────────────────────────────────────────────────┐
│  PRESENTATION LAYER                                                     │
│  Filament v3 Resources  →  Livewire Components  →  Alpine.js           │
│  (admin panel pages, form schemas, table definitions, custom widgets)   │
└──────────────────────────────┬──────────────────────────────────────────┘
                               │ HTTP / Livewire wire calls
┌──────────────────────────────▼──────────────────────────────────────────┐
│  APPLICATION LAYER                                                      │
│  Laravel Controllers / Livewire Actions / Filament Page Actions         │
│  Form Requests (validation)  →  Service Classes (business logic)        │
└──────────────────────────────┬──────────────────────────────────────────┘
                               │ Eloquent ORM
┌──────────────────────────────▼──────────────────────────────────────────┐
│  DOMAIN / DATA LAYER                                                    │
│  Eloquent Models  →  Observers  →  Events  →  Jobs                     │
│  MySQL 8.x (primary)  |  Redis (cache, queues, sessions)                │
└─────────────────────────────────────────────────────────────────────────┘
```

### Key Directory Structure

```
app/
├── Filament/
│   ├── Resources/          # One directory per admin module
│   │   └── [Name]Resource/
│   │       ├── Pages/      # ListRecords, CreateRecord, EditRecord, ViewRecord
│   │       └── RelationManagers/
│   └── Widgets/            # Dashboard widgets (StatsOverview, Charts, etc.)
├── Models/                 # Eloquent models
├── Observers/              # Model lifecycle hooks → event dispatch
├── Events/                 # Domain events (StockChanged, SalePosted, etc.)
├── Listeners/              # Event handlers
├── Jobs/                   # Queued background jobs
├── Services/               # Stateless service classes for business logic
│   ├── StockService.php    # Inventory mutation logic
│   ├── InvoiceService.php  # Invoice generation
│   └── PayrollService.php  # Payroll computation
├── Policies/               # Filament Shield authorization policies
└── Providers/
    └── Filament/
        └── AdminPanelProvider.php  # Panel registration, navigation, theme
```

---

## 3. Database Design

### Core Entity Groups

```
SALES DOMAIN
  customers ─────────────────────────────────────────────┐
  sale_headers (penjualan)  ──┬─── sale_items             │
  pos_sales                  │      └── products ─────────┤
  tukar_tambah ──────────────┘      └── stock_batches      │
                                    └── serials             │
INVENTORY DOMAIN                                           │
  products ──────────────────────────────────────────────── 
      └── stock_batches ───── stock_mutations
      └── warehouses
      └── product_categories
      └── serials

PURCHASING DOMAIN
  suppliers
  purchase_headers (pembelian) ──── purchase_items
  rma_headers ──── rma_items

HR DOMAIN
  employees ─── attendance (absensi)
             ─── shifts (penjadwalan_tugas)
             ─── leaves (libur_cuti)
             ─── payroll (gaji_karyawan)

ACCOUNTING DOMAIN
  ledger_transactions (transaksi_toko)
  cash_drawers (pos_activity)

MARKETING DOMAIN
  content_posts (content_calendar)
```

### Key Table Designs

#### `stock_batches` — Inventory Source of Truth

```sql
CREATE TABLE stock_batches (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    product_id      BIGINT UNSIGNED NOT NULL,
    warehouse_id    BIGINT UNSIGNED NOT NULL,
    purchase_item_id BIGINT UNSIGNED NULL,      -- Origin purchase
    qty_received    INT NOT NULL DEFAULT 0,
    qty_available   INT NOT NULL DEFAULT 0,     -- Current sellable qty
    qty_reserved    INT NOT NULL DEFAULT 0,     -- Draft sale holds
    cost_price      DECIMAL(15,2) NOT NULL,     -- FIFO cost basis
    batch_date      DATE NOT NULL,
    created_at      TIMESTAMP,
    updated_at      TIMESTAMP,

    INDEX idx_product_warehouse (product_id, warehouse_id),
    CONSTRAINT fk_sb_product FOREIGN KEY (product_id) REFERENCES products(id)
);
```

#### `stock_mutations` — Immutable Audit Trail

```sql
CREATE TABLE stock_mutations (
    id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    stock_batch_id  BIGINT UNSIGNED NOT NULL,
    type            ENUM('sale','sale_draft','sale_void','purchase',
                         'return','adjustment','opname','transfer') NOT NULL,
    qty_change      INT NOT NULL,               -- Signed: negative = out
    qty_before      INT NOT NULL,               -- Snapshot at mutation time
    qty_after       INT NOT NULL,               -- Snapshot at mutation time
    reference_type  VARCHAR(100) NULL,          -- Polymorphic: 'App\Models\SaleHeader'
    reference_id    BIGINT UNSIGNED NULL,       -- Polymorphic: sale ID
    user_id         BIGINT UNSIGNED NOT NULL,   -- Who triggered it
    notes           TEXT NULL,
    created_at      TIMESTAMP NOT NULL,         -- Immutable — no updated_at

    INDEX idx_batch (stock_batch_id),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_created (created_at)
);
```

---

## 4. Inventory Engine

### StockService — Centralized Mutation Logic

All inventory changes route through `App\Services\StockService` to ensure consistency:

```php
// Simplified illustration of the core deduction flow
public function deductForSale(SaleItem $item, string $mutationType): void
{
    DB::transaction(function () use ($item, $mutationType) {

        // 1. Acquire pessimistic lock — blocks concurrent transactions
        $batch = StockBatch::where('product_id', $item->product_id)
            ->where('warehouse_id', $item->warehouse_id)
            ->where('qty_available', '>', 0)
            ->lockForUpdate()        // SELECT ... FOR UPDATE
            ->orderBy('batch_date') // FIFO: oldest batch first
            ->firstOrFail();

        // 2. Validate availability
        if ($batch->qty_available < $item->qty) {
            throw new InsufficientStockException($item->product_id);
        }

        $qtyBefore = $batch->qty_available;

        // 3. Mutate the batch
        $batch->decrement('qty_available', $item->qty);

        // 4. Write immutable audit record
        StockMutation::create([
            'stock_batch_id' => $batch->id,
            'type'           => $mutationType,
            'qty_change'     => -$item->qty,
            'qty_before'     => $qtyBefore,
            'qty_after'      => $qtyBefore - $item->qty,
            'reference_type' => SaleHeader::class,
            'reference_id'   => $item->sale_header_id,
            'user_id'        => auth()->id(),
        ]);

    }); // Transaction auto-commits or rolls back on exception
}
```

### FIFO Cost Averaging

When a sale is posted (finalized), the system computes COGS using **FIFO batch costing** — pulling the `cost_price` from the oldest batch consumed — rather than a simple average, giving more accurate profit margin data per transaction.

---

## 5. Document State Machine

Sales and purchase documents implement a formal state machine using a `status` enum column and `SaleHeader::canTransitionTo(string $targetStatus): bool` guard method.

### State Transition Rules

```
DRAFT ──────────────────────────────────────────────────────► DRAFT_SAVED
  │   • All fields editable                      • Items locked (no add/remove)
  │   • Stock: qty_reserved incremented          • Payment/discount still editable
  │   • Mutations: type = 'sale_draft'           • Mutations: type = 'sale_draft'
  │
  │ delete()
  ▼
DELETED
  • Reserved stock released
  • Mutations reversed (qty_reserved decremented)
  • Soft-deleted (kept for audit)

DRAFT_SAVED ────────────────────────────────────────────────► FINAL_POSTED
                                                  • Full document locked
                                                  • Mutations converted: draft → permanent
                                                  • Financial entry recorded
                                                        │
                                                        │ void() — one time only
                                                        ▼
                                                  DRAFT (re-opened)
                                                  • Permanent mutations reversed to draft
                                                  • Payment zeroed for correction
                                                  • Inventory NOT restored (already left store)
                                                        │
                                                        │ lock()
                                                        ▼
                                                  FINAL_LOCKED
                                                  • Immutable, no further transitions
```

### Implementation Pattern

```php
// app/Models/SaleHeader.php (simplified)
public function post(): void
{
    if (! $this->canTransitionTo('final_posted')) {
        throw new InvalidStateTransitionException($this->status, 'final_posted');
    }

    DB::transaction(function () {
        $this->update(['status' => 'final_posted', 'posted_at' => now()]);

        // Convert all draft mutations to permanent
        $this->stockMutations()
            ->where('type', 'sale_draft')
            ->update(['type' => 'sale']);

        // Fire domain event → triggers financial ledger entry
        SalePosted::dispatch($this);
    });
}
```

---

## 6. WooCommerce Sync Pipeline

### Architecture

```
┌──────────────────┐     Observer      ┌─────────────────────────┐
│  StockBatch      │──── triggers ────▶│  SyncStockToWooCommerce │
│  (updated event) │                   │  Job (queued, async)     │
└──────────────────┘                   └────────────┬────────────┘
                                                    │ HTTP PUT
                                                    ▼
                                       ┌─────────────────────────┐
                                       │  WooCommerce REST API   │
                                       │  /wp-json/wc/v3/        │
                                       │  products/{id}           │
                                       └────────────┬────────────┘
                                                    │
                                       ┌────────────▼────────────┐
                                       │  WooCommerceSyncLog     │
                                       │  (success/fail + ts)    │
                                       └─────────────────────────┘

  ┌─────────────────────────────────────────────────────┐
  │  Laravel Scheduler (every 5 minutes)                │
  │  php artisan sync:woocommerce                       │
  │                                                     │
  │  1. Fetch all WC products (paginated)               │
  │  2. Match by SKU → local product                    │
  │  3. Compare stock_quantity vs local qty_available   │
  │  4. PATCH discrepancies → log result                │
  └─────────────────────────────────────────────────────┘
```

### Failover Strategy

| Failure Type | Behavior |
|---|---|
| Network timeout | Retry with exponential backoff (30s → 60s → 120s) |
| API rate limit (429) | Retry after `Retry-After` header value |
| Authentication error | Log as `auth_failed`, alert admin, do not retry |
| Max retries exceeded | Mark `sync_log` as `failed`, surface in admin dashboard alert |
| Periodic reconciliation | Catches any jobs that permanently failed by comparing state |

---

## 7. Authentication & Authorization

### Authentication

- **Driver:** Laravel session-based auth (no API tokens for admin panel)
- **Login:** Email + password with configurable 2FA (TOTP) via Filament Shield
- **Attendance auth:** Separate browser geolocation check (client-side) before server submission

### Authorization (Filament Shield)

Uses **Spatie Laravel Permission** + Filament Shield for granular, role-based access control:

```
ROLES:
  super_admin       → Full access to all resources and settings
  admin             → Full operational access, no system settings
  kasir             → POS terminal, sales, cash drawer only
  gudang            → Inventory, purchase orders, RMA
  teknisi           → RMA repairs, service scheduling
  hr                → Attendance, payroll, leave management
  marketing         → Content calendar only

PERMISSION GRANULARITY (per resource):
  view_any_{resource}
  view_{resource}
  create_{resource}
  update_{resource}
  delete_{resource}
  restore_{resource}   (soft-delete recovery)
  force_delete_{resource}
```

Custom Filament actions (e.g., `post`, `void`, `lock`) have their own dedicated permission checks separate from the CRUD permissions.

---

## 8. Background Processing

### Job Taxonomy

| Job Class | Trigger | Queue | Priority |
|---|---|---|---|
| `SyncStockToWooCommerce` | StockBatch observer | `woocommerce` | Normal |
| `GenerateInvoicePdf` | Sale posted event | `pdf` | Normal |
| `SendPayrollSlipEmail` | Payroll processed | `email` | Low |
| `ReconcileWooCommerce` | Scheduler (5 min) | `default` | Normal |

### Queue Configuration

```
QUEUE_CONNECTION=redis

Queues defined (in priority order):
  1. high       → critical real-time operations
  2. woocommerce → external API sync
  3. pdf         → report/invoice generation
  4. email       → notifications
  5. default     → miscellaneous

Worker: php artisan queue:work --queue=high,woocommerce,pdf,email,default
```

---

## 9. Infrastructure & Deployment

### Docker Compose Stack

```yaml
services:
  app:       # PHP 8.2 FPM
  nginx:     # Reverse proxy + static asset serving
  mysql:     # MySQL 8 (persistent volume)
  redis:     # Cache + sessions + queues
  worker:    # Queue worker (same image as app)
  scheduler: # cron: * * * * * php artisan schedule:run
```

### Deployment Flow

```
Developer pushes to main branch
          │
          ▼
  GitHub Actions (CI)
  - php artisan test (Pest)
  - ./vendor/bin/pint --test
          │
          ▼
  SSH to VPS
  - git pull origin main
  - composer install --no-dev --optimize-autoloader
  - php artisan migrate --force
  - npm run build
  - docker-compose restart app worker
  - php artisan cache:clear
  - php artisan config:cache
  - php artisan route:cache
  - php artisan view:cache
```

---

## 10. Testing Strategy

### Test Structure

```
tests/
├── Unit/
│   ├── Services/
│   │   ├── StockServiceTest.php       # Core inventory mutation logic
│   │   └── PayrollServiceTest.php     # Salary calculation rules
│   └── Models/
│       └── SaleHeaderTest.php         # State machine transition guards
├── Feature/
│   ├── POS/
│   │   └── CashierCheckoutTest.php    # Full POS flow integration
│   ├── Inventory/
│   │   └── StockOpnameTest.php
│   └── Auth/
│       └── RoleAccessTest.php         # Permission boundary tests
└── Pest.php                           # Pest configuration
```

### Testing Principles

```php
// Example: Race condition test using parallel transactions
it('prevents overselling under concurrent sales', function () {
    $product = Product::factory()->create();
    $batch = StockBatch::factory()->for($product)->create(['qty_available' => 1]);

    // Simulate two concurrent sales attempting to buy the last unit
    $results = collect(range(1, 2))->map(fn() =>
        rescue(fn() => app(StockService::class)->deductForSale(
            SaleItem::factory()->make(['product_id' => $product->id, 'qty' => 1])
        ), false)
    );

    // Only one should succeed
    expect($results->filter()->count())->toBe(1);
    expect($batch->fresh()->qty_available)->toBe(0);
});
```

### Coverage Targets

| Area | Coverage Goal |
|---|---|
| StockService mutations | 95%+ |
| State machine transitions | 100% |
| Role/permission boundaries | All role × resource combinations |
| WooCommerce sync failover | All retry + failure paths |

---

*Last updated: June 2026 | Author: Galih*
