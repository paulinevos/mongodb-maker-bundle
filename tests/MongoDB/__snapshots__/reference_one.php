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

    #[ODM\ReferenceOne(targetDocument: SearchableUser::class, inversedBy: 'articles')]
    public ?SearchableUser $author = null;
}
