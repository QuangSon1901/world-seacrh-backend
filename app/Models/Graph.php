<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Graph extends Model
{
    use HasFactory;
    protected $table = 'graphs';

    public function KeyphraseFirst() {
        return $this->belongsTo(Keyphrase::class, "k_first");
    }

    public function KeyphraseSecond() {
        return $this->belongsTo(Keyphrase::class, "k_second");
    }

    public function Relation() {
        return $this->belongsTo(Relationship::class, "relation");
    }
}
