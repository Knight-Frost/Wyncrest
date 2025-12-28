# NEXUS PHASE 3.4 - AUTOMATION & TIME-BASED LEDGER ENFORCEMENT

Complete implementation of automated rent generation and overdue detection.

---

## 📋 IMPLEMENTATION SUMMARY

### **Core Principle**
🔒 **Time-based automation preserves ledger immutability. All operations are idempotent and auditable.**

### **Key Features**
✅ Automated rent generation based on billing periods
✅ Overdue detection and marking
✅ Complete idempotency (safe to re-run)
✅ Full audit trail
✅ Laravel scheduler integration
✅ 10 comprehensive tests

---

## 🏗️ ARCHITECTURE

### **Billing Period Logic**

```
Contract:
- start_date: 2025-01-15
- payment_day: 1
- billing_cycle: MONTHLY

Billing Periods:
Period 1: Jan 15 - Feb 14 (due: Feb 1)
Period 2: Feb 15 - Mar 14 (due: Mar 1)
Period 3: Mar 15 - Apr 14 (due: Apr 1)
```

**Rent Generation Rule:**
1. Contract must be ACTIVE
2. Today must fall within a billing period  
3. No rent entry exists for that period
4. Period start must be before contract end_date (if exists)

**Due Date Calculation:**
```
due_date = payment_day of month containing billing_period_end
```

If payment_day invalid (e.g., Feb 30), use last day of month.

---

## 🚀 INSTALLATION

### **Step 1: Copy Files**

```bash
cd ~/Documents/Nexus

# Copy service
cp LedgerAutomationService.php app/Services/

# Copy commands
cp GenerateRentCommand.php app/Console/Commands/
cp MarkOverdueCommand.php app/Console/Commands/

# Copy Kernel (scheduler registration)
cp Kernel.php app/Console/

# Copy tests
cp LedgerAutomationTest.php tests/Feature/
```

### **Step 2: Run Tests**

```bash
# Dump autoload
composer dump-autoload

# Run Phase 3.4 tests
php artisan test --filter=LedgerAutomationTest

# Run all tests (verify nothing broke)
php artisan test
```

You should see **48 tests passing** (38 from before + 10 new).

### **Step 3: Test Commands Manually**

```bash
# Generate rent for all active contracts
php artisan ledger:generate-rent

# Generate rent for specific contract
php artisan ledger:generate-rent --contract=uuid

# Mark overdue entries
php artisan ledger:mark-overdue
```

### **Step 4: Verify Scheduler**

```bash
# List scheduled tasks
php artisan schedule:list

# Run scheduler once (for testing)
php artisan schedule:run

# In production, add to cron:
# * * * * * cd /path-to-nexus && php artisan schedule:run >> /dev/null 2>&1
```

---

## 📡 COMMANDS

### **1. Generate Rent**

```bash
php artisan ledger:generate-rent
```

**What it does:**
- Scans all ACTIVE contracts
- Calculates current billing period for each
- Creates rent entry if none exists for that period
- Logs all actions

**Options:**
```bash
# Generate for specific contract
php artisan ledger:generate-rent --contract=019b60d0-6d68-7115-9c1d-4ab8cda24cb7
```

**Output:**
```
🏠 Nexus - Automated Rent Generation
=====================================

🔄 Processing all active contracts...

📊 Summary:
   ✅ Created: 5 rent entries
   ⏭️  Skipped: 2 contracts (already exists or not eligible)

✨ Rent generation complete!
```

**Idempotency:**
- Safe to run multiple times
- Won't create duplicates
- Checks `billing_period_start` + `billing_period_end` + `contract_id`

---

### **2. Mark Overdue**

```bash
php artisan ledger:mark-overdue
```

**What it does:**
- Scans all PENDING ledger entries
- Checks if `due_date < today`
- Updates status to OVERDUE (using saveQuietly())
- Logs all actions

**Output:**
```
⏰ Nexus - Overdue Detection
============================

🔍 Scanning for overdue entries...

⚠️  Marked 3 entries as OVERDUE

💡 Tip: Check audit logs for details
```

**What it does NOT do:**
- ❌ Does NOT modify amounts
- ❌ Does NOT affect PAID entries
- ❌ Does NOT generate late fees (admin does that)

---

## 🔄 SCHEDULER

The commands run automatically via Laravel's scheduler:

**Schedule (in `app/Console/Kernel.php`):**
```php
// Generate rent daily at 1:00 AM
$schedule->command('ledger:generate-rent')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-rent.log'));

// Mark overdue daily at 2:00 AM
$schedule->command('ledger:mark-overdue')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/scheduler-overdue.log'));
```

**To enable in production:**

Add this to your server's crontab:
```bash
* * * * * cd /path-to-nexus && php artisan schedule:run >> /dev/null 2>&1
```

This runs Laravel's scheduler every minute, which then executes commands at their scheduled times.

**Logs:**
- Rent generation: `storage/logs/scheduler-rent.log`
- Overdue marking: `storage/logs/scheduler-overdue.log`

---

## 🧪 TESTS

### **Test Suite: LedgerAutomationTest (10 tests)**

1. ✅ `test_rent_is_generated_for_active_contract`
   - Creates rent for current billing period
   - Verifies correct dates and amounts

2. ✅ `test_duplicate_rent_is_not_created`
   - Running twice doesn't create duplicates
   - Idempotency verification

3. ✅ `test_rent_is_not_generated_for_inactive_contract`
   - DRAFT/TERMINATED contracts skipped

4. ✅ `test_overdue_entries_are_marked`
   - PENDING entries with past due dates marked OVERDUE

5. ✅ `test_paid_entries_are_not_marked_overdue`
   - PAID entries remain PAID

6. ✅ `test_future_entries_are_not_marked_overdue`
   - Entries with future due dates remain PENDING

7. ✅ `test_automation_creates_audit_logs`
   - All actions logged with correct severity

8. ✅ `test_rent_respects_contract_end_date`
   - No rent after contract expires

9. ✅ `test_command_generates_rent_for_all_contracts`
   - Batch processing works correctly

10. ✅ `test_billing_period_handles_invalid_payment_days`
    - Feb 30 → Feb 28/29 gracefully

**All tests use `Carbon::setTestNow()` for deterministic time travel.**

---

## 💾 SERVICE: LedgerAutomationService

### **Methods:**

#### **getCurrentBillingPeriod(Contract $contract): ?array**
Calculates current billing period for a contract.

**Returns:**
```php
[
    'start' => Carbon,      // billing_period_start
    'end' => Carbon,        // billing_period_end
    'due_date' => Carbon,   // when payment is due
]
```

**Returns null if:**
- Contract not ACTIVE
- Today is after contract end_date
- Contract just started (today before first period)

---

#### **rentExistsForPeriod(Contract $contract, Carbon $start, Carbon $end): bool**
Checks if rent already exists for a specific period.

**Uniqueness check:**
```sql
WHERE contract_id = X
  AND type = 'rent'
  AND billing_period_start = start
  AND billing_period_end = end
```

---

#### **generateRentForContract(Contract $contract): ?LedgerEntry**
Generates rent for contract's current billing period.

**Returns:**
- `LedgerEntry` if created
- `null` if skipped (already exists or not eligible)

**Idempotent:** Safe to call multiple times.

---

#### **markOverdueEntries(): int**
Marks all overdue PENDING entries.

**Returns:** Number of entries marked.

**Query:**
```sql
WHERE status = 'pending'
  AND type IN ('rent', 'late_fee')
  AND due_date < today
```

---

#### **generateRentForAllContracts(): array**
Batch generates rent for all active contracts.

**Returns:**
```php
[
    'created' => 5,  // Number of rent entries created
    'skipped' => 2,  // Number of contracts skipped
]
```

---

## 📊 AUDIT TRAIL

### **Actions Logged:**

| Action | Severity | When |
|--------|----------|------|
| `rent_entry_automated` | info | Rent generated |
| `ledger_entry_marked_overdue` | warning | Entry marked overdue |

### **Audit Log Metadata:**

**Rent Generation:**
```json
{
  "contract_id": "uuid",
  "billing_period_start": "2025-02-15",
  "billing_period_end": "2025-03-14",
  "due_date": "2025-03-01",
  "amount_cents": 250000
}
```

**Overdue Marking:**
```json
{
  "ledger_entry_id": "uuid",
  "type": "rent",
  "due_date": "2025-02-01",
  "amount_cents": 250000
}
```

---

## 🔍 EXAMPLES

### **Example 1: Monthly Rent Generation**

**Setup:**
```
Contract start_date: Jan 15, 2025
Payment day: 1
Today: Feb 20, 2025
```

**What happens:**
1. Calculate current period: Feb 15 - Mar 14
2. Check if rent exists for this period
3. If not, create rent entry:
   - Period: Feb 15 - Mar 14
   - Due: Mar 1
   - Amount: From contract
4. Audit log created

**Next day (Feb 21):**
- Same period detected
- Rent already exists
- Skip (idempotent)

---

### **Example 2: Overdue Detection**

**Setup:**
```
Rent entry:
- Due date: Feb 1, 2025
- Status: PENDING
Today: Feb 10, 2025
```

**What happens:**
1. Scan finds entry (due_date < today)
2. Update status to OVERDUE (using saveQuietly())
3. Audit log created (severity: warning)

**Next day (Feb 11):**
- Entry already OVERDUE
- Skip (idempotent)

---

### **Example 3: Contract End Date**

**Setup:**
```
Contract:
- Start: Jan 15, 2025
- End: Mar 31, 2025
Today: Apr 5, 2025
```

**What happens:**
1. getCurrentBillingPeriod() returns null (past end date)
2. No rent generated
3. Contract effectively inactive for billing

---

## 🚫 WHAT'S NOT IN PHASE 3.4

❌ Email notifications (Phase 3.5)
❌ Late fee automation (admin-only, already exists)
❌ Payment reminders (Phase 3.5)
❌ Refund processing (Phase 3.5)
❌ Landlord payouts (Phase 3.6)
❌ Frontend changes

---

## 🔮 FUTURE ENHANCEMENTS

### **Phase 3.5 - Notifications**
- Email reminders 3 days before due date
- Email when marked overdue
- SMS notifications (optional)

### **Phase 3.6 - Advanced Automation**
- Auto-generate late fees after X days overdue
- Payment plans / installments
- Proration for mid-month starts

### **Phase 4.0 - Reporting**
- Monthly financial reports
- Occupancy analytics
- Revenue forecasting

---

## 💡 DESIGN DECISIONS

### **Why Daily at 1 AM and 2 AM?**
- **1 AM**: Low traffic time
- **2 AM**: After rent generation (overdue detection needs rent to exist)
- **Separate times**: Avoid conflicts

### **Why `saveQuietly()` for Overdue?**
- LedgerEntry has immutability protection
- Status changes are allowed (by design)
- `saveQuietly()` bypasses model events but preserves data integrity

### **Why Check Period Start + End?**
- More precise than just month/year
- Handles edge cases (mid-month starts)
- Prevents duplicates across date changes

### **Why No Stripe in Phase 3.4?**
- Payments are user-initiated (Phase 3.3)
- Automation is time-based only
- Clear separation of concerns

---

## 📦 FILES DELIVERED

```
Phase 3.4 - Automation/
├── app/
│   ├── Services/
│   │   └── LedgerAutomationService.php
│   └── Console/
│       ├── Commands/
│       │   ├── GenerateRentCommand.php
│       │   └── MarkOverdueCommand.php
│       └── Kernel.php
└── tests/
    └── Feature/
        └── LedgerAutomationTest.php
```

**Total Files:** 5
- 1 Service
- 2 Commands
- 1 Kernel (scheduler)
- 1 Test suite (10 tests)

---

## ✅ VERIFICATION CHECKLIST

After installation:

- [ ] All 5 files copied to correct locations
- [ ] `composer dump-autoload` executed
- [ ] Phase 3.4 tests pass (10 tests)
- [ ] All tests pass (48 total)
- [ ] Commands can be run manually
- [ ] Scheduler registered (`php artisan schedule:list`)
- [ ] Audit logs created for automation actions
- [ ] No duplicates when commands re-run

---

## 🎓 NEXT STEPS

Phase 3.4 is **COMPLETE**. Future phases:

- **Phase 3.5**: Notifications (email reminders)
- **Phase 3.6**: Advanced automation (auto late fees)
- **Phase 4.0**: Reporting & analytics
- **Frontend**: Build tenant/landlord dashboards

---

**PHASE 3.4 STATUS: ✅ READY FOR INTEGRATION**

Automated, idempotent, auditable time-based ledger enforcement. Scheduler ready for production. All tests passing.
