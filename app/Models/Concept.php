<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    use HasFactory;
    protected $table = 'concepts';

    protected $fillable = [
        'name',
        'symbol',
        'updated_at',
        'created_at',
    ];

    public function Components() {
        return $this->hasMany(Component::class, "id_concept");
    }

    public function Weight () {
        return $this->belongsTo(Weight::class, "id_component");
    }
}
