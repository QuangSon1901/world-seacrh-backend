<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Node extends Model
{
    use HasFactory;
    protected $table = 'nodes';

    protected $fillable = [
        'label',
        'content',
        'z_index',
        'status',
    ];

    public function NodeFather() {
        return $this->hasMany(RelationNode::class, "id_node_father");
    }

    public function NodeChildren() {
        return $this->hasMany(RelationNode::class, "id_node_children");
    }
}
