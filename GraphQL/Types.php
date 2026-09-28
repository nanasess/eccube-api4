<?php

/*
 * This file is part of EC-CUBE
 *
 * Copyright(c) EC-CUBE CO.,LTD. All Rights Reserved.
 *
 * http://www.ec-cube.co.jp/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Plugin\Api44\GraphQL;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\AssociationMapping;
use Doctrine\ORM\Mapping\FieldMapping;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\Mapping\ManyToManyOwningSideMapping;
use Doctrine\ORM\Mapping\ManyToOneAssociationMapping;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Mapping\OneToOneInverseSideMapping;
use Doctrine\ORM\Mapping\OneToOneOwningSideMapping;
use Eccube\Entity\ProductClass;
use GraphQL\Type\Definition\ListOfType;
use GraphQL\Type\Definition\NonNull;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\Type;
use Plugin\Api44\GraphQL\Type\Definition\DateTimeType;

/**
 * DoctrineのEntityからGraphQLのObjectTypeを変換するクラス.
 */
class Types
{
    /**
     * マッピングに無いが, getter が値を返すフィールド. Doctrine の型と null を許すかで定義する.
     *
     * - ProductClass::stock: EC-CUBE 4.4 で在庫数を ProductStock::stock に一本化した際にマッピングから外れた.
     *   ProductClass::getStock() は ProductStock::stock へ委譲するため, 従来どおり ProductClass 型で返す.
     *
     * @var array<class-string, array<string, array{type: string, nullable: bool}>>
     */
    private const UNMAPPED_FIELDS = [
        ProductClass::class => [
            'stock' => ['type' => 'decimal', 'nullable' => true],
        ],
    ];

    /** @var EntityManager */
    private EntityManager $entityManager;

    /**
     * @var array<string, ObjectType>
     */
    private array $types = [];

    /**
     * @var AllowList[]
     */
    private array $allowLists = [];

    /**
     * Types constructor.
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function addAllowList(AllowList $allowList): void
    {
        $this->allowLists[] = $allowList;
    }

    /**
     * Entityに対応するObjectTypeを返す.
     *
     * @param class-string $className Entityクラス名
     */
    public function get(string $className): ObjectType
    {
        if (!isset($this->types[$className])) {
            $this->types[$className] = $this->createObjectType($className);
        }

        return $this->types[$className];
    }

    /**
     * @param class-string $className
     */
    private function createObjectType(string $className): ObjectType
    {
        return new ObjectType([
            'name' => (new \ReflectionClass($className))->getShortName(),
            'fields' => function () use ($className) {
                $classMetadata = $this->entityManager->getClassMetadata($className);
                $fields = array_reduce($classMetadata->fieldMappings, function (array $acc, FieldMapping $mapping) use ($classMetadata): array {
                    $type = $this->convertFieldMappingToType($mapping);
                    $fieldName = $mapping['fieldName'];

                    $allowed = array_filter($this->allowLists, function (AllowList $al) use ($classMetadata, $fieldName): bool {
                        return $al->isAllowed($classMetadata->name, $fieldName);
                    });

                    if ($allowed && $type) {
                        $acc[$fieldName] = $type;
                    }

                    return $acc;
                }, []);

                foreach (self::UNMAPPED_FIELDS[$classMetadata->name] ?? [] as $fieldName => $definition) {
                    if (isset($classMetadata->fieldMappings[$fieldName])) {
                        // マッピングされている EC-CUBE ではマッピングから作った型を使う
                        continue;
                    }

                    $allowed = array_filter($this->allowLists, function (AllowList $al) use ($classMetadata, $fieldName): bool {
                        return $al->isAllowed($classMetadata->name, $fieldName);
                    });

                    $type = $this->convertDoctrineTypeToType($definition['type'], $definition['nullable']);
                    if ($allowed && $type) {
                        $fields[$fieldName] = $type;
                    }
                }

                $fields = array_reduce($classMetadata->associationMappings, function (array $acc, ManyToManyInverseSideMapping|ManyToManyOwningSideMapping|ManyToOneAssociationMapping|OneToManyAssociationMapping|OneToOneInverseSideMapping|OneToOneOwningSideMapping $mapping) use ($classMetadata): array {
                    $fieldName = $mapping['fieldName'];

                    $allowed = array_filter($this->allowLists, function (AllowList $al) use ($classMetadata, $fieldName): bool {
                        return $al->isAllowed($classMetadata->name, $fieldName);
                    });

                    if ($allowed) {
                        $acc[$fieldName] = [
                            'type' => $this->convertAssociationMappingToType($mapping),
                        ];
                    }

                    return $acc;
                }, $fields);

                return $fields;
            },
        ]);
    }

    private function convertFieldMappingToType(FieldMapping $fieldMapping): ScalarType|NonNull|null
    {
        if (isset($fieldMapping['id'])) {
            $type = Type::id();

            return $fieldMapping['nullable'] ? $type : Type::nonNull($type);
        }

        return $this->convertDoctrineTypeToType($fieldMapping['type'], (bool) $fieldMapping['nullable']);
    }

    private function convertDoctrineTypeToType(string $doctrineType, bool $nullable): ScalarType|NonNull|null
    {
        // マッピングに無い Doctrine 型 (date / bigint / json 等) は null になり得る
        $type = [
            'string' => Type::string(),
            'text' => Type::string(),
            'integer' => Type::int(),
            'decimal' => Type::float(),
            'datetimetz' => DateTimeType::dateTime(),
            'smallint' => Type::int(),
            'boolean' => Type::boolean(),
        ][$doctrineType] ?? null;

        if ($type === null) {
            return null;
        }

        return $nullable ? $type : Type::nonNull($type);
    }

    /**
     * @return ListOfType<ObjectType>|ObjectType
     */
    private function convertAssociationMappingToType(ManyToManyInverseSideMapping|ManyToManyOwningSideMapping|ManyToOneAssociationMapping|OneToManyAssociationMapping|OneToOneInverseSideMapping|OneToOneOwningSideMapping $mapping): ListOfType|ObjectType
    {
        return $this->isToManyAssociation($mapping) ? Type::listOf($this->get($mapping['targetEntity'])) : $this->get($mapping['targetEntity']);
    }

    private function isToManyAssociation(AssociationMapping $mapping): bool
    {
        return $mapping->isToMany();
    }
}
