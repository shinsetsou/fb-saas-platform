<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceUser extends Model
{
    protected $fillable = ['workspace_id', 'user_id', 'role'];
}
