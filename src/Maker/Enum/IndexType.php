<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBMakerBundle\Maker\Enum;

use InvalidArgumentException;

use function array_map;
use function is_numeric;
use function sprintf;

enum IndexType: string
{
    case REGULAR = 'Regular';
    case UNIQUE = 'Unique';
    case SEARCH = 'Search';

    /** @return string[] */
    public static function stringValues(): array
    {
        return array_map(static fn ($case) => $case->value, self::cases());
    }

    public static function fromInput(string $input): self
    {
        if (is_numeric($input)) {
            $type = self::cases()[(int) $input] ?? null;
            if ($type === null) {
                throw new InvalidArgumentException(sprintf('Invalid input for index: %s', $input));
            }

            return $type;
        }

        return self::from($input);
    }
}
