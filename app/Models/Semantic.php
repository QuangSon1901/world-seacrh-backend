<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Semantic extends Model
{
    use HasFactory;
    protected $table = 'semantics';

    public function Graph() {
        return $this->hasMany(Graph::class);
    }
}
