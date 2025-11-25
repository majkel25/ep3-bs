<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class InterestController extends AbstractActionController
{
    public function registerAction()
    {
        return new JsonModel([
            'ok'      => true,
            'message' => 'InterestController baseline check OK',
        ]);
    }
}
