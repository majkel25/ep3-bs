<?php

namespace Frontend\Controller;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\JsonModel;

class InterestController extends AbstractActionController
{
    public function registerAction()
    {
        // Absolutely no includes, no DB, no services.
        // Just report what PHP thinks about the class.

        $fqcn = 'Service\\Service\\BookingInterestService';

        $existsWithoutAutoload = class_exists($fqcn, false); // do NOT trigger autoload
        $existsWithAutoload    = class_exists($fqcn);        // normal autoload

        return new JsonModel([
            'ok'                     => true,
            'checked_class'          => $fqcn,
            'exists_without_autoload'=> $existsWithoutAutoload,
            'exists_with_autoload'   => $existsWithAutoload,
        ]);
    }
}
