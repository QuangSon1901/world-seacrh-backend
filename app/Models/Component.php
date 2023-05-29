<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Component extends Model
{
    use HasFactory;
    protected $table = 'components';

    public function Graph() {
        return $this->hasMany(Graph::class, "id_component");
    }

    public function Weight() {
        return $this->hasMany(Weight::class, "id_component");
    }

    public function TypeComponent() {
        return $this->belongsTo(TypeComponent::class, "id_type_component");
    }

    public function Concept() {
        return $this->belongsTo(Concept::class, "id_concept");
    }

    public function RelationCC() {
        return $this->belongsTo(RelationCC::class, "id_relationcc");
    }

    public function Rule() {
        return $this->belongsTo(Rule::class, "id_rule");
    }

    public function Method() {
        return $this->belongsTo(Method::class, "id_method");
    }

    public function Function() {
        return $this->belongsTo(FunctionModel::class, "id_func");
    }

    public function Operator() {
        return $this->belongsTo(Operator::class, "id_operator");
    }
}
