<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MaintenanceAdminNote
 *
 * Internal, admin-only note attached to a maintenance request during platform
 * oversight. Never exposed to the tenant or landlord — mirrors ListingNote.
 */
class MaintenanceAdminNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_request_id',
        'admin_id',
        'body',
    ];

    public function maintenanceRequest(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
