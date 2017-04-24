<?php

namespace DoctrineGraphQL;

class GraphQLTypeMapping {
    /**
     * type
     *
     * @var array|IDType|StringType|FloatType|IntType|BooleanType
     */
    public $type;

    /**
     * eval
     * Allows evaluation of non-scalar objects like date, json etc.
     *
     * @var callable
     */
    public $eval;

    public function __construct($type, $eval = null)
    {
        $defaultEval = function ($val) {
            return $val;
        };

        $this->type = $type;
        $this->eval = is_null($eval) ? $defaultEval : $eval;
    }
}
