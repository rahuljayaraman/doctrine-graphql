<?php

namespace RahulJayaraman\DoctrineGraphQL;

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

    /**
     * args
     *
     * Field Resolution Args
     * @var array
     */
    public $args;

    public function __construct($type, $eval = null, $args = null)
    {
        $defaultEval = function ($val) {
            return $val;
        };

        $this->type = $type;
        $this->eval = is_null($eval) ? $defaultEval : $eval;
        $this->args = $args;
    }
}
