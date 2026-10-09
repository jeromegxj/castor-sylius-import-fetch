<?php

declare(strict_types=1);

namespace Castor\Attribute;

#[\Attribute]
final class AsTask
{
    public function __construct(
        public string $name = '',
        public string $namespace = '',
        public string $description = '',
        public array $aliases = [],
    ) {}
}

#[\Attribute]
final class AsArgument {}

#[\Attribute]
final class AsOption {}

namespace Castor\Api\Attribute;

#[\Attribute]
final class AsApi
{
    public function __construct(
        public bool $async = false,
    ) {}
}

require dirname(__DIR__) . '/vendor/autoload.php';
