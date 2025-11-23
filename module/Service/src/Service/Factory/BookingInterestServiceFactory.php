 (cd "$(git rev-parse --show-toplevel)" && git apply --3way <<'EOF' 
diff --git a/module/Service/src/Service/Factory/BookingInterestServiceFactory.php b/module/Service/src/Service/Factory/BookingInterestServiceFactory.php
index fd05d9c0ede200ff27636adf13f34d94ddacb27b..15c41edfeaa303af8178c27a302787a4e3a046f3 100644
--- a/module/Service/src/Service/Factory/BookingInterestServiceFactory.php
+++ b/module/Service/src/Service/Factory/BookingInterestServiceFactory.php
@@ -1,41 +1,57 @@
 <?php
 
 namespace Service\Factory;
 
+use Interop\Container\ContainerInterface;
 use Service\Service\BookingInterestService;
 use Service\Service\WhatsAppService;
+use Throwable;
 use Zend\Db\Adapter\Adapter;
 use Zend\Mail\Transport\TransportInterface;
 use Zend\ServiceManager\FactoryInterface;
 use Zend\ServiceManager\ServiceLocatorInterface;
 
 class BookingInterestServiceFactory implements FactoryInterface
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
+        return $this->createService($container);
+    }
+
     /**
      * @param ServiceLocatorInterface $sl
      * @return BookingInterestService
      */
     public function createService(ServiceLocatorInterface $sl)
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
