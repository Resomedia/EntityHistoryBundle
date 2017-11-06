# EntityHistoryBundle

Create a modifications' history of entities what you want. With the user who have modified your object.

##Installation

###Step 1: Download ResomediaDoctrineEncryptBundle using composer

ResomediaDoctrineEncryptBundle should be installed usin Composer:

    {
        "require": {
            "resomedia/entity-history-bundle": "1.*"
        }
    }

Now tell composer to download the bundle by running the command:

$ php composer.phar update resomedia/entity-history-bundle

###Step 2: Enable the bundle

Enable the bundle in the Symfony2 kernel by adding it in your /app/AppKernel.php file:

    public function registerBundles()
    {
        $bundles = array(
            // ...
            new Resomedia\DoctrineEncryptBundle\ResomediaEntityHistoryBundle(),
        );
    }

###Step 3: Configuration

    user_property - The user property will be stored for identify the user
        Default: username

    class_history - The class name of the object who extend History class

    entity - List your entities to historize
        
    fields - array of fields to stored in historization
    
    ignore_fields - array of fields to not stored in historization

yaml

    resomedia_entity_history:
        user_property: id
        class_history: AppBundle\Entity\Revision
        entity:
            user:
                fields: ~
            association:
                fields: ~
                ignore_fields: [users]

###Step 4: History class

Create an object to extend History class

You can add many fields as you want and override "addProcess" for add instruction to execute before the save of history.

    class Revision extends History
    {
        /**
         * @var integer $id
         * @ORM\Column(name="id", type="integer")
         * @ORM\Id
         * @ORM\GeneratedValue(strategy="AUTO")
         */
        protected $id;
        
        /**
         * @ORM\Column(name="property", type="integer")
         */
        protected $property
    
        /**
         * @param mixed $originEntity
         * @param mixed $historizableEntity
         * @return mixed
         */
        public function addProcess($originEntity, $historizableEntity) {
            $this->property = 0;
            //some instructions
        }
    }
    
###Step 5: Use services

####Create manual versions

You can use the historizationManager for create an history or compare two versions of an entity.

Use "historizationEntity" for create an history of one entity. You can specify an EntityManger for persist and flush automatically. And force a specific state (if you want, you can create your own states).
    
    historizationEntity(entity, EntityManager, state)
    
Use "historizationEntities" for an array of entities

    historizationEntities(Array entities, EntityManager, state)
    
Use "compareEntityVersion" for compare two versions of an entity.
You can specify a version object, or a version id.
Without this parameters, the version use is the last before the actual version.

    compareEntityVersion(HistoryRepository, Entity actual_entity, Entity specific_version, Integer history_id)
    
####Create versions automatically

Add the suscriber in your services.yml for create automatically a new version when an entity with the @History annotation is modified.

    resomedia_entity_history.subscriber:
        class: Resomedia\EntityHistoryBundle\Subscribers\HistorizationSubscriber
        arguments: ["@resomedia_entity_history.historization_manager"]
        tags:
            -  { name: doctrine.event_subscriber }