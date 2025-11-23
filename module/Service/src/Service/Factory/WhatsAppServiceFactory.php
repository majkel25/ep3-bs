
 <?php
 namespace Service\Factory;
 
+use Interop\Container\ContainerInterface;
 use Service\Service\WhatsAppService;
 use Zend\Http\Client;
-use Zend\ServiceManager\FactoryInterface;
+use Zend\ServiceManager\Factory\FactoryInterface as V3FactoryInterface;
+use Zend\ServiceManager\FactoryInterface as V2FactoryInterface;
 use Zend\ServiceManager\ServiceLocatorInterface;
 
-class WhatsAppServiceFactory implements FactoryInterface
+class WhatsAppServiceFactory implements V2FactoryInterface, V3FactoryInterface
 {
+    /**
+     * Zend ServiceManager v3 support.
+     *
+     * @param ContainerInterface $container
+     * @param string             $requestedName
+     * @param null|array         $options
+     * @return WhatsAppService
+     */
+    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
+    {
+        return $this->doCreate($container);
+    }
+
     /**
      * @param ServiceLocatorInterface $sl
      * @return WhatsAppService
      */
     public function createService(ServiceLocatorInterface $sl)
+    {
+        return $this->doCreate($sl);
+    }
+
+    /**
+     * Shared constructor to keep v2/v3 compatible.
+     *
+     * @param ContainerInterface|ServiceLocatorInterface $sl
+     * @return WhatsAppService
+     */
+    private function doCreate($sl)
     {
         $config = $sl->get('config');
         $client = new Client();
 
         return new WhatsAppService($client, $config);
     }
 }
 
EOF
)