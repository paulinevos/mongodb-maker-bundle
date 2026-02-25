<?php

declare(strict_types=1);

namespace App\Document;

use Doctrine\ODM\MongoDB\Mapping\Attribute as ODM;

#[ODM\Document]
class SearchableUser
{
    #[ODM\Field]
    public ?string $firstName = null;

    #[ODM\Id]
    public ?int $id = null;

    #[ODM\Field]
    public ?string $lastName = null;

    /** @var string[] */
    public array $hobbies;

    #[ODM\EmbedMany(targetDocument: self::class)]
    public array $friends;
}
