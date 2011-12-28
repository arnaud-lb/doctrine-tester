<?php

namespace Doctrine\Common\Tester;

use Symfony\Component\ClassLoader\UniversalClassLoader;

use Doctrine\ORM\Mapping\Driver\XmlDriver;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\DriverChain;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\SchemaTool;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\IndexedReader;
use Doctrine\Common\Annotations\CachedReader;

use Doctrine\Common\Annotations\AnnotationRegistry;

use Doctrine\DBAL\Types\Type;

class Tester
{    
    protected $annotationMapping = array();
    
    protected $xmlMapping = array();

    protected $em;
    
    protected $entitiesName = array();
    
    protected $fixtures = array();
    
    protected $fixtureManager = array();
    
    protected $dbalTypes = array();
    
    protected $basepathes = array();
    
    protected $connectionParams = array();
    
    public function __construct()
    {
        //assume the lib in vendor/doctrine-tester dir
        $this->registerBasepath($srcPath = __DIR__ . '/../../');
        
        $this->useSqlite();
    }
    
    public function registerBasepath($path)
    {
        $this->basepathes[] = realpath($path);
        
        return $this;
    }
    
    public function registerFixtures(array $fixtures)
    {
        $this->fixtures = $fixtures;
        
        return $this;
    }
    
    public function registerDBALType($name, $class)
    {
        $this->dbalTypes[$name] = $class;
        
        return $this;
    }
    
    public function registerEntities(array $entitiesName)
    {
        $this->entitiesName = $entitiesName;
        
        return $this;
    }
    
    public function registerAnnotationMapping($path, $namespace)
    {
        $this->annotationMapping[$namespace] = $path;
        
        return $this;
    }
    
    public function registerXmlMapping($path, $namespace)
    {
        $this->xmlMapping[$namespace] = $path;
        
        return $this;
    }
    
    /**
     *
     * @return \Doctrine\ORM\EntityManager
     */
    public function em()
    {
        if (false == $this->em) {
            $this->em = $this->initEm();
        }
        
        return $this->em;
    }
    
    protected function initEm()
    {
        $conf = new Configuration();
        $conf->setAutoGenerateProxyClasses(true);
        $conf->setProxyDir(\sys_get_temp_dir());
        $conf->setProxyNamespace('Proxies');
        $conf->setMetadataDriverImpl($this->initMetadataDriverImpl());
        $conf->setQueryCacheImpl(new ArrayCache());
        $conf->setMetadataCacheImpl(new ArrayCache());
        
        foreach ($this->dbalTypes as $name => $class) {
            if (false == Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }
        
        return EntityManager::create($this->connectionParams, $conf);
    }
    
    public function useSqlite()
    {
        $this->connectionParams = array('driver' => 'pdo_sqlite', 'path' => ':memory:');
        
        return $this;
    }
    
    public function useEm(EntityManager $em)
    {
        $this->em = $em;
        
        return $this;
    }
    
    public function useMysql($dbname, $user, $pass)
    {
        $this->connectionParams = array(
            'driver' => 'pdo_mysql',
            'dbname' => $dbname,
            'user' => $user,
            'password' => $pass,
            'host' => 'localhost',
            'charset' => "utf8");
        
        return $this;
    }
    
    protected function initMetadataDriverImpl()
    {
        $chainDriver = new DriverChain();
        
        if ($this->annotationMapping) {
            $rc = new \ReflectionClass('\Doctrine\ORM\Mapping\Driver\AnnotationDriver');
            AnnotationRegistry::registerFile(dirname($rc->getFileName()) . '/DoctrineAnnotations.php');

            $pathes = array();
            foreach ($this->annotationMapping as $path) {
                $pathes[] = $this->guessPath($path);
            }

            $annotationDriver = new AnnotationDriver(new AnnotationReader(), $pathes);
            foreach ($this->annotationMapping as $namespace => $path) {
                $chainDriver->addDriver($annotationDriver, $namespace);
            }
        }

        if ($this->xmlMapping) {
            foreach ($this->xmlMapping as $namespace => $path) {
                $path = $this->guessPath($path);
                
                $xd = new XmlDriver(array($path));
                $xd->setGlobalBasename(array('mapping'));
                $xd->setNamespacePrefixes(array($path => $namespace));
                
                $chainDriver->addDriver($xd, $namespace);
            }
            
        }
        
        return $chainDriver;
    }


    
    protected function initFixtureManager()
    {
        $fixtureManager = new FixtureManager($this->em());
        
        foreach ($this->fixtures as $name => $fixture) {
            $fixtureManager->registerFixture($name, $fixture);
        }
        
        return $fixtureManager;
    }
    
    protected function fixtureManager()
    {
        if (false == $this->fixtureManager) {
            $this->fixtureManager = $this->initFixtureManager();
        }
        
        return $this->fixtureManager;
    }
    
    protected function guessPath($originalPath)
    {
        if (is_dir($originalPath)) return $originalPath;
        
        foreach ($this->basepathes as $basepath) {
            $path = $basepath . '/' . $originalPath;
            
            if (is_dir($path)) return $path;
        }
        
        throw new \Exception('Can guess the path `'.$originalPath.'` and basepathes: `'.implode('`, `', $this->basepathes).'`');
    }
    
    public function rebuild()
    {
        $em = $this->em();        

        $classes = array();
        if ($this->entitiesName) {
            foreach ($this->entitiesName as $class) {
                $classes[] = $em->getClassMetadata($class);

                foreach ($classes as $class) {
                    foreach ($class->associationMappings as $fieldName => $mapping) {
                        if (false === array_search($mapping['targetEntity'], $this->entitiesName, false)) {
                            unset($class->associationMappings[$fieldName]);
                            unset($class->reflFields[$fieldName]);
                        }
                    }
                }
            }
        } else {
            $classes = $em->getMetadataFactory()->getAllMetadata();
        }
        
        $schemaTool = new SchemaTool($em);
        $schemaTool->dropSchema($classes);
        $schemaTool->createSchema($classes);
        
        $em->clear();
        
        return $this;
    }
    
    public function load(array $fixtures)
    {
        $this->fixtureManager()->load($fixtures);
        
        return $this;
    }
    
    public function clean()
    {
        $this->fixtureManager()->clean();
        
        return $this;
    }
    
    public function get($referance)
    {
        return $this->fixtureManager()->get($referance);
    }
}