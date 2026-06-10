<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacebookPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'page_id',
        'page_name',
        'page_access_token',
        'user_access_token',
        'subscribed_at',
    ];

    protected $casts = [
        'page_access_token' => 'encrypted',
        'user_access_token' => 'encrypted',
        'subscribed_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
