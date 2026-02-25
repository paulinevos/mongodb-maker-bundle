<?php

declare(strict_types=1);

/*
 * This file is part of the Doctrine MongoDB Maker Bundle package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Doctrine\Bundle\MongoDBMakerBundle\Tests\MongoDB;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\Bundle\MongoDBMakerBundle\MongoDB\MongoDBHelper;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;
use Doctrine\ODM\MongoDB\Mapping\Driver\AttributeDriver;
use Doctrine\ODM\MongoDB\Types\Type;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Generator;
use MongoDB\BSON\ObjectId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionProperty;
use Symfony\Component\Uid\Uuid;

class MongoDBHelperTest extends TestCase
{
    private MongoDBHelper $helper;
    private ManagerRegistry&Stub $registry;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(ManagerRegistry::class);
        $this->helper   = new MongoDBHelper($this->registry);
    }

    #[DataProvider('getPotentialCollectionNameDataProvider')]
    public function testGetPotentialCollectionName(string $className, string $expectedCollectionName): void
    {
        $result = $this->helper->getPotentialCollectionName($className);

        $this->assertSame($expectedCollectionName, $result);
    }

    /** @return Generator<string, array{0: string, 1: string}> */
    public static function getPotentialCollectionNameDataProvider(): Generator
    {
        yield 'simple class name' => [
            'App\\Document\\User',
            'user',
        ];

        yield 'camel case to snake case' => [
            'App\\Document\\UserProfile',
            'user_profile',
        ];

        yield 'already snake case' => [
            'App\\Document\\user_account',
            'user_account',
        ];
    }

    public function testGetDocumentNamespaceWhenDocumentManagerIsNotAvailable(): void
    {
        $this->registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $result = $this->helper->getDocumentNamespace();

        $this->assertSame('App\\Document', $result);
    }

    /** @param class-string[] $classNames */
    #[DataProvider('getDocumentNamespaceWithDocumentsDataProvider')]
    public function testGetDocumentNamespaceWithDocuments(array $classNames, string $expectedNamespace): void
    {
        $documentManagerMock = $this->createStub(DocumentManager::class);
        $configMock          = $this->createStub(Configuration::class);
        $driverMock          = $this->createStub(AttributeDriver::class);

        $driverMock->method('getAllClassNames')->willReturn($classNames);
        $configMock->method('getMetadataDriverImpl')->willReturn($driverMock);
        $documentManagerMock->method('getConfiguration')->willReturn($configMock);

        $this->registry->method('getManager')->willReturn($documentManagerMock);
        $result = $this->helper->getDocumentNamespace();

        $this->assertSame($expectedNamespace, $result);
    }

    /** @return Generator<string, array<int, mixed>> */
    public static function getDocumentNamespaceWithDocumentsDataProvider(): Generator
    {
        yield 'single class with Document namespace' => [
            ['App\\Document\\User'],
            'App\\Document',
        ];

        yield 'multiple classes in Document namespace' => [
            ['App\\Document\\User', 'App\\Document\\Post'],
            'App\\Document',
        ];

        yield 'classes in Document and other namespace' => [
            ['App\\Models\\Post', 'App\\Document\\User'],
            'App\\Document',
        ];

        yield 'single class without Document namespace' => [
            ['App\\Entity\\User'],
            'App\\Entity',
        ];

        yield 'multiple classes without Document namespace' => [
            ['App\\Entity\\User', 'App\\Entity\\Post'],
            'App\\Entity',
        ];

        yield 'multiple different namespaces without Document' => [
            ['App\\Entity\\User', 'App\\Models\\Post'],
            'App\\Entity',
        ];

        yield 'multiple different namespaces with Document' => [
            ['App\\Entity\\User', 'App\\Document\\Post', 'App\\Models\\Product'],
            'App\\Document',
        ];

        yield 'nested Document namespace' => [
            ['Doctrine\\Bundle\\MongoDBMakerBundle\\Document\\BlogPost'],
            'Doctrine\\Bundle\\MongoDBMakerBundle\\Document',
        ];

        yield 'single class without namespace' => [
            ['SingleClass'],
            'App\\Document',
        ];

        yield 'empty class list' => [
            [],
            'App\\Document',
        ];
    }

    #[DataProvider('canFieldTypeBeInferredByPropertyTypeDataProvider')]
    public function testCanFieldTypeBeInferredByPropertyType(string $fieldType, string $propertyType, bool $expectedResult): void
    {
        $this->assertEquals($expectedResult, MongoDBHelper::canFieldTypeBeInferredByPropertyType($fieldType, $propertyType));
    }

    public static function canFieldTypeBeInferredByPropertyTypeDataProvider(): Generator
    {
        yield 'string field type with string property type' => [Type::STRING, 'string', true];
        yield 'int field type with int property type' => [Type::INT, 'int', true];
        yield 'float field type with float property type' => [Type::FLOAT, 'float', true];
        yield 'bool field type with bool property type' => [Type::BOOL, 'bool', true];
        yield 'date field type with DateTime property type' => [Type::DATE, '\\' . DateTime::class, true];
        yield 'date_immutable field type with DateTimeImmutable property type' => [Type::DATE_IMMUTABLE, '\\' . DateTimeImmutable::class, true];
        yield 'has field type with array property type' => [Type::HASH, 'array', true];
        yield 'uuid field type with Uuid property type' => [Type::UUID, Uuid::class, true];

        yield 'object ID with string property type' => [Type::OBJECTID, 'string', false];
        yield 'collection field type with array property type' => [Type::COLLECTION, 'array', false];
    }

    /** @throws ReflectionException */
    #[DataProvider('getPropertyTypeForFieldDataProvider')]
    public function testGetPropertyTypeForField(string $fieldType, string|null $expectedPropertyType): void
    {
        $this->assertSame($expectedPropertyType, MongoDBHelper::getPropertyTypeForField($fieldType));
    }

    public static function getPropertyTypeForFieldDataProvider(): Generator
    {
        Type::registerType('custom_type', CustomType::class);

        yield [Type::STRING, 'string'];
        yield [Type::BINDATA, 'string'];
        yield [Type::BINDATABYTEARRAY, 'string'];
        yield [Type::BINDATACUSTOM, 'string'];
        yield [Type::BINDATAFUNC, 'string'];
        yield [Type::BINDATAUUID, 'string'];
        yield [Type::BINDATAUUIDRFC4122, 'string'];
        yield [Type::DECIMAL128, 'string'];
        yield [Type::ID, 'string'];
        yield [Type::TIMESTAMP, 'string'];
        yield [Type::INT, 'int'];
        yield [Type::FLOAT, 'float'];
        yield [Type::HASH, 'array'];
        yield [Type::COLLECTION, 'array'];
        yield [Type::OBJECTID, 'array'];
        yield [Type::VECTOR_FLOAT32, 'array'];
        yield [Type::VECTOR_INT8, 'array'];
        yield [Type::VECTOR_PACKED_BIT, 'array'];
        yield [Type::DATE, '\\' . DateTime::class];
        yield [Type::DATE_IMMUTABLE, '\\' . DateTimeImmutable::class];
        yield [Type::UUID, '\\' . Uuid::class];
        yield ['unknown_type', null];
        yield [Type::RAW, null];
        yield ['custom_type', 'string'];
    }

    public function testGetTypeConstant(): void
    {
        $this->assertSame('Type::STRING', MongoDBHelper::getTypeConstant(Type::STRING));
        $this->assertNull(MongoDBHelper::getTypeConstant('unknown_type'));
    }

    #[DataProvider('guessSearchIndexTypeForPropertyDataProvider')]
    public function testGuessSearchIndexTypeForProperty(ReflectionProperty $property, string|null $expected): void
    {
        $this->assertSame($expected, MongoDBHelper::guessSearchIndexTypeForProperty($property));
    }

    public static function guessSearchIndexTypeForPropertyDataProvider(): Generator
    {
        $class = new ReflectionClass(TypeMockClass::class);

        yield 'bool property' => [
            $class->getProperty('bool'),
            'bool',
        ];

        yield 'string property' => [
            $class->getProperty('string'),
            'string',
        ];

        yield 'float property' => [
            $class->getProperty('float'),
            'number',
        ];

        yield 'int property' => [
            $class->getProperty('int'),
            'number',
        ];

        yield 'Uuid property' => [
            $class->getProperty('uuid'),
            'uuid',
        ];

        yield 'ObjectId property' => [
            $class->getProperty('someObjectId'),
            'objectId',
        ];

        yield 'DateTime' => [
            $class->getProperty('dateTime'),
            'date',
        ];

        yield 'DateTimeImmutable property' => [
            $class->getProperty('dateTimeImmutable'),
            'date',
        ];

        yield 'DateTimeInterface property' => [
            $class->getProperty('dateTimeInterface'),
            'date',
        ];

        yield 'array of objects property' => [
            $class->getProperty('arrayOfEmbeddedDocuments'),
            'embeddedDocuments',
        ];

        yield 'embedded document property' => [
            $class->getProperty('embeddedDocument'),
            'document',
        ];

        yield 'nested arrays property' => [
            $class->getProperty('nestedArrays'),
            null,
        ];

        yield 'mixed property' => [
            $class->getProperty('mixed'),
            null,
        ];
    }
}

class CustomType extends Type
{
    public function convertToPHPValue(mixed $value): string
    {
        return 'foo';
    }
}

class TypeMockClass
{
    public bool $bool;
    public string $string;
    public float $float;
    public int $int;
    public Uuid $uuid;
    public ObjectId $someObjectId;
    public DateTime $dateTime;
    public DateTimeImmutable $dateTimeImmutable;
    public DateTimeInterface $dateTimeInterface;

    /** @var string[] */
    public array $arrayOfStrings;

    /** @var object[] */
    #[ODM\EmbedMany]
    public array $arrayOfEmbeddedDocuments;

    #[ODM\EmbedOne]
    public object $embeddedDocument;

    /** @var array<string[]>  */
    public array $nestedArrays;

    public mixed $mixed;
}
