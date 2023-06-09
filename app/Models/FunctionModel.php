<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FunctionModel extends Model
{
    use HasFactory;
    protected $table = 'funcs';

    protected $fillable = [
        'name',
        'updated_at',
        'created_at',
    ];

    public function Components() {
        return $this->hasMany(Component::class, "id_func");
    }
}
