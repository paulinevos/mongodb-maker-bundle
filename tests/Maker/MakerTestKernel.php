<?php

declare(strict_types=1);

namespace Doctrine\Bundle\MongoDBMakerBundle\Tests\Maker;

use Doctrine\Bundle\MongoDBBundle\DoctrineMongoDBBundle;
use Doctrine\Bundle\MongoDBMakerBundle\MongoDBMakerBundle;
use Symfony\Bundle\MakerBundle\Test\MakerTestKernel as MakerBundleTestKernel;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function getenv;

class MakerTestKernel extends MakerBundleTestKernel
{
    public function registerBundles(): iterable
    {
        yield from parent::registerBundles();
        yield new DoctrineMongoDBBundle();
        yield new MongoDBMakerBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);

        $loader->load(static function (ContainerBuilder $container): void {
            $uri = getenv('MONGODB_URI') ?: 'mongodb://localhost:27017';
            $container->loadFromExtension('doctrine_mongodb', [
                'default_connection' => 'default',
                'connections' => [
                    'default' => ['server' => $uri],
                ],
                'document_managers' => [
                    'default' => ['auto_mapping' => true],
                ],
            ]);
        });
    }
}
