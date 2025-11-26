<?php

namespace User\Form;

use Zend\Form\Form;

class EditNotificationsForm extends Form
{

    public function init()
    {
        $this->setName('enf');

        // Existing booking confirmation notifications
        $this->add(array(
            'name' => 'enf-booking-notifications',
            'type' => 'Checkbox',
            'options' => array(
                'label' => 'Notify on bookings and cancellations',
                'use_hidden_element' => true,
                'checked_value' => 'true',
                'unchecked_value' => 'false',
            ),
        ));

        // New: cancellation interest notifications via e-mail
        $this->add(array(
            'name' => 'enf-cancel-email',
            'type' => 'Checkbox',
            'options' => array(
                'label' => 'E-mail me when a table becomes free on a day I registered interest',
                'use_hidden_element' => true,
                'checked_value' => 'true',
                'unchecked_value' => 'false',
            ),
        ));

        // New: cancellation interest notifications via SMS / WhatsApp
        $this->add(array(
            'name' => 'enf-cancel-whatsapp',
            'type' => 'Checkbox',
            'options' => array(
                'label' => 'Send me an SMS / WhatsApp when a table becomes free on a day I registered interest',
                'use_hidden_element' => true,
                'checked_value' => 'true',
                'unchecked_value' => 'false',
            ),
        ));

        $this->add(array(
            'name' => 'enf-submit',
            'type' => 'Submit',
            'attributes' => array(
                'value' => 'Update settings',
                'class' => 'default-button',
            ),
        ));
    }

}
