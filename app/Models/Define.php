<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Define extends Model
{
    use HasFactory;

    protected $table = 'defines';

    public function Semantic() {
        return $this->hasMany(Semantic::class, "id_def");
    }
}
