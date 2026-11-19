<?php

require_once __DIR__ . '/civicrm_addressbook_instance.php';

class civicrm_addressbook extends rcube_plugin
{
    public $task = 'mail|addressbook';

    public function init()
    {
        $this->load_config();
        $config = rcmail::get_instance()->config;
        if (!$config->get('civicrm_api4_url') || !$config->get('civicrm_api4_key')) {
            return;
        }

        $this->add_hook('addressbooks_list', [$this, 'addressbooks_list']);
        $this->add_hook('addressbook_get', [$this, 'addressbook_get']);
        $sources = (array) $config->get('autocomplete_addressbooks', ['sql']);
        if (!in_array('civicrm', $sources, true)) {
            $sources[] = 'civicrm';
            $config->set('autocomplete_addressbooks', $sources);
        }
    }

    public function addressbook_get($args)
    {
        if ($args['id'] === 'civicrm') {
            $args['instance'] = new civicrm_addressbook_instance();
        }
        return $args;
    }

    public function addressbooks_list($args)
    {
        $args['sources']['civicrm'] = [
            'id' => 'civicrm', 'name' => 'CiviCRM', 'readonly' => true,
            'groups' => false, 'searchonly' => true,
        ];
        return $args;
    }
}
