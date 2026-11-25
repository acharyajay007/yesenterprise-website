<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['type', 'name', 'email', 'phone', 'company', 'product', 'variant', 'quantity', 'role', 'message', 'locale', 'resume_path', 'resume_extension', 'delivery_status', 'delivery_error', 'delivered_at'])]
class Submission extends Model
{
    protected function casts(): array
    {
        return ['delivered_at' => 'datetime'];
    }
}
