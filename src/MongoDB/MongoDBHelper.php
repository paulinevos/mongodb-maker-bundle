<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBMakerBundle\MongoDB;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Attribute\EmbedMany;
use Doctrine\ODM\MongoDB\Mapping\Attribute\EmbedOne;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Types\Type;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use MongoDB\BSON\ObjectId;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use Symfony\Bundle\MakerBundle\Str;
use Symfony\Bundle\MakerBundle\Util\ClassNameDetails;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function array_filter;
use function array_first;
use function array_flip;
use function array_keys;
use function array_pop;
use function assert;
use function count;
use function explode;
use function implode;
use function in_array;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;

/** @internal */
final class MongoDBHelper
{
    private const string DEFAULT_DOCUMENT_NAMESPACE = 'App\\Document';

    public function __construct(
        private ManagerRegistry $registry,
    ) {
    }

    public function getRegistry(): ManagerRegistry
    {
        return $this->registry;
    }

    public function getPotentialCollectionName(string $className): string
    {
        $shortClassName = Str::getShortClassName($className);

        return Str::asSnakeCase($shortClassName);
    }

    public function getDocumentNamespace(): string
    {
        $documentManager = $this->registry->getManager();

        if (! $documentManager instanceof DocumentManager) {
            return self::DEFAULT_DOCUMENT_NAMESPACE;
        }

        $metadataDriver     = $documentManager->getConfiguration()->getMetadataDriverImpl();
        $documentNamespaces = [];

        if ($metadataDriver === null) {
            return self::DEFAULT_DOCUMENT_NAMESPACE;
        }

        foreach ($metadataDriver->getAllClassNames() as $className) {
            $parts = explode('\\', $className);
            if (count($parts) <= 1) {
                continue;
            }

            array_pop($parts);
            $documentNamespaces[implode('\\', $parts)] = true;
        }

        $namespaces = array_keys($documentNamespaces);

        // Prefer 'Document' namespace if it exists
        foreach ($namespaces as $namespace) {
            if (str_contains($namespace, '\\Document')) {
                return $namespace;
            }
        }

        return array_first($namespaces) ?? self::DEFAULT_DOCUMENT_NAMESPACE;
    }

    /** @return array<ClassMetadata<object>> */
    public function getMetadata(string|null $classOrNamespace = null, bool $disconnected = false): array
    {
        $metadata = [];

        foreach ($this->registry->getManagers() as $dm) {
            assert($dm instanceof DocumentManager);
            $cmf = $dm->getMetadataFactory();
            assert($cmf instanceof AbstractClassMetadataFactory);

            if ($disconnected) {
                try {
                    $loaded = $cmf->getAllMetadata();
                } catch (Throwable) {
                    $loaded = [];
                }

                // Set the reflection service for disconnected mode
                $cmf->setReflectionService(new StaticReflectionService());

                foreach ($loaded as $classMetadata) {
                    $cmf->setMetadataFor($classMetadata->getName(), $classMetadata);
                }
            }

            foreach ($cmf->getAllMetadata() as $classMetadata) {
                if ($classOrNamespace === null) {
                    $metadata[$classMetadata->getName()] = $classMetadata;
                } else {
                    // Exact match
                    if ($classMetadata->getName() === $classOrNamespace) {
                        return [$classMetadata->getName() => $classMetadata];
                    }

                    if (str_starts_with($classMetadata->getName(), $classOrNamespace)) {
                        $metadata[$classMetadata->getName()] = $classMetadata;
                    }
                }
            }
        }

        return $metadata;
    }

    /** @return string[] */
    public function getDocumentsForAutocomplete(): array
    {
        $documents         = [];
        $documentNamespace = $this->getDocumentNamespace();

        $allMetadata = $this->getMetadata();

        foreach (array_keys($allMetadata) as $classname) {
            $documentClassDetails = new ClassNameDetails($classname, $documentNamespace);
            $documents[]          = $documentClassDetails->getRelativeName();
        }

        sort($documents);

        return $documents;
    }

    public static function canFieldTypeBeInferredByPropertyType(string $fieldType, string $propertyType): bool
    {
        return match ($propertyType) {
            '\\' . DateTime::class => $fieldType === Type::DATE,
            '\\' . DateTimeImmutable::class => $fieldType === Type::DATE_IMMUTABLE,
            Uuid::class => $fieldType === Type::UUID,
            'array' => $fieldType === Type::HASH,
            'bool' => $fieldType === Type::BOOL,
            'float' => $fieldType === Type::FLOAT,
            'int' => $fieldType === Type::INT,
            'string' => $fieldType === Type::STRING,
            default => false,
        };
    }

    /** @throws ReflectionException */
    public static function getPropertyTypeForField(string $fieldType): string|null
    {
        $propertyType = match ($fieldType) {
            Type::STRING,
            Type::BINDATA,
            Type::BINDATABYTEARRAY,
            Type::BINDATACUSTOM,
            Type::BINDATAFUNC,
            Type::BINDATAMD5,
            Type::BINDATAUUID,
            Type::BINDATAUUIDRFC4122,
            Type::DECIMAL128,
            Type::ID,
            Type::TIMESTAMP => 'string',
            Type::INT => 'int',
            Type::FLOAT => 'float',
            Type::BOOL => 'bool',
            Type::HASH,
            Type::COLLECTION,
            Type::OBJECTID,
            Type::VECTOR_FLOAT32,
            Type::VECTOR_INT8,
            Type::VECTOR_PACKED_BIT => 'array',
            Type::DATE => '\\' . DateTime::class,
            Type::DATE_IMMUTABLE => '\\' . DateTimeImmutable::class,
            Type::UUID => '\\' . Uuid::class,
            default => null,
        };

        $typesMap = Type::getTypesMap();
        if ($propertyType !== null || ! isset($typesMap[$fieldType])) {
            return $propertyType;
        }

        $reflection = new ReflectionClass($typesMap[$fieldType]);
        $returnType = $reflection->getMethod('convertToPHPValue')->getReturnType();

        /*
         * we do not support union and intersection types
         */
        if (! $returnType instanceof ReflectionNamedType) {
            return null;
        }

        return $returnType->isBuiltin() ? $returnType->getName() : '\\' . $returnType->getName();
    }

    /**
     * Given the string "field type", this returns the "Types::STRING" constant.
     *
     * This is, effectively, a reverse lookup: given the final string, give us
     * the constant to be used in the generated code.
     */
    public static function getTypeConstant(string $fieldType): string|null
    {
        $reflection = new ReflectionClass(Type::class);
        $constants  = array_flip($reflection->getConstants());

        if (! isset($constants[$fieldType])) {
            return null;
        }

        return sprintf('Type::%s', $constants[$fieldType]);
    }

    /**
     * Given a property, make a best-effort guess for its search index type.
     */
    public static function guessSearchIndexTypeForProperty(ReflectionProperty $property): string|null
    {
        if (! $property->hasType()) {
            return null;
        }

        $type = $property->getType();
        assert($type instanceof ReflectionNamedType);
        $typeString = (string) $type;

        if ($type->isBuiltin()) {
            if ($typeString === 'array' && self::hasEmbeddingAttribute($property)) {
                return 'embeddedDocuments';
            }

            return self::deriveSearchIndexTypeFromNativeType($typeString);
        }

        if ($typeString === Uuid::class) {
            return 'uuid';
        }

        if ($typeString === ObjectId::class) {
            return 'objectId';
        }

        if (in_array($typeString, [DateTime::class, DateTimeImmutable::class, DateTimeInterface::class])) {
            return 'date';
        }

        if (self::hasEmbeddingAttribute($property)) {
            return 'document';
        }

        return null;
    }

    private static function deriveSearchIndexTypeFromNativeType(string $type): string|null
    {
        return match ($type) {
            'bool' => 'bool',
            'float', 'int' => 'number',
            'string' => 'string',
            'object' => 'document',
            default => null,
        };
    }

    private static function hasEmbeddingAttribute(ReflectionProperty $property): bool
    {
        return ! empty(array_filter(
            $property->getAttributes(),
            static fn ($attr) => in_array($attr->getName(), [EmbedMany::class, EmbedOne::class]),
        ));
    }
}
