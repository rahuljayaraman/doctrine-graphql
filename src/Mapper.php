<?php

namespace RahulJayaraman\DoctrineGraphQL;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use Doctrine\ORM\PersistentCollection;
use Doctrine\ORM\EntityManager;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\IndexedReader;
use RahulJayaraman\DoctrineGraphQL\Annotations\RegisterField;

class Mapper {

    /**
     * className
     * package\className of doctrine entity to be mapped
     *
     * @var string
     */
    private $className;

    /**
     * em
     * Doctrine EntityManager
     * @var EntityManager
     */
    private $em;

    /**
     * typeMappings
     *
     * @var array[string]GraphQLTypeMapping
     */
    private $typeMappings = [];

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
     * extractType
     * Extract type from a root entity
     * Recursively extracts types for associations as well
     *
     * @param string $className
     * @param EntityManager $entityManager
     * @return ObjectType;
     */
    public static function extractType(
        string $className,
        EntityManager $entityManager
    )
    {
        AnnotationRegistry::registerFile(dirname(__FILE__). "/annotations/RegisterField.php");
        $mapper = new Mapper($className, $entityManager);
        return $mapper->getType();
    }

    /**
     * addRegistry
     *
     * @param Callable $register
     * @param Callable $lookUp
     */
    public static function addRegistry(
        Callable $register,
        Callable $lookUp
    )
    {
        self::$register = $register;
        self::$lookUp = $lookUp;
    }

    /**
     * __construct
     *
     * @param string $className
     * @param EntityManager $entityManager
     */
    public function __construct($className, EntityManager $entityManager)
    {
        $this->className = $className;
        $this->em = $entityManager;
        if (!$this->isValid()) {
            throw new \UnexpectedValueException('Class '. $className.
                ' is not a valid doctrine entity.');
        }
    }

    /**
     * getType
     *
     * @return ObjectType;
     */
    public function getType()
    {
        $typeName = $this->getTypeName();
        $typeGenFn = function ($typeName) {
            $fieldGetter = function () {
                $metadata = $this->getDoctrineMetadata();
                $fieldMappings = $metadata->fieldMappings;
                $fields = $this->getFields($fieldMappings);
                $associationMappings = $metadata->associationMappings;
                $associations = $this->getAssociations($associationMappings);
                $extendedFields = $this->getExtendedFields();
                return array_merge($fields, $associations, $extendedFields);
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
     * isValid
     * Checks if a className is a valid doctrine entity
     *
     * @return boolean
     */
    public function isValid()
    {
        return !$this->em->getMetadataFactory()->isTransient($this->className);
    }

    /**
     * findOrCreateType
     *
     * @param string $typeName
     * @param callable $typeGenFn
     * @return ObjectType;
     */
    private function findOrCreateType($typeName, $typeGenFn)
    {
        try {
            $type = $this->findType($typeName);
        } catch(\Exception $e) {
            $type = $typeGenFn($typeName);
            call_user_func_array(self::$register, array($typeName, $type));
        }
        return $type;
    }

    private function findType($typeName)
    {
        if (!is_callable(self::$lookUp) || !is_callable(self::$register)) {
            throw \Exception("Please define a registry first");
        }

        return call_user_func_array(self::$lookUp, array($typeName));
    }

    /**
     * getAssociations
     *
     * @param array $associationMappings
     * @return array[string]array
     */
    private function getAssociations($associationMappings)
    {
        $types = [];

        foreach($associationMappings as $key => $association) {
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
     * buildFieldForClass
     *
     * @param String $key
     * @param String $className
     * @param boolean $isList
     * @param \ReflectionMethod $resolver
     * @param array $args
     * @return array;
     */
    private function buildFieldForClass(String $key,
        $className,
        $isList,
        \ReflectionMethod $resolver = null,
        $args = null)
    {
        try {
            $mapper = new Mapper($className, $this->em);
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
            new GraphQLTypeMapping($type),
            $resolver,
            $args
        );
    }

    /**
     * getTypeName
     *
     * @return string
     */
    private function getTypeName()
    {
        $split = explode("\\", $this->className);
        return array_values(array_slice($split, -1))[0];
    }

    /**
     * getFields
     *
     * @param array $mappings
     * @return array[string]array
     */
    private function getFields($mappings)
    {
        $fields = [];
        foreach($mappings as $key => $doctrineMetdata) {
            $dTypeKey = $doctrineMetdata['type'];
            $typeMapping = $this->getGraphQLTypeMapping($dTypeKey);
            if (is_null($typeMapping)) {
                throw new \UnexpectedValueException(
                    "The doctrine type ". $dKey.
                    " has not been mapped correctly."
                );
            }

            $fields[$key] = $this->buildField($key, $typeMapping);
        }
        return $fields;
    }

    /**
     * getExtendedFields
     *
     * @return array[string]array
     */
    private function getExtendedFields()
    {
        $reader = new IndexedReader(new AnnotationReader());
        $refClass = new \ReflectionClass($this->className);
        $fields = [];
        foreach($refClass->getMethods() as $method)
        {
            $annotations = $reader->getMethodAnnotations($method);
            $class = __NAMESPACE__. '\Annotations\RegisterField';
            if (isset($annotations[$class])) {
                $registration = $annotations[$class];
                $key = $method->name;
                $field = $this->buildExtendedField($key, $registration, $method);
                if (!is_null($field)) {
                    $fields[$key] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * buildExtendedField
     *
     * @param string $key
     * @param RegisterField $registration
     * @param \ReflectionMethod $method
     * @return array
     */
    private function buildExtendedField(String $key,
        RegisterField $registration,
        \ReflectionMethod $method)
    {
        $typeMapping = $this->getGraphQLTypeMapping($registration->type);
        //Scalar return value
        if (!is_null($typeMapping)) {
            return $this->buildField($key, $typeMapping);
        }

        $args = array_map(function ($arg) {
            $typeMapping = $this->getGraphQLTypeMapping($arg[1]);
            return [
                'name' => $arg[0],
                'type' => $typeMapping->type
            ];
        }, $registration->args);

        //Return value is an association
        $registeredClass = $this->getClassNameSpace().
            "\\". $registration->type;
        return $this->buildFieldForClass(
            $key,
            $registeredClass,
            $registration->isList,
            $method,
            $args
        );
    }


    /**
     * getClassNameSpace
     *
     * @return string
     */
    private function getClassNameSpace()
    {
        $split = explode("\\", $this->className);
        return implode("\\", array_splice($split, 0, -1));
    }

    /**
     * buildField
     *
     * @param string $key
     * @param GraphQLTypeMapping $typeMapping
     * @param ReflectionMethod $resolver
     * @param array $args
     * @param string $description
     * @return array
     */
    private function buildField($key,
        $typeMapping,
        \ReflectionMethod $resolver = null,
        $args = null,
        $description = '')
    {
        //Eval is used to resolve custom scalar types like date
        $resolveFactory = function ($key, $eval) use ($resolver) {
            return function ($val, $args) use ($key, $eval, $resolver) {
                $fieldResolver = new FieldResolver($val, $resolver);
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
     * getDoctrineMetadata
     *
     * @return Doctrine\ORM\Mapping\ClassMetadata
     */
    private function getDoctrineMetadata()
    {
        $metadata = $this->em->getClassMetadata($this->className);

        if (empty($metadata)) {
            throw new \UnexpectedValueException('No mapping information to process');
        }
        return $metadata;
    }

    /**
     * getGraphQLTypeMapping
     *
     * @param string $dKey
     * @return GraphQLTypeMapping
     */
    private function getGraphQLTypeMapping($dKey)
    {
        if (empty($this->typeMappings)) {
            $this->setTypeMappings();
        }

        if (!isset($this->typeMappings[$dKey])) {
            return null;
        }

        return $this->typeMappings[$dKey];
    }

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
            'smallint' => new GraphQLTypeMapping(Type::int()),
            'integer' => new GraphQLTypeMapping(Type::int()),
            'float' => new GraphQLTypeMapping(Type::float()),
            'text' => new GraphQLTypeMapping(Type::string()),
            'string' => new GraphQLTypeMapping(Type::string()),
            'boolean' => new GraphQLTypeMapping(Type::boolean()),
            'array' => new GraphQLTypeMapping(Type::listOf(Type::string())),
            'json_array' => new GraphQLTypeMapping(Type::string(), $jsonHandler),
            'date' => new GraphQLTypeMapping(Type::string(), $dateEval),
            'datetime' => new GraphQLTypeMapping(Type::string(), $dateEval),
            'time' => new GraphQLTypeMapping(Type::string(), $dateEval),
            'decimal' => new GraphQLTypeMapping(Type::float())
        ];
    }

    /**
     * isList
     *
     * @param integer $associationType
     * @return boolean
     */
    private function isList($associationType)
    {
        $LIST_TYPES = array(4, 8);
        return in_array($associationType, $LIST_TYPES);
    }
}
