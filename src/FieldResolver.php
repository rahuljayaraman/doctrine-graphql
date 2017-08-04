<?php

namespace RahulJayaraman\DoctrineGraphQL;

class FieldResolver {
    private $originalInstance;
    private $instance;
    private $resolver;

    public function __construct(
        $instance,
        \ReflectionMethod $resolver = null,
        Callable $proxy
    )
    {
        $this->originalInstance = $instance;
        $this->resolver = $resolver;
        $this->instance = $proxy($instance);
    }

    public function resolve($key, $args = [])
    {
        if (isset($this->resolver)) {
            $methodName = $this->resolver->getName();
            return call_user_func_array(array($this->instance, $methodName), $args);
        }
        return $this->getDefaultGraphQLResolver($key);
    }

    private function getDefaultGraphQLResolver($key)
    {
        $fn = "get". $this->camelCase($key);
        if (method_exists($this->originalInstance, $fn)) {
            return $this->instance->$fn();
        }
        return null;
    }

    private function camelCase($string)
    {
        return implode('', array_map('ucfirst', explode('_', $string)));
    }
}
