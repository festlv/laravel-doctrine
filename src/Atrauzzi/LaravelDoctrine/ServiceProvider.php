<?php namespace Atrauzzi\LaravelDoctrine;

use Illuminate\Support\ServiceProvider as Base;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManager;


use Knp\DoctrineBehaviors\Reflection\ClassAnalyzer;

class ServiceProvider extends Base {

	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = false;

	/**
	 * Bootstrap the application events.
	 *
	 * @return void
	 */
	public function boot() {
		$this->package('atrauzzi/laravel-doctrine');
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register() {
		$this->package('atrauzzi/laravel-doctrine');

		//
		// Doctrine
		//
		$this->app->singleton('Doctrine\ORM\EntityManager', function ($app) {

			// Retrieve our configuration.
			$config = $app['config'];
            
			$connection = $this->getDatabaseConfig($config);

			$devMode = $config->get('app.debug');

			$cache = null; // Default, let Doctrine decide.

			if(!$devMode) {

				$cache_config = $config->get('laravel-doctrine::doctrine.cache');
				$cache_provider = $cache_config['provider'];
				$cache_provider_config = $cache_config[$cache_provider];

				switch($cache_provider) {

					case 'apc':
						if(extension_loaded('apc')) {
							$cache = new \Doctrine\Common\Cache\ApcCache();
						}
					break;

					case 'xcache':
						if(extension_loaded('xcache')) {
							$cache = new \Doctrine\Common\Cache\XcacheCache();
						}
					break;

					case 'memcache':
						if(extension_loaded('memcache')) {
							$memcache = new \Memcache();
							$memcache->connect($cache_provider_config['host'], $cache_provider_config['port']);
							$cache = new \Doctrine\Common\Cache\MemcacheCache();
							$cache->setMemcache($memcache);
						}
					break;

					case 'redis':
						if(extension_loaded('redis')) {
							$redis = new \Redis();
							$redis->connect($cache_provider_config['host'], $cache_provider_config['port']);

							if ($cache_provider_config['database']) {
								$redis->select($cache_provider_config['database']);
							}

							$cache = new \Doctrine\Common\Cache\RedisCache();
							$cache->setRedis($redis);
						}
					break;

				}

			} else {
                $cache = new \Doctrine\Common\Cache\ArrayCache();
            }
            \Doctrine\Common\Annotations\AnnotationRegistry::registerFile(
                base_path().'/vendor/doctrine/orm/lib/Doctrine/ORM/Mapping/Driver/DoctrineAnnotations.php'
            );        
            
            $annotationReader = new \Doctrine\Common\Annotations\AnnotationReader;
            $cachedAnnotationReader = new \Doctrine\Common\Annotations\CachedReader(
                $annotationReader, // use reader
                $cache // and a cache driver
            );
            // create a driver chain for metadata reading
            $driverChain = new \Doctrine\ORM\Mapping\Driver\DriverChain();

            // now we want to register our application entities,
            // for that we need another metadata driver used for Entity namespace
            $annotationDriver = new \Doctrine\ORM\Mapping\Driver\AnnotationDriver(
                $cachedAnnotationReader, // our cached annotation reader
                $config->get('laravel-doctrine::doctrine.metadata') // paths to look in
            );
            // NOTE: driver for application Entity can be different, Yaml, Xml or whatever
            // register annotation driver for our application Entity namespace
            $driverChain->addDriver($annotationDriver, "Entity");

            // general ORM configuration
            $doctrine_config = new \Doctrine\ORM\Configuration;
            // register metadata driver
            $doctrine_config->setMetadataDriverImpl($driverChain);
            // use our already initialized cache driver
            $doctrine_config->setMetadataCacheImpl($cache);
            $doctrine_config->setQueryCacheImpl($cache);

            // create event manager and hook preferred extension listeners
            $evm = new \Doctrine\Common\EventManager();
            $doctrine_config->setAutoGenerateProxyClasses(
				$config->get('laravel-doctrine::doctrine.proxy_classes.auto_generate')
            );
            $evm->addEventSubscriber(new \Knp\DoctrineBehaviors\ORM\Translatable\TranslatableListener(
                new ClassAnalyzer(),
                false,
                function()
                {
                    return \App::getLocale();
                },
                'Knp\DoctrineBehaviors\Model\Translatable\Translatable',
                'Knp\DoctrineBehaviors\Model\Translatable\Translation',
                'LAZY',
                'LAZY'
            ));
            $evm->addEventSubscriber(
                new \Knp\DoctrineBehaviors\ORM\Timestampable\TimestampableListener(
                    new ClassAnalyzer(),
                    true,
                    'Knp\DoctrineBehaviors\Model\Timestampable\Timestampable'
                ));
            $evm->addEventSubscriber(
                new \Knp\DoctrineBehaviors\ORM\SoftDeletable\SoftDeletableListener(
                    new ClassAnalyzer(),
                    true,
                    'Knp\DoctrineBehaviors\Model\SoftDeletable\SoftDeletable'
                ));
            $doctrine_config->setDefaultRepositoryClassName($config->get('laravel-doctrine::doctrine.defaultRepository'));
            
            if (\Debugbar::hasCollector('queries'))
                $doctrine_config->setSQLLogger($config->get('laravel-doctrine::doctrine.sqlLogger'));

			$proxy_class_namespace = $config->get('laravel-doctrine::doctrine.proxy_classes.namespace');
			if ($proxy_class_namespace !== null) {
				$doctrine_config->setProxyNamespace($proxy_class_namespace);
			}
            $doctrine_config->setProxyDir($config->get('laravel-doctrine::doctrine.proxy_classes.directory'));

			if (isset($connection['prefix']) && !empty($connection['prefix'])) {
				$evm->addEventListener(Events::loadClassMetadata, new Listener\Metadata\TablePrefix($connection['prefix']));
			}

			// Obtain an EntityManager from Doctrine.
			return EntityManager::create($connection, $doctrine_config, $evm);

		});

        $this->app->singleton('Doctrine\ORM\Tools\SchemaTool', function ($app) {
            return new SchemaTool($app['Doctrine\ORM\EntityManager']);
        });

        //
		// Utilities
		//

		$this->app->singleton('Doctrine\ORM\Mapping\ClassMetadataFactory', function ($app) {
			return $app['Doctrine\ORM\EntityManager']->getMetadataFactory();
		});

    $this->app->singleton('doctrine.registry', function ($app) {
      $connections = array('doctrine.connection');
      $managers = array('doctrine' => 'doctrine');
      $proxy = 'Doctrine\Common\Persistence\Proxy';
      return new DoctrineRegistry('doctrine', $connections, $managers, $connections[0], $managers['doctrine'], $proxy);
    });

		//
		// String name re-bindings.
		//

		$this->app->singleton('doctrine', function ($app) {
			return $app['Doctrine\ORM\EntityManager'];
		});

		$this->app->singleton('doctrine.metadata-factory', function ($app) {
			return $app['Doctrine\ORM\Mapping\ClassMetadataFactory'];
		});
		
		$this->app->singleton('doctrine.metadata', function($app) {
			return $app['doctrine.metadata-factory']->getAllMetadata();
		});
		
		// After binding EntityManager, the DIC can inject this via the constructor type hint!
		$this->app->singleton('doctrine.schema-tool', function ($app) {
			return $app['Doctrine\ORM\Tools\SchemaTool'];
		});

    // Registering the doctrine connection to the IoC container.
    $this->app->singleton('doctrine.connection', function ($app) {
      return $app['doctrine']->getConnection();
    });

		//
		// Commands
		//
		$this->commands(
			array('Atrauzzi\LaravelDoctrine\Console\CreateSchemaCommand',
			'Atrauzzi\LaravelDoctrine\Console\UpdateSchemaCommand',
			'Atrauzzi\LaravelDoctrine\Console\DropSchemaCommand')
		);

	}

	/**
	 * Get the services provided by the provider.
	 *
	 * @return array
	 */
	public function provides() {
    return array(
      'doctrine',
      'Doctrine\ORM\EntityManager',
      'doctrine.metadata-factory',
      'Doctrine\ORM\Mapping\ClassMetadataFactory',
      'doctrine.metadata',
      'doctrine.schema-tool',
      'Doctrine\ORM\Tools\SchemaTool',
      'doctrine.registry'
    );
	}

    /**
     * Map Laravel's to Doctrine's database config
     *
     * @param $config
     * @return array
     */
    private function getDatabaseConfig($config)
    {
        return [
            'pdo' => \DB::connection()->getPdo()
        ];
    }
}
