<?php

namespace RahulJayaraman\DoctrineGraphQL;

class FieldResolver {
    private $instance;
    private $resolver;

    public function __construct($instance, \ReflectionMethod $resolver = null)
    {
        $this->instance = $instance;
        $this->resolver = $resolver;
    }

    public function resolve($key, $args = null)
    {
        if (isset($this->resolver)) {
            return $this->resolver->invoke($this->instance, $args);
        }
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
