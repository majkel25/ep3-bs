
 <?php
 
 namespace Service\Factory;
 
+use Interop\Container\ContainerInterface;
 use Service\Service\BookingInterestService;
 use Service\Service\WhatsAppService;
+use Throwable;
 use Zend\Db\Adapter\Adapter;
 use Zend\Mail\Transport\TransportInterface;
-use Zend\ServiceManager\FactoryInterface;
+use Zend\ServiceManager\Factory\FactoryInterface as V3FactoryInterface;
+use Zend\ServiceManager\FactoryInterface as V2FactoryInterface;
 use Zend\ServiceManager\ServiceLocatorInterface;
 
-class BookingInterestServiceFactory implements FactoryInterface
+class BookingInterestServiceFactory implements V2FactoryInterface, V3FactoryInterface
 {
+    /**
+     * Zend ServiceManager v3 support.
+     *
+     * @param ContainerInterface $container
+     * @param string             $requestedName
+     * @param null|array         $options
+     * @return BookingInterestService
+     */
+    public function __invoke(ContainerInterface $container, $requestedName, array $options = null)
+    {
+        return $this->doCreate($container);
+    }
+
     /**
      * @param ServiceLocatorInterface $sl
      * @return BookingInterestService
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
+     * @return BookingInterestService
+     */
+    private function doCreate($sl)
     {
         /** @var Adapter $db */
         $db = $sl->get('Zend\Db\Adapter\Adapter');
 
         /** @var TransportInterface $mail */
         $mail = $sl->get('Zend\Mail\Transport\TransportInterface');
 
         $config  = $sl->get('config');
         $mailCfg = isset($config['mail']) ? $config['mail'] : array();
 
-        // WhatsApp is optional
+        // WhatsApp is optional; resolve it when available but do not block booking-interest flow
         $wa = null;
         if ($sl->has(WhatsAppService::class)) {
             try {
                 $wa = $sl->get(WhatsAppService::class);
-            } catch (\Exception $e) {
+            } catch (Throwable $e) {
+                // Ignore failures resolving the optional WhatsApp integration
                 $wa = null;
             }
         }
 
         return new BookingInterestService($db, $mail, $mailCfg, $wa);
     }
 }
 
EOF
)