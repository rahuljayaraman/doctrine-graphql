<?php

namespace DoctrineGraphQL;

class FieldResolver {
    private $instance;

    public function __construct($instance)
    {
        $this->instance = $instance;
    }

    public function getKey($key)
    {
        return $this->getDefaultGraphQLResolver($key);
    }

    private function getDefaultGraphQLResolver($key)
    {
        $fn = "get". $this->camelCase($key);
        if (method_exists($this->instance, $fn)) {
            return $this->instance->$fn();
        }
        return null;
    }

    private function camelCase($string)
    {
        return implode('', array_map('ucfirst', explode('_', $string)));
    }
}
