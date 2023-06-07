<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    use HasFactory;
    protected $table = 'ops';

    protected $fillable = [
        'name',
        'symbol',
        'updated_at',
        'created_at',
    ];
}
