 (cd "$(git rev-parse --show-toplevel)" && git apply --3way <<'EOF' 
diff --git a/module/Service/src/Service/Factory/WhatsAppServiceFactory.php b/module/Service/src/Service/Factory/WhatsAppServiceFactory.php
index aea1985dc5ae8638387947ab8764086d9755a4ec..abf7ab8e70f0531ab28aca4cbff6dd1e5d810b29 100644
--- a/module/Service/src/Service/Factory/WhatsAppServiceFactory.php
+++ b/module/Service/src/Service/Factory/WhatsAppServiceFactory.php
@@ -1,22 +1,36 @@
 <?php
 namespace Service\Factory;
 
 use Service\Service\WhatsAppService;
+use Interop\Container\ContainerInterface;
 use Zend\Http\Client;
 use Zend\ServiceManager\FactoryInterface;
 use Zend\ServiceManager\ServiceLocatorInterface;
 
 class WhatsAppServiceFactory implements FactoryInterface
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
+        return $this->createService($container);
+    }
+
     /**
      * @param ServiceLocatorInterface $sl
      * @return WhatsAppService
      */
     public function createService(ServiceLocatorInterface $sl)
     {
         $config = $sl->get('config');
         $client = new Client();
 
         return new WhatsAppService($client, $config);
     }
 }
 
EOF
)
