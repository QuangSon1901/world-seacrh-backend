<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rule extends Model
{
    use HasFactory;
    protected $table = 'rules';

    protected $fillable = [
        'name',
        'id_type_rule',
        'updated_at',
        'created_at',
    ];
}
