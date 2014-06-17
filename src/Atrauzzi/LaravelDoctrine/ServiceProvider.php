<?php namespace Atrauzzi\LaravelDoctrine;

use Illuminate\Support\ServiceProvider as Base;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Tools\Setup;
use Doctrine\ORM\Events;
use Doctrine\ORM\EntityManager;


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
            
            //Gedmo doctrine extensions
            $annotationReader = new \Doctrine\Common\Annotations\AnnotationReader;
            $cachedAnnotationReader = new \Doctrine\Common\Annotations\CachedReader(
                $annotationReader, // use reader
                $cache // and a cache driver
            );
            // create a driver chain for metadata reading
            $driverChain = new \Doctrine\ORM\Mapping\Driver\DriverChain();
            // load superclass metadata mapping only, into driver chain
            // also registers Gedmo annotations.NOTE: you can personalize it
            \Gedmo\DoctrineExtensions::registerAbstractMappingIntoDriverChainORM(
                $driverChain, // our metadata driver chain, to hook into
                $cachedAnnotationReader // our cached annotation reader
            );

            // now we want to register our application entities,
            // for that we need another metadata driver used for Entity namespace
            $annotationDriver = new \Doctrine\ORM\Mapping\Driver\AnnotationDriver(
                $cachedAnnotationReader, // our cached annotation reader
                $config->get('laravel-doctrine::doctrine.metadata') // paths to look in
            );
            // NOTE: driver for application Entity can be different, Yaml, Xml or whatever
            // register annotation driver for our application Entity namespace
            $driverChain->addDriver($annotationDriver, 'Entity');

            // general ORM configuration
            $doctrine_config = new \Doctrine\ORM\Configuration;
            // register metadata driver
            $doctrine_config->setMetadataDriverImpl($driverChain);
            // use our already initialized cache driver
            $doctrine_config->setMetadataCacheImpl($cache);
            $doctrine_config->setQueryCacheImpl($cache);

            // create event manager and hook preferred extension listeners
            $evm = new \Doctrine\Common\EventManager();
            // gedmo extension listeners, remove which are not used
            /*
            // sluggable
            $sluggableListener = new Gedmo\Sluggable\SluggableListener;
            // you should set the used annotation reader to listener, to avoid creating new one for mapping drivers
            $sluggableListener->setAnnotationReader($cachedAnnotationReader);
            $evm->addEventSubscriber($sluggableListener);


            // tree
            $treeListener = new Gedmo\Tree\TreeListener;
            $treeListener->setAnnotationReader($cachedAnnotationReader);
            $evm->addEventSubscriber($treeListener);
            // loggable, not used in example
            $loggableListener = new Gedmo\Loggable\LoggableListener;
            $loggableListener->setAnnotationReader($cachedAnnotationReader);
            $evm->addEventSubscriber($loggableListener);

            // sortable, not used in example
            $sortableListener = new Gedmo\Sortable\SortableListener;
            $sortableListener->setAnnotationReader($cachedAnnotationReader);
            $evm->addEventSubscriber($sortableListener);

            */

            // timestampable
            $timestampableListener = new \Gedmo\Timestampable\TimestampableListener;
            $timestampableListener->setAnnotationReader($cachedAnnotationReader);
            $evm->addEventSubscriber($timestampableListener);

            // translatable
            $translatableListener = new \Gedmo\Translatable\TranslatableListener;
            // current translation locale should be set from session or hook later into the listener
            // most important, before entity manager is flushed
            $translatableListener->setTranslatableLocale($app->getLocale());
            $translatableListener->setDefaultLocale($config->get('app.locale'));

            $translatableListener->setAnnotationReader($cachedAnnotationReader);
            $evm->addEventSubscriber($translatableListener);


			$doctrine_config->setAutoGenerateProxyClasses(
				$config->get('laravel-doctrine::doctrine.proxy_classes.auto_generate')
			);

            $doctrine_config->setDefaultRepositoryClassName($config->get('laravel-doctrine::doctrine.defaultRepository'));

            $doctrine_config->setSQLLogger($config->get('laravel-doctrine::doctrine.sqlLogger'));

			$proxy_class_namespace = $config->get('laravel-doctrine::doctrine.proxy_classes.namespace');
			if ($proxy_class_namespace !== null) {
				$doctrine_config->setProxyNamespace($proxy_class_namespace);
			}
            $doctrine_config->setProxyDir('laravel-doctrine::doctrine.proxy_classes.directory');

			// Trap doctrine events, to support entity table prefix
			$evm = new EventManager();

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
        $default = $config['database.default'];
        $database = $config["database.connections.{$default}"];
        return [
            'driver' => 'pdo_'.$database['driver'],
            'host' => $database['host'],
            'dbname' => $database['database'],
            'user' => $database['username'],
            'password' => $database['password'],
            'prefix' => $database['prefix'],
            'charset' => $database['charset'],
            'port' => $database['port']
        ];
    }
}
