<?php

namespace RahulJayaraman\DoctrineGraphQL;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\FileCacheReader;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\IndexedReader;
use RahulJayaraman\DoctrineGraphQL\Annotations\RegisterField;

class Mapper
{

    /**
     * Doctrine EntityManager
     *
     * @var EntityManager
     */
    private static $em;

    /**
     * Custom AnnotationReader
     *
     * @var FileCacheReader | CachedReader
     */
    private static $cachedAnnotationReader;

    /**
     * register
     *
     * @var callable
     */
    private static $register;

    /**
     * lookUp
     *
     * @var callable
     */
    private static $lookUp;

    /**
     * It wraps the instance & returns a new instance
     * Could be used for logging/authorization etc.
     *
     * @var callable
     */
    private static $proxy;

    /**
     * className
     * package\className of doctrine entity to be mapped
     *
     * @var string
     */
    private $className;

    /**
     * typeMappings
     *
     * @var array[string]GraphQLTypeEval
     */
    private $typeMappings = [];

    /**
     * Setup registry & annotations
     *
     * @param EntityManager $em
     * @param Callable $register
     * @param FileCacheReader | CachedReader $cachedAnnotationReader
     * @param callable $lookUp
     */
    public static function setup(
        EntityManager $em,
        callable $register,
        callable $lookUp,
        callable $proxy = null,
        $cachedAnnotationReader = null
    ) {
        $identity = function ($arg) {
            return $arg;
        };
        self::$em = $em;
        self::$register = $register;
        self::$lookUp = $lookUp;
        self::$proxy = isset($proxy) ? $proxy : $identity;
        self::$cachedAnnotationReader = $cachedAnnotationReader;
        AnnotationRegistry::registerFile(__DIR__. "/annotations/Annotations.php");
    }

    /**
     * Extract type from a root entity
     * Recursively extracts types for associations as well
     *
     * @param string $className
     * @param EntityManager $entityManager [deprecated]
     * @return ObjectType;
     *
     * @SuppressWarnings(PHPMD)
     */
    public static function extractType(
        string $className,
        EntityManager $entityManager
    ) {
        $mapper = new Mapper($className);
        return $mapper->getType();
    }


    /**
     * __construct
     *
     * @param string $className
     */
    public function __construct($className)
    {
        $this->className = $className;
        if (!$this->isValid()) {
            throw new \UnexpectedValueException('Class '. $className.
                ' is not a valid doctrine entity.');
        }
    }

    /**
     * Builds type from given class
     *
     * @return ObjectType;
     */
    public function getType()
    {
        $typeName = $this->getTypeName();
        $typeGenFn = function ($typeName) {
            $fieldGetter = function () {
                $blacklistedFieldNames =
                    $this->getBlacklistedFieldNames();
                $metadata = $this->getDoctrineMetadata();
                $fieldMappings = $this->filterAndFormatMappings(
                    $metadata->fieldMappings,
                    $blacklistedFieldNames
                );
                $fields = $this->getFields($fieldMappings);
                $associationMappings = $this->filterAndFormatMappings(
                    $metadata->associationMappings,
                    $blacklistedFieldNames
                );
                $associations = $this->getAssociations($associationMappings);
                $registeredFields = $this->formatKeys($this->getRegisteredFields());
                return array_merge($fields, $associations, $registeredFields);
            };

            return new ObjectType([
                'name' => $typeName,
                'description' => $typeName,
                'fields' => $fieldGetter
            ]);
        };
        return $this->findOrCreateType($typeName, $typeGenFn);
    }

    /**
     * Checks if a className is a valid doctrine entity
     *
     * @return boolean
     */
    public function isValid()
    {
        return !self::$em->getMetadataFactory()->isTransient($this->className);
    }

    /**
     * Finds type from registry or builds one
     *
     * @param string $typeName
     * @param callable $typeGenFn
     * @return ObjectType;
     */
    private function findOrCreateType($typeName, $typeGenFn)
    {
        try {
            $type = $this->findType($typeName);
        } catch (\Exception $e) {
            $type = $typeGenFn($typeName);
            call_user_func_array(self::$register, array($typeName, $type));
        }
        return $type;
    }

    /**
     * Filter and format doctrine mappings
     *
     * @param array $mappings
     * @param string[] $blacklistedFieldNames
     * @return array
     */
    private function filterAndFormatMappings(
        array $mappings,
        array $blacklistedFieldNames
    ) {
        $filtered = array_filter(
            $mappings,
            function ($mapping) use ($blacklistedFieldNames) {
                $fieldName = $mapping['fieldName'];
                return !in_array($fieldName, $blacklistedFieldNames);
            }
        );

        return $this->formatKeys($filtered);
    }

    /**
     * Get blacklisted fields for current entity
     *
     * @return array
     */
    private function getBlacklistedFieldNames()
    {
        $reader = new IndexedReader($this->getAnnotationReader());
        $refClass = new \ReflectionClass($this->className);
        $class = __NAMESPACE__. '\Annotations\BlacklistField';

        $filtered = array_filter(
            $refClass->getProperties(),
            function ($property) use ($class, $reader) {
                $annotations = $reader->getPropertyAnnotations($property);
                return isset($annotations[$class]);
            }
        );

        return array_map(function ($property) {
            return $property->getName();
        }, $filtered);
    }

    /**
     * Gets annotation reader
     *
     * @return AnnotationReader | FileCacheReader | CachedReader
     */
    private function getAnnotationReader()
    {
        if (!$this->isValidCachedReader()) {
            return new AnnotationReader();
        }

        return self::$cachedAnnotationReader;
    }

    /**
     * Checks if supplied annotation reader is valid
     *
     * @return boolean
     */
    private function isValidCachedReader()
    {
        return self::$cachedAnnotationReader instanceof FileCacheReader ||
            self::$cachedAnnotationReader instanceof CachedReader;
    }

    /**
     * Converts keys of given array to camelCase
     *
     * @param array $collection
     * @return array
     */
    private function formatKeys(array $collection)
    {
        $transformed = [];
        foreach ($collection as $key => $item) {
            $transformed[$this->camelCase($key)] = $item;
        }
        return $transformed;
    }

    /**
     * Try & find type in supplied registry
     *
     * @param mixed $typeName
     */
    private function findType($typeName)
    {
        if (!is_callable(self::$lookUp) || !is_callable(self::$register)) {
            throw \Exception("Please define a registry first");
        }

        return call_user_func_array(self::$lookUp, array($typeName));
    }

    /**
     * Build types from association doctrine mappings
     *
     * @param array $associationMappings
     * @return array[string]array
     */
    private function getAssociations($associationMappings)
    {
        $types = [];

        foreach ($associationMappings as $key => $association) {
            $className = $association['targetEntity'];
            $isList = $this->isList($association['type']);
            $type = $this->buildFieldForClass($key, $className, $isList);
            if (!is_null($type)) {
                $types[$key] = $type;
            }
        }

        return $types;
    }

    /**
     * Build Field for a given class
     *
     * @param string $key
     * @param string $className
     * @param boolean $isList
     * @param \ReflectionMethod $resolver
     * @param array $args
     * @return array;
     */
    private function buildFieldForClass(
        string $key,
        string $className,
        $isList,
        \ReflectionMethod $resolver = null,
        $args = null
    ) {
        try {
            $mapper = new Mapper($className);
        } catch (\UnexpectedValueException $e) {
            //TODO: Maybe we should be throwing some error here
            return null;
        }

        if ($isList) {
            $type = Type::listOf($mapper->getType());
        } else {
            $type = $mapper->getType();
        }

        return $this->buildField(
            $key,
            new GraphQLTypeEval($type),
            $resolver,
            $args
        );
    }

    /**
     * Get type name from class name
     *
     * @return string
     */
    private function getTypeName()
    {
        $split = explode("\\", $this->className);
        return array_values(array_slice($split, -1))[0];
    }

    /**
     * Build fields from doctrine mappings
     *
     * @param array $mappings
     * @return array[string]array
     */
    private function getFields($mappings)
    {
        $fields = [];
        foreach ($mappings as $key => $doctrineMetdata) {
            $dTypeKey = $doctrineMetdata['type'];
            $typeMapping = $this->getGraphQLType($dTypeKey);
            if (is_null($typeMapping)) {
                throw new \UnexpectedValueException(
                    "The doctrine type ". $dTypeKey.
                    " has not been mapped correctly."
                );
            }
            $fields[$key] = $this->buildField($key, $typeMapping);
        }
        return $fields;
    }

    /**
     * Build fields from methods registered with RegisterField
     *
     * @return array[string]array
     */
    private function getRegisteredFields()
    {
        $reader = new IndexedReader($this->getAnnotationReader());
        $refClass = new \ReflectionClass($this->className);
        $fields = [];
        foreach ($refClass->getMethods() as $method) {
            $annotations = $reader->getMethodAnnotations($method);
            $class = __NAMESPACE__. '\Annotations\RegisterField';
            if (isset($annotations[$class])) {
                $registration = $annotations[$class];
                $key = is_null($registration->name) ? $method->name :
                    $registration->name;
                $field = $this->buildRegisteredField($key, $registration, $method);
                if (!is_null($field)) {
                    $fields[$key] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * Build field from registered field
     *
     * @param string $key
     * @param RegisterField $registration
     * @param \ReflectionMethod $method
     * @return array
     */
    private function buildRegisteredField(
        String $key,
        RegisterField $registration,
        \ReflectionMethod $method
    ) {
        $typeMapping = $this->getGraphQLType($registration->type);
        //Scalar return value
        if (!is_null($typeMapping)) {
            return $this->buildField($key, $typeMapping, $method);
        }

        $args = array_map(function ($arg) {
            $typeMapping = $this->getGraphQLType($arg['type']);
            $isNullable = $arg['nullable'];
            $type = $isNullable ? $typeMapping->type :
                Type::nonNull($typeMapping->type);
            return [
                'name' => $arg['name'],
                'type' => $type
            ];
        }, $registration->args);

        //Return value is an association

        $registeredClass = $this->getRegistrationReturnType($registration);
        return $this->buildFieldForClass(
            $key,
            $registeredClass,
            $registration->isList,
            $method,
            $args
        );
    }

    /**
     * get classname for the registered field's return type
     *
     * @param RegisterField $registration
     * @return string
     */
    private function getRegistrationReturnType(RegisterField $registration)
    {
        $containsNs = $this->containsNameSpace($registration->type);
        $validate = function ($className) {
            if (!class_exists($className)) {
                throw new \Exception($className. " is not defined in ".
                    $this->className);
            }
            return $className;
        };

        if ($containsNs) {
            return $validate($registration->type);
        }

        $withNs = $this->getClassNameSpace().
            "\\". $registration->type;

        return $validate($withNs);
    }

    /**
     * Does given classname string contain a namespace
     *
     * @param string $className
     * @param boolean
     */
    private function containsNameSpace(string $className)
    {
        return count(explode("\\", $className)) > 1;
    }


    /**
     * Get namespace for current entity
     *
     * @return string
     */
    private function getClassNameSpace()
    {
        $split = explode("\\", $this->className);
        return implode("\\", array_splice($split, 0, -1));
    }

    /**
     * Build a GraphQL field
     *
     * @param string $key
     * @param GraphQLTypeEval $typeMapping
     * @param ReflectionMethod $resolver
     * @param array $args
     * @param string $description
     * @return array
     */
    private function buildField(
        $key,
        $typeMapping,
        \ReflectionMethod $resolver = null,
        $args = null,
        $description = ''
    ) {
        //Eval is used to resolve custom scalar types like date
        $proxy = self::$proxy;
        $resolveFactory = function ($key, $eval) use ($resolver, $proxy) {
            return function ($val, $args) use ($key, $eval, $resolver, $proxy) {
                $fieldResolver = new FieldResolver($val, $resolver, $proxy);
                $result = $fieldResolver->resolve($key, $args);
                if (!$result instanceof PersistentCollection) {
                    return $eval($result);
                }

                return array_map(function ($item) use ($eval) {
                    return $eval($item);
                }, $result->toArray());
            };
        };

        return [
            'description' => $description,
            'type' => $typeMapping->type,
            'args' => $args,
            'resolve' => $resolveFactory($key, $typeMapping->eval)
        ];
    }

    /**
     * Get Doctrine metadata
     *
     * @return Doctrine\ORM\Mapping\ClassMetadata
     */
    private function getDoctrineMetadata()
    {
        $metadata = self::$em->getClassMetadata($this->className);

        if (empty($metadata)) {
            throw new \UnexpectedValueException('No mapping information to process');
        }
        return $metadata;
    }

    /**
     * Get mapping of doctrine types => GraphQL types
     *
     * @param string $dKey
     * @return GraphQLTypeEval
     */
    private function getGraphQLType($dKey)
    {
        if (empty($this->typeMappings)) {
            $this->setTypeMappings();
        }

        if (!isset($this->typeMappings[$dKey])) {
            return null;
        }

        return $this->typeMappings[$dKey];
    }

    /**
     * Set type mappings
     *
     */
    private function setTypeMappings()
    {
        //TODO: Allow custom date handling
        $dateEval = function ($date) {
            $DATE_FORMAT = 'Y-m-d H:i:s';
            if (is_null($date)) {
                return null;
            }
            return $date->format($DATE_FORMAT);
        };

        //TODO: Allow custom json handling
        $jsonHandler = function ($obj) {
            if (is_null($obj)) {
                return null;
            }
            return json_encode($obj);
        };

        $this->typeMappings = [
            'smallint' => new GraphQLTypeEval(Type::int()),
            'integer' => new GraphQLTypeEval(Type::int()),
            'float' => new GraphQLTypeEval(Type::float()),
            'text' => new GraphQLTypeEval(Type::string()),
            'string' => new GraphQLTypeEval(Type::string()),
            'boolean' => new GraphQLTypeEval(Type::boolean()),
            'array' => new GraphQLTypeEval(Type::listOf(Type::string())),
            'json_array' => new GraphQLTypeEval(Type::string(), $jsonHandler),
            'date' => new GraphQLTypeEval(Type::string(), $dateEval),
            'datetime' => new GraphQLTypeEval(Type::string(), $dateEval),
            'time' => new GraphQLTypeEval(Type::string(), $dateEval),
            'decimal' => new GraphQLTypeEval(Type::float())
        ];
    }

    /**
     * Checks if association is list
     *
     * @param integer $associationType
     * @return boolean
     */
    private function isList($associationType)
    {
        $LIST_TYPES = array(4, 8);
        return in_array($associationType, $LIST_TYPES);
    }

    /**
     * Convert to cameCase
     *
     * @param string $string
     * @return string
     */
    private function camelCase($string)
    {
        $func = create_function('$c', 'return strtoupper($c[1]);');
        return preg_replace_callback('/_([a-z])/', $func, $string);
    }
}
