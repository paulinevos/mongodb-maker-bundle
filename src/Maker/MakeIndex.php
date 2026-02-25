<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBMakerBundle\Maker;

use Doctrine\Bundle\MongoDBBundle\DoctrineMongoDBBundle;
use Doctrine\Bundle\MongoDBMakerBundle\Maker\Enum\IndexType;
use Doctrine\Bundle\MongoDBMakerBundle\MongoDB\ClassSourceManipulator;
use Doctrine\Bundle\MongoDBMakerBundle\MongoDB\MongoDBHelper;
use Doctrine\ODM\MongoDB\Mapping\Attribute\Id;
use Doctrine\ODM\MongoDB\Mapping\Attribute\SearchIndex;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Bundle\MakerBundle\Maker\AbstractMaker;
use Symfony\Bundle\MakerBundle\MakerInterface;
use Symfony\Bundle\MakerBundle\Util\ClassDetails;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Throwable;

use function array_filter;
use function array_key_first;
use function array_map;
use function array_values;
use function class_exists;
use function count;
use function dirname;
use function file_get_contents;
use function in_array;
use function preg_match;
use function reset;
use function sprintf;
use function strtolower;

final class MakeIndex extends AbstractMaker implements MakerInterface
{
    private const string ARG_DOCUMENT = 'document';

    public function __construct(
        private readonly FileManager $fileManager,
        private readonly MongoDBHelper $mongoDBHelper,
    ) {
    }

    public static function getCommandName(): string
    {
        return 'make:document:index';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addArgument(self::ARG_DOCUMENT, InputArgument::OPTIONAL, 'The document class to create the index for')
            ->setHelp((string) file_get_contents(dirname(__DIR__, 2) . '/config/help/MakeIndex.txt'));

        $inputConfig->setArgumentAsNonInteractive(self::ARG_DOCUMENT);
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
        $dependencies->addClassDependency(
            DoctrineMongoDBBundle::class,
            'doctrine/mongodb-odm-bundle',
        );
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        $documentClassName = $input->getArgument(self::ARG_DOCUMENT);
        if ($documentClassName && empty($this->verifyDocumentName($documentClassName))) {
            return;
        }

        $argument = $command->getDefinition()->getArgument(self::ARG_DOCUMENT);
        $question = new ChoiceQuestion(
            $argument->getDescription(),
            $this->mongoDBHelper->getDocumentsForAutocomplete(),
        );

        $documentClassName = $io->askQuestion($question);

        $input->setArgument(self::ARG_DOCUMENT, $documentClassName);
    }

    /** @throws Exception */
    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $documentName = $input->getArgument(self::ARG_DOCUMENT);

        $documentClassDetails = $generator->createClassNameDetails(
            $documentName,
            'Document\\',
        );

        if (! class_exists($documentClassDetails->getFullName())) {
            throw new Exception(sprintf(
                'Document "%s" does not exist. You can generate it by running %s %s',
                $documentName,
                MakeDocument::getCommandName(),
                $documentName,
            ));
        }

        $documentClass   = $documentClassDetails->getFullName();
        $documentPath    = new ClassDetails($documentClass)->getPath();
        $reflectionClass = new ReflectionClass($documentClass);

        /** Filter any identifiers. We don't consider them valid candidates for `MakeIndex` */
        $fields = array_values(array_filter(array_map(static function (ReflectionProperty $prop) {
            $isId = ! empty($prop->getAttributes(Id::class));

            return $isId ? null : $prop->getName();
        }, $reflectionClass->getProperties())));

        if (empty($fields)) {
            throw new Exception(sprintf(
                'Document "%s" does not have any fields that can be indexed. Please add some fields before running this command.',
                $documentName,
            ));
        }

        $manipulator = new ClassSourceManipulator(
            sourceCode: $this->fileManager->getFileContents($documentPath),
        );

        $this->addIndexRecursive($io, $manipulator, $reflectionClass->getProperties(), $documentPath);
    }

    /** @return string[] */
    private function verifyDocumentName(string $documentName): array
    {
        preg_match('/([^\x00-\x7F]+)/u', $documentName, $matches);

        return $matches;
    }

    /**
     * @param ReflectionProperty[] $fields
     *
     * @throws Exception
     */
    private function addIndexRecursive(ConsoleStyle $io, ClassSourceManipulator $manipulator, array $fields, string $documentPath): void
    {
        $io->writeln('');

        $question = new ChoiceQuestion(
            'What type of index do you want to create?',
            IndexType::stringValues(),
            IndexType::REGULAR->value,
        );
        $question->setValidator(static function ($value) {
            try {
                return IndexType::fromInput($value);
            } catch (Throwable) {
                throw new InvalidArgumentException('Please select a valid index type.');
            }
        });

        $type = $io->askQuestion($question);

        $this->createAttribute($io, $type, $fields, $manipulator);
        $this->fileManager->dumpFile($documentPath, $manipulator->getSourceCode());

        $createAnother = $io->ask('Would you like to add another index to this document? (y/n)', 'n', static function ($answer) {
            if (! in_array(strtolower($answer), ['y', 'n'], true)) {
                throw new InvalidArgumentException('Please enter "y" or "n".');
            }

            return strtolower($answer) === 'y';
        });

        if (! $createAnother) {
            $this->writeSuccessMessage($io);

            $io->writeln(
                'Remember to run "php bin/console doctrine:mongodb:schema:update" to create your indexes in the database.',
            );

            return;
        }

        $this->addIndexRecursive($io, $manipulator, $fields, $documentPath);
    }

    /**
     * @param ReflectionProperty[] $fields
     *
     * @throws Exception
     */
    private function createAttribute(ConsoleStyle $io, IndexType $type, array $fields, ClassSourceManipulator $manipulator): void
    {
        switch ($type) {
            case IndexType::REGULAR:
                $this->createIndexAttribute('ODM\Index', $io, $fields, $manipulator);
                break;
            case IndexType::UNIQUE:
                $this->createIndexAttribute('ODM\UniqueIndex', $io, $fields, $manipulator);
                break;
            case IndexType::SEARCH:
                $this->createSearchIndexAttribute($io, $manipulator, $fields);
        }
    }

    /**
     * @param ReflectionProperty[] $fields
     *
     * @throws Exception
     */
    private function createIndexAttribute(string $attributeClass, ConsoleStyle $io, array $fields, ClassSourceManipulator $manipulator): void
    {
        $fields = self::filterIdentifiers($fields);
        $keys = $this->askForKeys($io, $fields);

        $createClassAttribute = count($keys) > 1;

        if ($createClassAttribute) {
            $manipulator->addAttributeToClass($attributeClass, ['keys' => $keys]);

            return;
        }

        $field = array_key_first($keys);
        $manipulator->addAttributeToProperty($attributeClass, (string) $field, ['order' => $keys[$field]]);

        $io->info('Index added.');
    }

    /** @param ReflectionProperty[] $fields */
    private function createSearchIndexAttribute(
        ConsoleStyle $io,
        ClassSourceManipulator $manipulator,
        array $fields,
    ): void {
        $name = $io->ask('What is the name of the index?', 'default');

        $isDynamic = $io->confirm('Is the search index dynamic?');

        if ($isDynamic) {
            $manipulator->addAttributeToClass(SearchIndex::class, ['dynamic' => true, 'name' => $name]);
        } else {
            $selectedFields = self::selectFields($fields, $io);

            $options = [];
            foreach ($selectedFields as $field) {
                $property = array_filter($fields, static fn (ReflectionProperty $prop) => $prop->name === $field);
                $property = reset($property);

                if (! $property) {
                    throw new RuntimeException(sprintf('Field "%s" not found.', $field));
                }

                $type = MongoDBHelper::guessSearchIndexTypeForProperty($property);

                $options[$field] = $type ? ['type' => $type] : [];
            }

            $manipulator->addAttributeToClass(SearchIndex::class, ['fields' => $options, 'name' => $name]);
        }

        $io->info(sprintf(
            'Search index added.%s',
            $isDynamic ? '' : ' Remember to review the generated field mappings in the `SearchIndex` attribute and adjust them as needed.',
        ));
    }

    /**
     * @param ReflectionProperty[] $properties
     *
     * @return string[]
     */
    private static function getPropertyNames(array $properties): array
    {
        return array_map(static fn (ReflectionProperty $prop) => $prop->name, $properties);
    }

    /**
     * @param ReflectionProperty[] $fields
     *
     * @return array<string,string> $keys
     */
    private function askForKeys(ConsoleStyle $io, array $fields): array
    {
        $selection = self::selectFields($fields, $io);
        $keys = [];

        foreach ($selection as $key) {
            $orderQuestion = new ChoiceQuestion(
                sprintf('Provide the order for key "%s"', $key),
                ['asc', 'desc'],
                'asc',
            );

            $keys[$key] = $io->askQuestion($orderQuestion);
        }

        return $keys;
    }

    /**
     * @param ReflectionProperty[] $properties
     *
     * @return ReflectionProperty[]
     */
    private static function filterIdentifiers(array $properties): array
    {
        /** Filter any identifiers. We don't consider them valid candidates for regular and unique indexes */
        return array_values(array_filter(
            $properties,
            static fn (ReflectionProperty $prop) => empty($prop->getAttributes(Id::class)),
        ));
    }

    /**
     * @param ReflectionProperty[] $fields
     *
     * @return string[]
     */
    private static function selectFields(array $fields, ConsoleStyle $io): array
    {
        $options = self::getPropertyNames($fields);
        $question = new ChoiceQuestion('Select one or more fields for the index (or <return> to finish)', $options);
        $question->setMultiselect(true);

        return $io->askQuestion($question);
    }
}
