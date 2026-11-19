<?php

class civicrm_filing
{
    public function __construct(private rcube_plugin $plugin) {}

    private function directory(): string
    {
        return rcmail::get_instance()->config->get('civicrm_filing_dir') ?: sys_get_temp_dir() . '/roundcube-civicrm-filing';
    }

    private function marker(string $identity): string
    {
        $site = rcmail::get_instance()->config->get('civicrm_api4_url');
        return $this->directory() . '/mail-' . hash('sha256', $site . "\0" . $identity);
    }

    public function request_message_id($options)
    {
        // List views fetch a shorter set of IMAP headers than the open-message view.
        $options['fetch_headers'] = trim(($options['fetch_headers'] ?? '') . ' MESSAGE-ID');
        return $options;
    }

    public function mark_list($args)
    {
        $marked = [];
        foreach ($args['messages'] as $header) {
            $identity = trim((string) $header->get('message-id'));
            if ($identity !== '' && ($status = $this->status($identity))) {
                $folder = $header->folder ?: rcmail::get_instance()->get_storage()->get_folder();
                $marked[$folder][$header->uid] = $status;
            }
        }
        rcmail::get_instance()->output->set_env('civicrm_filed_rows', $marked);
        return $args;
    }

    public function mark_open($args)
    {
        $mail = $args['object'];
        $identity = trim((string) $mail->get_header('message-id'));
        if ($identity === '') {
            $identity = $this->fallback_identity($mail, (string) $mail->first_text_part());
        }
        rcmail::get_instance()->output->set_env('civicrm_mail_filed', $this->status($identity));
        return $args;
    }

    private function fallback_identity($mail, string $body): string
    {
        return hash('sha256', implode("\0", [$mail->subject, $mail->get_header('from'), $mail->get_header('date'), $body]));
    }

    private function status(string $identity): array|bool
    {
        $path = $this->marker($identity);
        if (!is_file($path) || !($file = fopen($path, 'rb'))) {
            return false;
        }
        try {
            return flock($file, LOCK_SH) ? $this->read_marker($file) : true;
        } finally {
            fclose($file);
        }
    }

    private function read_marker($file): array|bool
    {
        $link = json_decode(stream_get_contents($file), true);
        return !empty($link['id']) && !empty($link['cid']) ? $link : true;
    }

    private function mark_confirmed(string $identity, int $id, int $contact): array|bool
    {
        $file = fopen($this->marker($identity), 'c+b');
        try {
            if (!$file || !flock($file, LOCK_EX)) {
                throw new DomainException('journalfailed');
            }
            $first = $this->read_marker($file);
            if (is_array($first)) {
                return $first;
            }
            $link = ['id' => $id, 'cid' => $contact];
            $this->record($file, json_encode($link));
            return $link;
        } catch (DomainException $e) {
            error_log('[civicrm_addressbook] Could not update the filing indicator');
            return true;
        } finally {
            if ($file) {
                fclose($file);
            }
        }
    }

    public function handle(): void
    {
        $app = rcmail::get_instance();
        $reply = [];
        try {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$app->user->ID || !$app->check_request()) {
                throw new DomainException('invalidrequest');
            }
            $action = $_POST['op'] ?? '';
            if ($action === 'search') {
                $book = new civicrm_addressbook_instance();
                $term = trim((string) ($_POST['term'] ?? ''));
                $book->page_size = 20;
                $result = $book->search('*', $term, 0, true, true);
                if ($book->get_error()) {
                    throw new DomainException('lookupfailed');
                }
                $reply['contacts'] = $result->records;
            } else {
                $uid = (string) ($_POST['_uid'] ?? '');
                $folder = $_POST['_mbox'] ?? '';
                if (!ctype_digit($uid) || !is_string($folder) || $folder === '') {
                    throw new DomainException('invalidrequest');
                }
                $mail = new rcube_message($uid, $folder);
                if (!$mail->headers) {
                    throw new DomainException('missingmail');
                }
                if ($action === 'preview') {
                    $reply['subject'] = $mail->subject;
                    $reply['sender'] = $mail->sender['mailto'] ?? '';
                } elseif ($action === 'file') {
                    $id = (string) ($_POST['contact'] ?? '');
                    $book = new civicrm_addressbook_instance();
                    if (!ctype_digit($id) || !(int) $id || !$book->get_record($id, true)) {
                        throw new DomainException('lookupfailed');
                    }
                    $reply = $this->file($mail, (int) $id);
                } else {
                    throw new DomainException('invalidrequest');
                }
            }
        } catch (DomainException $e) {
            $reply = ['error' => $this->plugin->gettext($e->getMessage())];
        } catch (Throwable $e) {
            error_log('[civicrm_addressbook] filing failed: ' . get_class($e));
            $reply = ['error' => $this->plugin->gettext('filingfailed')];
        }
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($reply, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    private function file(rcube_message $mail, int $contact): array
    {
        $config = rcmail::get_instance()->config;
        $body = (string) $mail->first_text_part();
        $lines = [];
        foreach (['from', 'to', 'cc', 'date'] as $field) {
            $value = (string) $mail->get_header($field);
            if ($value !== '') {
                $lines[] = $this->plugin->gettext('header' . $field) . ': ' . $value;
            }
        }
        $details = nl2br(htmlspecialchars(implode("\n", $lines) . "\n\n" . $body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $values = [
            'activity_type_id:name' => 'Email', 'status_id:name' => 'Completed',
            'source_contact_id' => 'user_contact_id', 'target_contact_id' => [$contact],
            'subject' => $mail->subject ?: $this->plugin->gettext('nosubject'), 'details' => $details,
        ];
        if ($mail->headers->timestamp) {
            $values['activity_date_time'] = date('Y-m-d H:i:s', $mail->headers->timestamp);
        }

        // Reserve before posting: a transport timeout can also occur after a successful create.
        $identity = trim((string) $mail->get_header('message-id'));
        if ($identity === '') {
            $identity = $this->fallback_identity($mail, $body);
        }
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new DomainException('journalfailed');
        }
        $key = hash('sha256', implode("\0", [$config->get('civicrm_api4_url'), $contact, $identity]));
        $file = fopen($dir . '/' . $key, 'c+b');
        if (!$file) {
            throw new DomainException('journalfailed');
        }
        try {
            if (!flock($file, LOCK_EX)) {
                throw new DomainException('journalfailed');
            }
            $state = stream_get_contents($file);
            if (ctype_digit($state) && (int) $state > 0) {
                return ['id' => (int) $state, 'already' => true, 'marker' => $this->mark_confirmed($identity, (int) $state, $contact)];
            }
            if ($state !== '') {
                throw new DomainException('uncertain');
            }
            $this->record($file, 'pending');
            try {
                $response = civicrm_api4_http::post('Activity/create', ['values' => $values]);
            } catch (Throwable $e) {
                throw new DomainException('uncertain');
            }
            $status = $response->getStatusCode();
            $data = json_decode((string) $response->getBody(), true);
            $id = (int) ($data['values'][0]['id'] ?? 0);
            if ($status !== 200 || isset($data['error_message']) || !$id) {
                error_log('[civicrm_addressbook] Activity.create http=' . $status);
                if (in_array($status, [400, 401, 403, 404, 405, 422], true)) {
                    $this->record($file, '');
                    throw new DomainException(in_array($status, [401, 403], true) ? 'denied' : 'filingfailed');
                }
                throw new DomainException('uncertain');
            }
            $this->record($file, (string) $id);
            return ['id' => $id, 'marker' => $this->mark_confirmed($identity, $id, $contact)];
        } finally {
            fclose($file);
        }
    }

    private function record($file, string $state): void
    {
        // Overwrite pending first so a partial final write cannot reopen the reservation.
        if (!rewind($file) || fwrite($file, $state) !== strlen($state)
            || !ftruncate($file, strlen($state)) || !fflush($file)) {
            throw new DomainException('journalfailed');
        }
    }
}
