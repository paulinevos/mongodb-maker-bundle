<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBMakerBundle\Tests\Maker;

use Doctrine\Bundle\MongoDBMakerBundle\Maker\MakeIndex;
use Generator;
use Symfony\Bundle\MakerBundle\Test\MakerTestCase;
use Symfony\Bundle\MakerBundle\Test\MakerTestDetails;
use Symfony\Bundle\MakerBundle\Test\MakerTestRunner;
use Symfony\Component\HttpKernel\KernelInterface;

use function getenv;
use function sprintf;

class MakeIndexTest extends MakerTestCase
{
    protected function getMakerClass(): string
    {
        return MakeIndex::class;
    }

    private static function createMakeIndexTest(bool $withDatabase = true): MakerTestDetails
    {
        return self::buildMakerTest()
            ->preRun(static function (MakerTestRunner $runner) use ($withDatabase): void {
                $runner->runConsole('doctrine:mongodb:schema:update', ['--force' => true]);

                if (! $withDatabase) {
                    return;
                }

                $runner->replaceInFile(
                    '.env',
                    'mongodb://localhost:27017',
                    (string) getenv('MONGODB_URI'),
                );

                $runner->copy(
                    sprintf('make-index/documents/User.php'),
                    sprintf('src/Document/User.php'),
                );

                $runner->copy(
                    sprintf('make-index/documents/SearchableUser.php'),
                    sprintf('src/Document/SearchableUser.php'),
                );

                $runner->copy(
                    sprintf('utils/MongoDBFunctionalTestCase.php'),
                    sprintf('src/MongoDBFunctionalTestCase.php'),
                );
            });
    }

    protected function createKernel(): KernelInterface
    {
        return new MakerTestKernel('dev', true);
    }

    public static function getTestDetails(): Generator
    {
        yield 'it adds an index to a property' => [
            self::createMakeIndexTest()
                ->run(static function (MakerTestRunner $runner): void {
                    $runner->runMaker([
                        // document class name
                        'User',
                        // should be a regular index
                        '',
                        // add an index on `lastName`
                        '1',
                        // `asc` order for `lastName`
                        '',
                        // no additional indexes
                        '',
                    ]);

                    self::runIndexTest($runner, [
                        [
                            'unique' => false,
                            'keys' => ['lastName' => 1],
                        ],
                    ]);
                }),
        ];

        yield 'it adds two indexes' => [
            self::createMakeIndexTest()
                ->run(static function (MakerTestRunner $runner): void {
                    $runner->runMaker([
                        // document class name
                        'User',
                        // should be a regular index
                        '',
                        // add an index on `lastName`
                        '1',
                        // `asc` order for `lastName`
                        '',
                        // create another index
                        'y',
                        // index should be unique
                        'Unique',
                        // add an index on `firstName`
                        '0',
                        // `desc` order for `firstName`
                        'desc',
                    ]);

                    self::runIndexTest($runner, [
                        [
                            'unique' => true,
                            'keys' => ['firstName' => -1],
                        ],
                        [
                            'unique' => false,
                            'keys' => ['lastName' => 1],
                        ],
                    ]);
                }),
        ];

        yield 'it adds an index with multiple fields' => [
            self::createMakeIndexTest()
                ->run(static function (MakerTestRunner $runner): void {
                    $runner->runMaker([
                        // document class name
                        'User',
                        // should be a regular index
                        '',
                        // select `firstName` and `lastName` as keys for the index
                        '0,1',
                        // `asc` order for `firstName`
                        '',
                        // `desc` order for `lastName`
                        'desc',
                        // no additional indexes
                        '',
                    ]);

                    self::runIndexTest($runner, [
                        [
                            'unique' => false,
                            'keys' => ['firstName' => 1, 'lastName' => -1],
                        ],
                    ]);
                }),
        ];

        yield 'it creates search indexes' => [
            self::createMakeIndexTest()
                ->run(static function (MakerTestRunner $runner): void {
                    $runner->runMaker([
                        // document class name
                        'SearchableUser',
                        // should be a search index
                        'Search',
                        // Default index name
                        '',
                        // should be statically mapped
                        'n',
                        'hobbies,friends',
                    ]);

                    self::runSearchIndexTest($runner, [
                        'default' => [
                            'fields' => ['hobbies' => [], 'friends' => ['type' => 'embeddedDocuments']],
                        ],
                    ]);
                }),
        ];
    }

    /** @param array<string, mixed> $data */
    private static function runIndexTest(MakerTestRunner $runner, array $data = []): void
    {
        $runner->renderTemplateFile(
            'make-index/GeneratedIndexesTest.php.twig',
            'tests/GeneratedIndexesTest.php',
            ['data' => $data],
        );

        $runner->runTests();
    }

    private static function runSearchIndexTest(MakerTestRunner $runner, array $data = []): void
    {
        $runner->renderTemplateFile(
            'make-index/GeneratedSearchIndexesTest.php.twig',
            'tests/GeneratedSearchIndexesTest.php',
            ['data' => $data],
        );

        $runner->runTests();
    }
}
