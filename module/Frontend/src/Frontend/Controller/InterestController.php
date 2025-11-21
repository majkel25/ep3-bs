<?php
namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;
use Service\Service\BookingInterestService;

class InterestController extends AbstractActionController
{
    public function registerAction()
    {
        $auth = $this->zfcUserAuthentication();
        if (!$auth->hasIdentity()) {
            return new JsonModel(array('ok' => false, 'error' => 'AUTH_REQUIRED'));
        }

        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(array('ok' => false, 'error' => 'METHOD_NOT_ALLOWED'));
        }

        $dateStr = $this->params()->fromPost('date');

        if (!$dateStr || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return new JsonModel(array('ok' => false, 'error' => 'INVALID_DATE'));
        }

        try {
            $date = new \DateTime($dateStr);
        } catch (\Exception $e) {
            return new JsonModel(array('ok' => false, 'error' => 'INVALID_DATE'));
        }

        $identity = $auth->getIdentity();
        // ep3-bs normally exposes uid via getId()
        $userId   = (int)$identity->getId();

        /** @var BookingInterestService $svc */
        $svc = $this->getServiceLocator()->get(BookingInterestService::class);
        $svc->registerInterest($userId, $date);

        return new JsonModel(array('ok' => true));
    }
}
