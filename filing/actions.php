<?php

class civicrm_filing
{
    public function __construct(private rcube_plugin $plugin) {}

    private function directory(): string
    {
        $app = rcmail::get_instance();
        $account = (int) ($app->user->ID ?? 0);
        if ($account < 1) {
            throw new DomainException('invalidrequest');
        }
        $root = $app->config->get('civicrm_filing_dir') ?: sys_get_temp_dir() . '/roundcube-civicrm-filing';
        return $root . '/account-' . $account;
    }

    private function marker(string $mailboxIdentity): string
    {
        $site = rcmail::get_instance()->config->get('civicrm_api4_url');
        $key = hash('sha256', serialize(['marker-v2', $site, $mailboxIdentity]));
        return $this->directory() . '/mail-' . $key;
    }

    private function mailbox_identity(string $folder, string $uid): string
    {
        return hash('sha256', serialize(['mailbox-v1', $folder, $uid]));
    }

    public function mark_list($args)
    {
        $marked = [];
        foreach ($args['messages'] as $header) {
            $folder = $header->folder ?: rcmail::get_instance()->get_storage()->get_folder();
            $identity = $this->mailbox_identity((string) $folder, (string) $header->uid);
            if ($status = $this->status($identity)) {
                $marked[$folder][$header->uid] = $status;
            }
        }
        rcmail::get_instance()->output->set_env('civicrm_filed_rows', $marked);
        return $args;
    }

    public function mark_open($args)
    {
        $mail = $args['object'];
        $folder = (string) (($mail->folder ?? '') ?: rcmail::get_instance()->get_storage()->get_folder());
        $uid = (string) ($mail->uid ?? $mail->headers->uid ?? '');
        $status = $folder !== '' && ctype_digit($uid)
            ? $this->status($this->mailbox_identity($folder, $uid)) : false;
        rcmail::get_instance()->output->set_env('civicrm_mail_filed', $status);
        return $args;
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

    private function mark_confirmed(string $mailboxIdentity, int $id, int $contact): array|bool
    {
        $file = fopen($this->marker($mailboxIdentity), 'c+b');
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
                $term = (string) ($_POST['term'] ?? '');
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
                $headers = $app->get_storage()->get_message_headers($uid, $folder);
                if (!$headers) {
                    throw new DomainException('missingmail');
                }
                $limit = filter_var($app->config->get('civicrm_filing_max_mib', 25), FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1, 'default' => 25]]);
                if (empty($headers->size)) {
                    throw new DomainException('sizeunknown');
                }
                if ($headers->size > $limit * 1048576) {
                    $reply['error'] = sprintf($this->plugin->gettext('mailtoolarge'), $limit);
                } elseif (in_array($action, ['preview', 'file'], true)) {
                    $mail = new rcube_message($uid, $folder);
                    if (!$mail->headers) {
                        throw new DomainException('missingmail');
                    }
                    $parts = $this->attachment_list($mail);
                    if ($action === 'preview') {
                        $reply = ['subject' => $mail->subject, 'sender' => $mail->sender['mailto'] ?? '',
                            'attachments' => array_values($parts)];
                    } else {
                        $selected = json_decode($_POST['attachments'] ?? '[]', true);
                        if (!is_array($selected) || !array_is_list($selected)) {
                            throw new DomainException('invalidrequest');
                        }
                        foreach ($selected as $part) {
                            if (!is_string($part) || !isset($parts[$part])) {
                                throw new DomainException('invalidrequest');
                            }
                        }
                        $id = (string) ($_POST['contact'] ?? '');
                        $book = new civicrm_addressbook_instance();
                        if (!ctype_digit($id) || !(int) $id || !$book->get_record($id, true)) {
                            throw new DomainException('lookupfailed');
                        }
                        $contents = $this->attachment_contents($mail, $parts);
                        $mailboxIdentity = $this->mailbox_identity($folder, $uid);
                        $reply = $this->file($mail, (int) $id, $parts, $contents, $mailboxIdentity);
                        $reply['attachments'] = [];
                        foreach (array_unique($selected) as $part) {
                            $label = 'attachmentconfirmed';
                            try {
                                $this->attach($reply['id'], $parts[$part], $contents[$part]);
                            } catch (Throwable $e) {
                                $label = $e instanceof DomainException && in_array($e->getMessage(), ['attachmentfailed', 'attachmentdenied', 'attachmentlimit'], true)
                                    ? $e->getMessage() : 'attachmentuncertain';
                            }
                            $reply['attachments'][] = ['id' => $part, 'confirmed' => $label === 'attachmentconfirmed',
                                'message' => sprintf($this->plugin->gettext($label), $parts[$part]['name'])];
                        }
                    }
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

    private function attachment_list(rcube_message $mail): array
    {
        $parts = [];
        foreach ($mail->attachments as $part) {
            $type = strtolower((string) $part->mimetype);
            $disposition = strtolower((string) $part->disposition);
            if (str_starts_with($type, 'image/') && ($disposition === 'inline'
                || (!empty($part->content_id) && $disposition !== 'attachment'))) {
                continue;
            }
            $id = (string) $part->mime_id;
            $name = basename(str_replace('\\', '/', (string) $part->filename));
            $name = preg_replace('/[[:cntrl:]]/', '_', $name);
            $parts[$id] = ['id' => $id, 'name' => $name ?: 'attachment-' . $id,
                'type' => $type ?: 'application/octet-stream', 'size' => (int) $part->size,
                'exactSize' => !empty($part->exact_size)];
        }
        return $parts;
    }

    private function attachment_contents(rcube_message $mail, array $parts): array
    {
        $contents = [];
        foreach ($parts as $part) {
            $bytes = $mail->get_part_body($part['id']);
            if (!is_string($bytes) || ($bytes === '' && $part['size'] > 0)) {
                throw new DomainException('attachmentreadfailed');
            }
            $contents[$part['id']] = $bytes;
        }
        return $contents;
    }

    private function message_identity(string $subject, array $headers, string $body, ?string $activityDate,
        array $parts, array $contents): string
    {
        ksort($parts, SORT_STRING);
        $attachments = [];
        foreach ($parts as $part) {
            $bytes = $contents[$part['id']];
            $attachments[] = [
                'id' => $part['id'], 'name' => $part['name'], 'type' => $part['type'],
                'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            ];
        }
        return hash('sha256', serialize([
            'message-v1', $subject, $headers, $body, $activityDate, $attachments,
        ]));
    }

    private function attach(int $activity, array $part, string $bytes): void
    {
        $site = rcmail::get_instance()->config->get('civicrm_api4_url');
        $key = hash('sha256', serialize([
            'attachment-v2', $site, $activity, $part['id'], $part['name'], $part['type'],
            strlen($bytes), hash('sha256', $bytes),
        ]));
        $file = fopen($this->directory() . '/attachment-' . $key, 'c+b');
        if (!$file) {
            throw new DomainException('attachmentfailed');
        }
        try {
            if (!flock($file, LOCK_EX)) {
                throw new DomainException('attachmentfailed');
            }
            $state = stream_get_contents($file);
            if (ctype_digit($state) && (int) $state > 0) {
                return;
            }
            if ($state !== '') {
                throw new DomainException('attachmentuncertain');
            }
            $this->record($file, 'pending');
            $response = civicrm_api4_http::attach($activity, $part['name'], $part['type'], $bytes);
            $status = $response->getStatusCode();
            $data = json_decode((string) $response->getBody(), true);
            $created = $data['id'] ?? null;
            if (!$created && is_array($data['values'] ?? null) && count($data['values']) === 1) {
                $created = reset($data['values'])['id'] ?? null;
            }
            if ($status >= 200 && $status < 300 && isset($data['is_error']) && !$data['is_error'] && (int) $created > 0) {
                $this->record($file, (string) $created);
                return;
            }
            error_log('[civicrm_addressbook] Attachment.create http=' . $status);
            if (in_array($status, [401, 403, 413], true) || ($data['error_code'] ?? '') === 'unauthorized') {
                $this->record($file, '');
                throw new DomainException($status === 413 ? 'attachmentlimit' : 'attachmentdenied');
            }
            throw new DomainException('attachmentuncertain');
        } finally {
            fclose($file);
        }
    }

    private function file(rcube_message $mail, int $contact, array $parts, array $contents,
        string $mailboxIdentity): array
    {
        $config = rcmail::get_instance()->config;
        $body = (string) $mail->first_text_part();
        $lines = [];
        $headers = [];
        foreach (['from', 'to', 'cc', 'date'] as $field) {
            $value = (string) $mail->get_header($field);
            $headers[$field] = $value;
            if ($value !== '') {
                $lines[] = $this->plugin->gettext('header' . $field) . ': ' . $value;
            }
        }
        $details = nl2br(htmlspecialchars(implode("\n", $lines) . "\n\n" . $body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $subject = (string) ($mail->subject ?: '');
        $values = [
            'activity_type_id:name' => 'Email', 'status_id:name' => 'Completed',
            'source_contact_id' => 'user_contact_id', 'target_contact_id' => [$contact],
            'subject' => $subject !== '' ? $subject : $this->plugin->gettext('nosubject'), 'details' => $details,
        ];
        $activityDate = null;
        if ($mail->headers->timestamp) {
            $activityDate = date('Y-m-d H:i:s', $mail->headers->timestamp);
            $values['activity_date_time'] = $activityDate;
        }

        // Reserve before posting: a transport timeout can also occur after a successful create.
        $identity = $this->message_identity($subject, $headers, $body, $activityDate, $parts, $contents);
        $dir = $this->directory();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new DomainException('journalfailed');
        }
        $key = hash('sha256', serialize([
            'activity-v2', $config->get('civicrm_api4_url'), $contact, $identity,
        ]));
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
                return ['id' => (int) $state, 'already' => true,
                    'marker' => $this->mark_confirmed($mailboxIdentity, (int) $state, $contact)];
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
            return ['id' => $id, 'marker' => $this->mark_confirmed($mailboxIdentity, $id, $contact)];
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
