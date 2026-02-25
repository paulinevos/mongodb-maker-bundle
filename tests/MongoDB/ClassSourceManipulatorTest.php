<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBMakerBundle\Tests\MongoDB;

use Doctrine\Bundle\MongoDBMakerBundle\MongoDB\ClassSourceManipulator;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\MakerBundle\Util\ClassSource\Model\ClassProperty;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function sprintf;
use function substr_count;

class ClassSourceManipulatorTest extends TestCase
{
    private const string SNAPSHOTS_DIR = __DIR__ . '/__snapshots__';

    public function testAddDocumentField(): void
    {
        $sourceCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

#[ODM\Document]
class TestDocument
{
    #[ODM\Id]
    public string $id;
}

PHP;

        $manipulator = new ClassSourceManipulator($sourceCode);

        $property           = new ClassProperty('title', 'string');
        $property->nullable = false;

        $manipulator->addDocumentField($property);

        $result = $manipulator->getSourceCode();

        $this->assertMatchesSnapshot('field', $result);

        // Verify the property was added
        $this->assertStringContainsString('public string $title;', $result);
        $this->assertStringContainsString('#[ODM\Field', $result);
    }

    public function testAddReferenceOne(): void
    {
        $sourceCode = $this->getBaseDocumentSource();

        $manipulator = new ClassSourceManipulator($sourceCode);
        $manipulator->addReferenceOne('author', 'App\\Document\\SearchableUser', true, ['inversedBy' => 'articles']);

        $result = $manipulator->getSourceCode();

        $this->assertMatchesSnapshot('reference_one', $result);

        // Also verify key elements are present
        $this->assertStringContainsString('#[ODM\ReferenceOne(targetDocument: User::class, inversedBy: \'articles\')]', $result);
        $this->assertStringContainsString('public ?User $author = null;', $result);
    }

    public function testAddReferenceMany(): void
    {
        $sourceCode = $this->getBaseDocumentSource();

        $manipulator = new ClassSourceManipulator($sourceCode);
        $manipulator->addReferenceMany('comments', 'App\\Document\\Comment', [
            'mappedBy' => 'article',
            'orphanRemoval' => true,
        ]);

        $result = $manipulator->getSourceCode();

        $this->assertMatchesSnapshot('reference_many', $result);

        // Verify key elements
        $this->assertStringContainsString('use Doctrine\Common\Collections\ArrayCollection;', $result);
        $this->assertStringContainsString('use Doctrine\Common\Collections\Collection;', $result);
        $this->assertStringContainsString('#[ODM\ReferenceMany(targetDocument: Comment::class', $result);
        $this->assertStringContainsString('orphanRemoval: true', $result);
        $this->assertStringContainsString('private(set) Collection $comments;', $result);
        $this->assertStringContainsString('public function __construct()', $result);
        $this->assertStringContainsString('$this->comments = new ArrayCollection();', $result);
    }

    public function testAddEmbedOne(): void
    {
        $sourceCode = $this->getBaseDocumentSource();

        $manipulator = new ClassSourceManipulator($sourceCode);
        $manipulator->addEmbedOne('metadata', 'App\\Document\\ArticleMetadata', true);

        $result = $manipulator->getSourceCode();

        $this->assertMatchesSnapshot('embed_one', $result);

        // Verify key elements
        $this->assertStringContainsString('#[ODM\EmbedOne(targetDocument: ArticleMetadata::class)]', $result);
        $this->assertStringContainsString('public ?ArticleMetadata $metadata = null;', $result);
    }

    public function testAddEmbedMany(): void
    {
        $sourceCode = $this->getBaseDocumentSource();

        $manipulator = new ClassSourceManipulator($sourceCode);
        $manipulator->addEmbedMany('phoneNumbers', 'App\\Document\\PhoneNumber');

        $result = $manipulator->getSourceCode();

        $this->assertMatchesSnapshot('embed_many', $result);

        // Verify key elements
        $this->assertStringContainsString('use Doctrine\Common\Collections\ArrayCollection;', $result);
        $this->assertStringContainsString('use Doctrine\Common\Collections\Collection;', $result);
        $this->assertStringContainsString('#[ODM\EmbedMany(targetDocument: PhoneNumber::class)]', $result);
        $this->assertStringContainsString('private(set) Collection $phoneNumbers;', $result);
        $this->assertStringContainsString('public function __construct()', $result);
        $this->assertStringContainsString('$this->phoneNumbers = new ArrayCollection();', $result);
    }

    public function testAddMultipleCollectionsInitializesAllInConstructor(): void
    {
        $sourceCode = $this->getBaseDocumentSource();

        $manipulator = new ClassSourceManipulator($sourceCode);

        // Add multiple collection relations
        $manipulator->addReferenceMany('comments', 'App\\Document\\Comment', ['mappedBy' => 'article']);
        $manipulator->addEmbedMany('tags', 'App\\Document\\Tag');
        $manipulator->addReferenceMany('likes', 'App\\Document\\Like', []);

        $result = $manipulator->getSourceCode();

        $this->assertMatchesSnapshot('multiple_collections', $result);

        // Verify all collections are initialized in constructor
        $this->assertStringContainsString('$this->comments = new ArrayCollection();', $result);
        $this->assertStringContainsString('$this->tags = new ArrayCollection();', $result);
        $this->assertStringContainsString('$this->likes = new ArrayCollection();', $result);

        // Verify constructor exists only once
        $this->assertSame(1, substr_count($result, 'public function __construct()'));
    }

    public function testAddReferenceOneWithSelfReferencing(): void
    {
        $sourceCode = $this->getBaseDocumentSource();

        $manipulator = new ClassSourceManipulator($sourceCode);
        $manipulator->addReferenceOne('parent', 'App\\Document\\Article', true, ['inversedBy' => 'children']);

        $result = $manipulator->getSourceCode();

        // Verify self-referencing uses 'self'
        $this->assertStringContainsString('#[ODM\ReferenceOne(targetDocument: self::class, inversedBy: \'children\')]', $result);
        $this->assertStringContainsString('public ?self $parent = null;', $result);
    }

    private function getBaseDocumentSource(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

#[ODM\Document]
class Article
{
    #[ODM\Id]
    public string $id;

    #[ODM\Field(type: 'string')]
    public string $title;
}

PHP;
    }

    /**
     * Assert that generated code matches snapshot
     */
    private function assertMatchesSnapshot(string $snapshotName, string $actual): void
    {
        $snapshotFile = sprintf('%s/%s.php', self::SNAPSHOTS_DIR, $snapshotName);

        if (! file_exists($snapshotFile)) {
            // Create snapshot if it doesn't exist
            file_put_contents($snapshotFile, $actual);
            $this->markTestIncomplete(sprintf('Snapshot created: %s. Run test again to validate.', $snapshotName));
        }

        $expected = file_get_contents($snapshotFile);

        $this->assertSame(
            $expected,
            $actual,
            sprintf(
                "Generated code doesn't match snapshot '%s'.\nTo update snapshot, delete: %s",
                $snapshotName,
                $snapshotFile,
            ),
        );
    }
}
