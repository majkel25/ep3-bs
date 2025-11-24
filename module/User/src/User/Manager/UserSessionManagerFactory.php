<?php

namespace User\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;
use RuntimeException;

class UserSessionManagerFactory implements FactoryInterface
{
    /**
     * Create UserSessionManager with proper error reporting.
     *
     * @param ServiceLocatorInterface $sm
     * @return UserSessionManager
     */
    public function createService(ServiceLocatorInterface $sm)
    {
        try {
            $configManager   = $sm->get('Base\Manager\ConfigManager');
            $userManager     = $sm->get('User\Manager\UserManager');
            $sessionManager  = $sm->get('Zend\Session\SessionManager');
        } catch (\Throwable $e) {
            // This makes it clear WHICH dependency blew up.
            throw new RuntimeException(
                'UserSessionManagerFactory dependency error: ' . $e->getMessage(),
                0,
                $e
            );
        }

        try {
            return new UserSessionManager(
                $configManager,
                $userManager,
                $sessionManager
            );
        } catch (\Throwable $e) {
            // If constructor itself throws, we’ll see that too.
            throw new RuntimeException(
                'UserSessionManager constructor error: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
