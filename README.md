### WARNING: This repo is just an experiment & is not maintained. Please use at your own risk. Suggest using https://github.com/Ecodev/graphql-doctrine instead

## Doctrine GraphQL Mapper

_Builds GraphQL types out of doctrine entities_

### Installation
```
$> curl -sS https://getcomposer.org/installer | php
$> php composer.phar require rahuljayaraman/doctrine-graphql
```

### Requirements
PHP >=5.4

### Overview

The mapper builds GraphQL types out of doctrine entities. It's built on top of [webonyx/graphql-php](webonyx/graphql-php) and maps doctrine entities to an [ObjectType](http://webonyx.github.io/graphql-php/type-system/object-types/) graph at runtime.

Here's an example. Consider the following schema

```
Employee
│
└───Company (getCompanies)
|
└───User (getUser)
```

We can extract the type for Employee by using

```php
Mapper::extractType(Employee::class)
```
[Associations](http://docs.doctrine-project.org/projects/doctrine-orm/en/latest/reference/association-mapping.html) are recursively extracted as well. So `$employee->getCompanies()` would return [ListOf](http://webonyx.github.io/graphql-php/type-system/lists-and-nonnulls/) Type Company & `$employee->getUser()` would return Type User

#### NOTE: This library is not responsible for any of your GraphQL setup like setting up routes, root nodes etc.

### TODO

* [Support 'Relay Cursor Connections' ](https://github.com/rahuljayaraman/doctrine-graphql/issues/2)
* [Remove Doctrine coupling](https://github.com/rahuljayaraman/doctrine-graphql/issues/4)
* [Allow wrapping getter code](https://github.com/rahuljayaraman/doctrine-graphql/issues/3)

### Usage

#### Setup

Setup accepts 3 args. Doctrine's EntityManager, a setter method & a getter method to a type store (a data structure which stores types).

```php
//Setup code, I use this in a laravel service provider
use RahulJayaraman\DoctrineGraphQL\Mapper;
use Doctrine\ORM\EntityManager;

Mapper::setup(
    app(EntityManager::class),
    function ($typeName, $type) {
        Cache::add($type, $typeName);
    },
    function ($typeName) {
        return Cache::get($typeName);
    }
);
```

`Cache` above could be replaced by any store.
Eg. using [Folkloreatelier/laravel-graphql](https://github.com/Folkloreatelier/laravel-graphql)'s store

```php
use Folklore\GraphQL\Support\Facades\GraphQL;

Mapper::setup(
    app(EntityManager::class),
    function ($typeName, $type) {
        GraphQL::addType($type, $typeName);
    },
    function ($typeName) {
        return GraphQL::type($typeName);
    }
);
```


#### Extract type

To extract the type

```php
Mapper::extractType(Entity::class);
```

We could place it [here](https://github.com/Folkloreatelier/laravel-graphql#creating-a-query) if using with [Folkloreatelier/laravel-graphql](https://github.com/Folkloreatelier/laravel-graphql).

```php
public function type()
{
   return Mapper::extractType(Entity::class);
}
```

#### Default resolver

For now, given a field name, say `fieldName`, the mapper will look for a `getFieldName` getter method on the entity. There are plans to allow customization here.

#### Register additional fields

For registering additional fields, one can use the RegisterField annotation.

RegisterField accepts `name`, `type` and `args`.

`name` accepts a string.

`type` accepts either an [internal type](https://github.com/webonyx/graphql-php#internal-types) or any of the extracted entities.

`args` accepts an array of tuples in the form of `{{string, type}}`

Here's an example

```php
use RahulJayaraman\DoctrineGraphQL\Annotations\RegisterField;


/**
 * getEmployee
 *
 * @RegisterField(name="CustomName" type="Employee", args={{"slug", "string"}})
 */
public function getEmployee($slug)
{
    return ...
}
```

#### Blacklist fields

Fields can be blacklisted using the BlacklistField annotation. Here's an example.

```php
use RahulJayaraman\DoctrineGraphQL\Annotations\BlacklistField;

/**
 * @var string
 * @ORM\Column(type="string")
 * @BlacklistField()
 */
private $password;
```

### Complementary Tools
- [Use GraphQL with Laravel 5](https://github.com/Folkloreatelier/laravel-graphql)
