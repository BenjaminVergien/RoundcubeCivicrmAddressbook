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
        $app = rcmail::get_instance();
        $filingEnabled = $app->task === 'mail'
            && $config->get('civicrm_filing_enabled', (bool) $config->get('civicrm_filing_dir'))
            && is_file(__DIR__ . '/filing/actions.php');
        $filingView = $filingEnabled && $app->output instanceof rcmail_output_html
            && in_array($app->action, ['', 'list', 'show'], true);
        if ($filingEnabled) {
            require_once __DIR__ . '/filing/actions.php';
            $filing = new civicrm_filing($this);
            $this->add_hook('storage_init', [$filing, 'request_message_id']);
            $this->add_hook('messages_list', [$filing, 'mark_list']);
            if ($app->action === 'show' || $app->action === 'preview') {
                $this->add_hook('message_load', [$filing, 'mark_open']);
            }
            if ($filingView || $app->action === 'plugin.civicrm-file') {
                $this->add_texts('localization', $filingView);
                $this->register_action('plugin.civicrm-file', [$filing, 'handle']);
            }
            if ($filingView) {
                $app->output->set_env('civicrm_logo', 'data:image/svg+xml;base64,' . base64_encode(file_get_contents(__DIR__ . '/filing/civicrm.svg')));
                $this->add_button([
                    'type' => 'link', 'command' => 'plugin.civicrm-file',
                    'label' => 'filemail', 'domain' => $this->ID,
                    'class' => 'button archive disabled', 'classact' => 'button archive',
                    'innerclass' => 'inner',
                ], 'toolbar');
                $app->output->add_script(file_get_contents(__DIR__ . '/filing/dialog.js'), 'head');
            }
        }
        $compose = $app->task === 'mail' && $app->action === 'compose' && $app->output instanceof rcmail_output_html;
        if ($compose || $filingView) {
            $route = $compose ? 'contact/view' : 'activity/view';
            $uri = new \GuzzleHttp\Psr7\Uri($config->get('civicrm_api4_url'));
            parse_str($uri->getQuery(), $query);
            $path = $uri->getPath();
            $publicUri = $uri->withUserInfo('')->withFragment('')->withQuery('');
            if (($query['civiwp'] ?? '') === 'CiviCRM' || ($query['page'] ?? '') === 'CiviCRM') {
                $root = preg_replace('~/(?:wp-admin/admin\.php|index\.php)/?$~', '/', $path);
                $publicUri = $publicUri->withPath(rtrim($root, '/') . '/wp-admin/admin.php')
                    ->withQuery("page=CiviCRM&q=civicrm/{$route}&reset=1");
            } elseif (preg_match('~/ajax/api4/?$~', $path)) {
                $publicUri = $publicUri->withPath(preg_replace('~/ajax/api4/?$~', '/' . $route, $path))
                    ->withQuery('reset=1');
            }
            if ($publicUri->getScheme() === 'https' && $publicUri->getQuery() !== '') {
                $app->output->set_env($compose ? 'civicrm_contact_url' : 'civicrm_activity_url', (string) $publicUri);
            }
        }
        if ($compose) {
            $app->output->add_script(<<<'JS'
rcmail.addEventListener('listupdate', function (event) {
    if (event.list !== rcmail.contact_list) return;
    for (const [id, recipient] of Object.entries(rcmail.env.contactdata || {})) {
        if (typeof recipient !== 'string') continue;
        const row = document.getElementById('rcmrow' + rcmail.html_identifier(id));
        const link = row && row.closest('#recipient-dialog') && row.querySelector('td.contact a');
        if (!link || link.dataset.recipientDetails) continue;
        const mailbox = recipient.match(/<([^<>]+)>$/);
        const email = mailbox ? mailbox[1] : recipient;
        const crm = /^civicrm-(\d+)-\d+$/.exec(id);
        const details = [];
        if (!link.querySelector('.email') && link.textContent.trim() !== email) details.push(email);
        if (crm && !rcmail.env.civicrm_contact_url) details.push('CiviCRM #' + crm[1]);
        if (details.length) link.append(' · ' + details.join(' · '));
        if (crm && rcmail.env.civicrm_contact_url) {
            const url = new URL(rcmail.env.civicrm_contact_url);
            url.searchParams.set('cid', crm[1]);
            const contact = document.createElement('a');
            contact.href = url.href;
            contact.target = '_blank';
            contact.rel = 'noopener noreferrer';
            contact.textContent = 'CiviCRM #' + crm[1] + ' ↗';
            contact.style.display = 'inline';
            contact.style.textDecoration = 'underline';
            for (const type of ['pointerdown', 'mousedown', 'mouseup', 'click', 'dblclick', 'touchstart', 'touchend', 'keydown', 'keyup']) {
                contact.addEventListener(type, event => event.stopPropagation());
            }
            link.style.display = 'inline';
            link.after(' · ', contact);
        }
        link.dataset.recipientDetails = '1';
    }
});
JS
            , 'docready');
        }
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
