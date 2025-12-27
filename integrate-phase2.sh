#!/bin/bash

# Nexus Phase 2 Integration Script
# This script integrates Phase 2 files into your existing Nexus project

set -e  # Exit on any error

echo "================================================"
echo "  NEXUS PHASE 2 - INTEGRATION SCRIPT"
echo "================================================"
echo ""

# Color codes for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check if we're in a Laravel project
if [ ! -f "artisan" ]; then
    echo -e "${RED}ERROR: This script must be run from the root of your Laravel project${NC}"
    echo "Please cd into your Nexus project directory first"
    exit 1
fi

echo -e "${GREEN}✓ Detected Laravel project${NC}"
echo ""

# Backup existing files (if any exist)
echo "Step 1: Creating backup of existing files..."
BACKUP_DIR="backup-phase2-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Backup routes/api.php if it exists
if [ -f "routes/api.php" ]; then
    cp routes/api.php "$BACKUP_DIR/api.php.backup"
    echo -e "${YELLOW}  ⚠ Backed up existing routes/api.php${NC}"
fi

# Backup Kernel.php if it exists
if [ -f "app/Http/Kernel.php" ]; then
    cp app/Http/Kernel.php "$BACKUP_DIR/Kernel.php.backup"
    echo -e "${YELLOW}  ⚠ Backed up existing app/Http/Kernel.php${NC}"
fi

# Backup AuthServiceProvider.php if it exists
if [ -f "app/Providers/AuthServiceProvider.php" ]; then
    cp app/Providers/AuthServiceProvider.php "$BACKUP_DIR/AuthServiceProvider.php.backup"
    echo -e "${YELLOW}  ⚠ Backed up existing app/Providers/AuthServiceProvider.php${NC}"
fi

echo -e "${GREEN}✓ Backup created in: $BACKUP_DIR${NC}"
echo ""

# Create necessary directories
echo "Step 2: Creating directory structure..."
mkdir -p app/Http/Controllers/Public
mkdir -p app/Http/Controllers/Tenant
mkdir -p app/Http/Controllers/Landlord
mkdir -p app/Http/Controllers/Admin
mkdir -p app/Http/Middleware
mkdir -p app/Http/Requests
mkdir -p app/Policies
mkdir -p tests/Feature

echo -e "${GREEN}✓ Directories created${NC}"
echo ""

# Check if Phase 2 files are in the current directory
PHASE2_SOURCE="nexus-phase2"

if [ ! -d "$PHASE2_SOURCE" ]; then
    echo -e "${RED}ERROR: Phase 2 files not found${NC}"
    echo "Please extract nexus-phase2.tar.gz first:"
    echo "  tar -xzf nexus-phase2.tar.gz"
    exit 1
fi

# Copy Phase 2 files
echo "Step 3: Copying Phase 2 files..."

# Controllers
echo "  → Copying controllers..."
cp -r "$PHASE2_SOURCE/app/Http/Controllers/Public"/* app/Http/Controllers/Public/ 2>/dev/null || true
cp -r "$PHASE2_SOURCE/app/Http/Controllers/Tenant"/* app/Http/Controllers/Tenant/ 2>/dev/null || true
cp -r "$PHASE2_SOURCE/app/Http/Controllers/Landlord"/* app/Http/Controllers/Landlord/ 2>/dev/null || true
cp -r "$PHASE2_SOURCE/app/Http/Controllers/Admin"/* app/Http/Controllers/Admin/ 2>/dev/null || true

# Middleware
echo "  → Copying middleware..."
cp "$PHASE2_SOURCE/app/Http/Middleware/EnsureTenant.php" app/Http/Middleware/
cp "$PHASE2_SOURCE/app/Http/Middleware/EnsureLandlord.php" app/Http/Middleware/

# Form Requests
echo "  → Copying form requests..."
cp "$PHASE2_SOURCE/app/Http/Requests"/* app/Http/Requests/

# Policies
echo "  → Copying policies..."
cp "$PHASE2_SOURCE/app/Policies"/* app/Policies/

# Routes
echo "  → Copying routes..."
cp "$PHASE2_SOURCE/routes/api.php" routes/api.php

# Kernel and AuthServiceProvider
echo "  → Copying provider files..."
cp "$PHASE2_SOURCE/app/Http/Kernel.php" app/Http/Kernel.php
cp "$PHASE2_SOURCE/app/Providers/AuthServiceProvider.php" app/Providers/AuthServiceProvider.php

# Tests
echo "  → Copying tests..."
cp "$PHASE2_SOURCE/tests/Feature"/* tests/Feature/ 2>/dev/null || true

echo -e "${GREEN}✓ All files copied${NC}"
echo ""

# Update composer autoload
echo "Step 4: Updating Composer autoload..."
composer dump-autoload

echo -e "${GREEN}✓ Composer autoload updated${NC}"
echo ""

# Run tests to verify installation
echo "Step 5: Running tests to verify installation..."
echo ""
php artisan test --filter=ListingSubmissionWorkflowTest --stop-on-failure

if [ $? -eq 0 ]; then
    echo ""
    echo -e "${GREEN}✓ Tests passed - Phase 2 integration successful!${NC}"
else
    echo ""
    echo -e "${RED}✗ Tests failed - Please check the errors above${NC}"
    echo "Your original files are backed up in: $BACKUP_DIR"
    exit 1
fi

echo ""
echo "================================================"
echo -e "${GREEN}  PHASE 2 INTEGRATION COMPLETE!${NC}"
echo "================================================"
echo ""
echo "What was installed:"
echo "  ✓ 11 Controllers (Public, Tenant, Landlord, Admin)"
echo "  ✓ 2 Middleware (EnsureTenant, EnsureLandlord)"
echo "  ✓ 8 Form Requests (Validation classes)"
echo "  ✓ 3 Policies (PropertyPolicy, UnitPolicy, ListingPolicy)"
echo "  ✓ Complete API routes (routes/api.php)"
echo "  ✓ 2 Feature tests"
echo ""
echo "Original files backed up to: $BACKUP_DIR"
echo ""
echo "Next steps:"
echo "  1. Test the API endpoints (see README.md)"
echo "  2. Review the routes: routes/api.php"
echo "  3. Run full test suite: php artisan test"
echo ""
echo "API Documentation: See README.md in Phase 2 folder"
echo ""
