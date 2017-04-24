<?php

namespace RahulJayaraman\DoctrineGraphQL;

use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\ObjectType;
use Doctrine\ORM\PersistentCollection;
use Folklore\GraphQL\Support\Facades\GraphQL;
use Folklore\GraphQL\Exception\TypeNotFound;
use Doctrine\ORM\EntityManager;

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
        $mapper = new Mapper($className, $entityManager);
        return $mapper->getType();
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
                return array_merge($fields, $associations);
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
     * @param string $typeName
     * @param callable $typeGenFn
     * @return ObjectType;
     *
     * This seems to be the only dependency on laravel-graphql
     * TODO: Try & pass in registry finder & register as params
     */
    private function findOrCreateType($typeName, $typeGenFn)
    {
        try {
            $type = GraphQL::type($typeName);
        } catch(TypeNotFound $e) {
            $type = $typeGenFn($typeName);
            //laravel-graphql defines a pattern guard using known types
            //This will add our type to the white list & also to the type
            //registry
            GraphQL::addType($type, $typeName);
        }
        return $type;
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

            try {
                $mapper = new Mapper($className, $this->em);
            } catch (\UnexpectedValueException $e) {
                //TODO: Maybe we should be throwing some error here
                continue;
            }

            if ($this->isList($association['type'])) {
                $type = Type::listOf($mapper->getType());
            } else {
                $type = $mapper->getType();
            }

            $types[$key] = $this->buildField(
                $key, new GraphQLTypeMapping($type)
            );
        }

        return $types;
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
            $fields[$key] = $this->buildField($key, $typeMapping);
        }
        return $fields;
    }

    /**
     * buildField
     *
     * @param string $key
     * @param GraphQLTypeMapping $typeMapping
     * @param string $description
     * @return array
     */
    private function buildField($key, $typeMapping, $description = '')
    {
        //Eval is used to resolve custom scalar types like date
        $resolveFactory = function ($key, $eval) {
            return function ($val, $args) use ($key, $eval) {
                $fieldResolver = new FieldResolver($val);
                $result = $fieldResolver->getKey($key);
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
            throw new \UnexpectedValueException(
                "The doctrine type ". $dKey.
                " has not been mapped correctly. Defaulting to `string`"
            );
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
