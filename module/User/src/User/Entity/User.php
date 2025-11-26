<?php

namespace User\Entity;

use Base\Entity\AbstractEntity;

class User extends AbstractEntity
{

    protected $uid;
    protected $alias;
    protected $status;
    protected $email;
    protected $notify_cancel_email;
    protected $notify_cancel_whatsapp;
    protected $pw;
    protected $login_attempts;
    protected $login_detent;
    protected $last_activity;
    protected $last_ip;
    protected $created;

    /**
     * The possible status options.
     *
     * @var array
     */
    public static $statusOptions = array(
        'placeholder' => 'Placeholder',
        'deleted' => 'Deleted user',
        'blocked' => 'Blocked user',
        'disabled' => 'Waiting for activation',
        'enabled' => 'Enabled',
    );

    /**
     * The possible gender options.
     *
     * @var array
     */
    public static $genderOptions = array(
        'm' => 'Mr.',
        'f' => 'Ms.',
    );

    /**
     * Creates a new user object.
     *
     * @param array $data
     * @param array $meta
     */
    public function __construct(array $data = array(), array $meta = array())
    {
        $this->primary = 'uid';

        $this->populate($data);
        $this->populateMeta($meta);
    }

    /**
     * Populates an entity with data.
     *
     * @param array $data
     */
    public function populate(array $data = array())
    {
        foreach ($data as $property => $value) {
            $this->set($property, $value, false, false);
        }

        $this->reset();
    }

    /**
     * Populates meta data.
     *
     * @param array $meta
     */
    public function populateMeta(array $meta = array())
    {
        foreach ($meta as $property => $value) {
            $this->setMeta($property, $value, false);
        }

        $this->reset();
    }

    /**
     * Gets the status label.
     *
     * @param string $default
     *
     * @return string
     */
    public function getStatusLabel($default = null)
    {
        $status = $this->get('status');

        if (is_null($status)) {
            return $default;
        }

        if (isset(self::$statusOptions[$status])) {
            return self::$statusOptions[$status];
        } else {
            return 'Unknown';
        }
    }

    /**
     * Gets the gender label.
     *
     * @param string $default
     *
     * @return string
     */
    public function getGender($default = null)
    {
        $gender = $this->getMeta('gender');

        if (is_null($gender)) {
            return $default;
        }

        if (isset(self::$genderOptions[$gender])) {
            return self::$genderOptions[$gender];
        } else {
            return 'Unknown';
        }
    }

    /**
     * Gets the display name.
     *
     * @param string $default
     *
     * @return string
     */
    public function getDisplayName($default = null)
    {
        $firstname = $this->getMeta('firstname');
        $lastname = $this->getMeta('lastname');

        if ($firstname || $lastname) {
            return trim($firstname . ' ' . $lastname);
        }

        $name = $this->getMeta('name');

        if ($name) {
            return $name;
        }

        $alias = $this->get('alias');

        if ($alias) {
            return $alias;
        }

        return $default;
    }

    /**
     * Gets the display name with gender.
     *
     * @param string $default
     *
     * @return string
     */
    public function getDisplayNameWithGender($default = null)
    {
        $gender = $this->getGender();
        $displayName = $this->getDisplayName();

        if ($displayName && $gender) {
            return sprintf('%s %s', $gender, $displayName);
        } else if ($displayName) {
            return $displayName;
        }

        return $default;
    }

    /**
     * The possible privileges.
     *
     * @var array
     */
    public static $privileges = array(
        'admin.user' => 'May manage users',
        'admin.booking' => 'May manage bookings',
        'admin.event' => 'May manage events',
        'admin.config' => 'May change configuration',
        'admin.see-menu' => 'Can see the admin menu',
        'calendar.see-past' => 'Can see the past in the calendar',
        'calendar.see-future' => 'Can see the future in the calendar',
        'calendar.see-user' => 'Can see other user\'s bookings in the calendar',
        'calendar.limit-past' => 'Can not create bookings in the past',
        'calendar.limit-future' => 'Can not create bookings in the future',
        'booking.create' => 'May create bookings',
        'booking.update' => 'May update bookings',
        'booking.delete.own' => 'May delete own bookings',
        'booking.delete.all' => 'May delete all bookings',
        'booking.create.own' => 'May only create own bookings',
        'booking.update.own' => 'May only update own bookings',
        'booking.update.time' => 'May update booking times',
        'booking.update.past' => 'May update past bookings',
        'booking.update.future' => 'May update future bookings',
        'booking.update.all' => 'May update all bookings',
    );

    /**
     * Checks whether a user has a privilege.
     *
     * @param string|array $privileges
     *
     * @return boolean
     */
    public function hasPrivilege($privileges)
    {
        $userPrivileges = $this->getMeta('privileges', array());

        if (! is_array($userPrivileges)) {
            $userPrivileges = array($userPrivileges);
        }

        if (is_string($privileges)) {
            $privileges = array($privileges);
        }

        if (! is_array($privileges)) {
            return false;
        }

        foreach ($privileges as $privilege) {
            if (isset($userPrivileges[$privilege]) && $userPrivileges[$privilege]) {
                return true;
            }

            if (strpos($privilege, '||') !== false) {
                $orPrivileges = explode('||', $privilege);
                $orPrivilegesMatched = 0;

                foreach ($orPrivileges as $orPrivilege) {
                    if (isset($userPrivileges[$orPrivilege]) && $userPrivileges[$orPrivilege]) {
                        $orPrivilegesMatched++;
                    }
                }

                if ($orPrivilegesMatched >= 1) {
                    return true;
                }
            }
        }

        return false;
    }

}
