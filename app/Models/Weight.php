<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Weight extends Model
{
    use HasFactory;
    protected $table = 'weight';

    protected $fillable = [
        'tf',
        'ip',
        'id_keyphrase',
        'id_component',
    ];

    public function Keyphrase() {
        return $this->belongsTo(Keyphrase::class, 'id_keyphrase');
    }
}
