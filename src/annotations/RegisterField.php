<?php

namespace RahulJayaraman\DoctrineGraphQL\Annotations;

/**
 * @Annotation
 * @Target({"METHOD", "PROPERTY"})
 */
class RegisterField {
    /** @Required */
    public $type;

    public $isList;
    public $args;

    public function __construct(array $values)
    {
        $this->type = $values['type'];
        $this->args = isset($values['args']) ? $values['args'] : false;
        $this->isList = isset($values['isList']) ? $values['isList'] : false;
    }
}
