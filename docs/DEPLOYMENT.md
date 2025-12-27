# Phase 1 Deployment Checklist

Use this checklist when deploying Nexus Phase 1 to staging or production.

---

## Pre-Deployment

### Code Review
- [ ] All migrations reviewed and tested
- [ ] All models have appropriate relationships
- [ ] Services include proper type hints and return types
- [ ] Events and listeners are properly registered in `EventServiceProvider`
- [ ] Enums are used for all state fields
- [ ] Soft deletes configured where required

### Database Preparation
- [ ] PostgreSQL 13+ available
- [ ] Database created with proper encoding (UTF8)
- [ ] Database user has appropriate permissions
- [ ] Connection tested from application server

### Environment Configuration
- [ ] `.env` file created from `.env.example`
- [ ] `APP_KEY` generated
- [ ] `APP_ENV` set correctly (`staging` or `production`)
- [ ] `APP_DEBUG` set to `false` for production
- [ ] Database credentials configured
- [ ] Mail configuration completed
- [ ] Queue connection configured (Redis recommended)

### Dependencies
- [ ] PHP 8.3+ installed
- [ ] Required PHP extensions installed:
  - [ ] pdo_pgsql
  - [ ] mbstring
  - [ ] xml
  - [ ] curl
  - [ ] openssl
  - [ ] json
- [ ] Composer dependencies installed (`composer install --no-dev --optimize-autoloader`)
- [ ] Redis server running (if using Redis queues)

---

## Deployment Steps

### 1. Database Migration
```bash
# Backup existing database (if applicable)
pg_dump nexus > backup_$(date +%Y%m%d_%H%M%S).sql

# Run migrations
php artisan migrate --force
```

### 2. Seed Initial Data
```bash
# Create Super Admin and core features
php artisan db:seed --class=Phase1Seeder --force
```

**Important**: Change default admin password immediately:
```php
$admin = Admin::where('email', 'admin@nexus.com')->first();
$admin->update(['password' => Hash::make('NEW_SECURE_PASSWORD')]);
```

### 3. Storage Setup
```bash
# Create storage link
php artisan storage:link

# Set permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```

### 4. Queue Workers
```bash
# Start queue workers (use supervisor in production)
php artisan queue:work --queue=default,emails --tries=3 --timeout=90
```

**Supervisor Configuration** (`/etc/supervisor/conf.d/nexus-worker.conf`):
```ini
[program:nexus-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/nexus/artisan queue:work --queue=default,emails --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/nexus/storage/logs/worker.log
stopwaitsecs=3600
```

### 5. Caching
```bash
# Optimize for production
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 6. Web Server Configuration

**Nginx Example**:
```nginx
server {
    listen 80;
    server_name nexus.example.com;
    root /path/to/nexus/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 7. SSL Certificate
```bash
# Install certbot and obtain certificate
sudo certbot --nginx -d nexus.example.com
```

---

## Post-Deployment

### Verification Checks

#### Database
- [ ] All tables created successfully
- [ ] Indexes created on foreign keys
- [ ] Super Admin account exists
- [ ] Core features seeded

#### Application
- [ ] Homepage loads without errors
- [ ] API routes respond correctly
- [ ] Authentication works (user and admin)
- [ ] Queue workers processing jobs

#### Security
- [ ] `.env` file not accessible via web
- [ ] `APP_DEBUG` is `false`
- [ ] Error logs not exposed
- [ ] Admin password changed from default
- [ ] HTTPS configured and working

#### Features
- [ ] Public listing search works
- [ ] Filters function correctly
- [ ] Saved listings work for tenants
- [ ] Feature gating enforced for landlords
- [ ] Audit logs being created

### Monitoring Setup

#### Log Monitoring
```bash
# Laravel logs
tail -f storage/logs/laravel.log

# Queue worker logs
tail -f storage/logs/worker.log
```

#### Database Monitoring
- [ ] Connection pool monitoring
- [ ] Slow query logging enabled
- [ ] Disk space alerts configured

#### Application Monitoring
- [ ] Error tracking configured (Sentry, Bugsnag, etc.)
- [ ] Performance monitoring enabled
- [ ] Uptime monitoring configured

### Email Verification
- [ ] Test welcome email sends
- [ ] Email logs being created
- [ ] SMTP credentials working
- [ ] Queue processing emails

---

## Rollback Plan

If deployment fails, follow these steps:

### 1. Restore Database
```bash
# Stop application
sudo systemctl stop nginx
sudo systemctl stop supervisor

# Restore database
psql nexus < backup_TIMESTAMP.sql

# Restart services
sudo systemctl start nginx
sudo systemctl start supervisor
```

### 2. Revert Code
```bash
git checkout previous_stable_tag
composer install --no-dev --optimize-autoloader
php artisan migrate:rollback --step=X
```

### 3. Clear Caches
```bash
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan cache:clear
```

---

## Security Hardening

### Application Level
- [ ] Rate limiting configured
- [ ] CORS headers configured
- [ ] CSRF protection enabled
- [ ] SQL injection protection (use Eloquent/Query Builder)
- [ ] XSS protection (escape all output)

### Server Level
- [ ] Firewall configured (UFW/iptables)
- [ ] SSH key-only authentication
- [ ] Fail2ban installed and configured
- [ ] Automatic security updates enabled
- [ ] Regular backup schedule

### Database Level
- [ ] Database user has minimal required permissions
- [ ] Database not exposed to public internet
- [ ] Connection encryption enabled
- [ ] Regular backups configured

---

## Maintenance

### Daily
- [ ] Check error logs
- [ ] Monitor disk space
- [ ] Verify queue workers running

### Weekly
- [ ] Review audit logs for suspicious activity
- [ ] Check performance metrics
- [ ] Verify backups successful

### Monthly
- [ ] Update dependencies (`composer update`)
- [ ] Review and archive old logs
- [ ] Security audit

---

## Emergency Contacts

**Technical Lead**: _________  
**Database Admin**: _________  
**DevOps Lead**: _________  
**Security Team**: _________  

---

## Deployment Sign-off

**Deployed By**: _________  
**Date**: _________  
**Version**: Phase 1 - Foundation  
**Environment**: _________  

**Checklist Completed**: [ ]  
**All Tests Passing**: [ ]  
**Stakeholder Approval**: [ ]  

---

**Last Updated**: December 2024
