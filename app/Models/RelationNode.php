<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RelationNode extends Model
{
    use HasFactory;
    protected $table = 'relation_nodes';

    protected $fillable = [
        'id_node_father',
        'id_node_children',
    ];
}
