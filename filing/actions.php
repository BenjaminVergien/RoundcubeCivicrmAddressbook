<?php

class civicrm_filing
{
    private const DIRECTORY_MODE = 0700;
    private const FILE_MODE = 0600;
    private const MAX_STATE_BYTES = 4096;

    private ?string $journalRoot = null;
    private ?int $journalOwner = null;
    private bool $journalWarningLogged = false;

    public function __construct(private rcube_plugin $plugin) {}

    private function effective_uid(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        // An opened temporary object reveals the process owner without trusting its pathname.
        $probe = @tmpfile();
        if (!$probe) {
            throw new DomainException('journalfailed');
        }
        try {
            $stat = fstat($probe);
            if ($stat === false) {
                throw new DomainException('journalfailed');
            }
            return (int) $stat['uid'];
        } finally {
            fclose($probe);
        }
    }

    private function assert_safe_ancestors(string $path, int $owner): void
    {
        $current = DIRECTORY_SEPARATOR;
        $parts = array_filter(explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)), 'strlen');
        array_unshift($parts, '');
        foreach ($parts as $part) {
            if ($part !== '') {
                $current = rtrim($current, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $part;
            }
            clearstatcache(true, $current);
            $stat = @lstat($current);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
                || !in_array((int) $stat['uid'], [0, $owner], true)
                || ($stat['mode'] & 0022) !== 0) {
                throw new DomainException('journalfailed');
            }
        }
    }

    private function assert_private_directory(string $path, int $owner): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000
            || (int) $stat['uid'] !== $owner || ($stat['mode'] & 07777) !== self::DIRECTORY_MODE) {
            throw new DomainException('journalfailed');
        }
    }

    private function root(): string
    {
        if ($this->journalRoot !== null) {
            return $this->journalRoot;
        }

        $configured = rcmail::get_instance()->config->get('civicrm_filing_dir');
        if (!is_string($configured) || $configured === '' || $configured[0] !== DIRECTORY_SEPARATOR) {
            throw new DomainException('journalfailed');
        }
        $configured = rtrim($configured, DIRECTORY_SEPARATOR);
        if ($configured === '') {
            throw new DomainException('journalfailed');
        }

        $owner = $this->effective_uid();
        $root = realpath($configured);
        if ($root === false) {
            $parent = realpath(dirname($configured));
            $leaf = basename($configured);
            if ($parent === false || $leaf === '' || $leaf === '.' || $leaf === '..') {
                throw new DomainException('journalfailed');
            }
            $this->assert_safe_ancestors($parent, $owner);
            $candidate = rtrim($parent, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $leaf;
            if (!@mkdir($candidate, self::DIRECTORY_MODE)) {
                clearstatcache(true, $candidate);
                if (@lstat($candidate) === false) {
                    throw new DomainException('journalfailed');
                }
            }
            $root = realpath($candidate);
            if ($root === false || $root !== $candidate) {
                throw new DomainException('journalfailed');
            }
        }

        $this->assert_safe_ancestors(dirname($root), $owner);
        $this->assert_private_directory($root, $owner);
        $this->journalOwner = $owner;
        $this->journalRoot = $root;
        return $root;
    }

    private function directory(bool $create): string
    {
        $app = rcmail::get_instance();
        $account = (int) ($app->user->ID ?? 0);
        if ($account < 1) {
            throw new DomainException('invalidrequest');
        }
        $dir = $this->root() . '/account-' . $account;
        clearstatcache(true, $dir);
        if (@lstat($dir) === false) {
            if (!$create) {
                return $dir;
            }
            if (!@mkdir($dir, self::DIRECTORY_MODE) && @lstat($dir) === false) {
                throw new DomainException('journalfailed');
            }
        }
        $this->assert_private_directory($dir, $this->journalOwner);
        return $dir;
    }

    private function marker(string $identity): string
    {
        $site = rcmail::get_instance()->config->get('civicrm_api4_url');
        return 'mail-' . hash('sha256', $site . "\0" . $identity);
    }

    private function journal_stat_is_safe(array $stat): bool
    {
        return ($stat['mode'] & 0170000) === 0100000
            && (int) $stat['uid'] === $this->journalOwner
            && (int) $stat['nlink'] === 1
            && ($stat['mode'] & 07777) === self::FILE_MODE
            && (int) $stat['size'] <= self::MAX_STATE_BYTES;
    }

    private function open_journal(string $name, bool $create, string $error)
    {
        if (!preg_match('/^[a-z0-9-]+$/D', $name)) {
            throw new DomainException($error);
        }
        $path = $this->directory($create) . '/' . $name;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false && !$create) {
            return null;
        }
        if ($before === false) {
            $file = @fopen($path, 'x+b');
            if ($file) {
                if (!@chmod($path, self::FILE_MODE)) {
                    fclose($file);
                    throw new DomainException($error);
                }
                clearstatcache(true, $path);
                $before = @lstat($path);
                $opened = fstat($file);
                if ($before === false || $opened === false || !$this->journal_stat_is_safe($before)
                    || !$this->journal_stat_is_safe($opened)
                    || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']) {
                    fclose($file);
                    throw new DomainException($error);
                }
                return $file;
            }
            clearstatcache(true, $path);
            $before = @lstat($path);
        }
        if ($before === false || ($before['mode'] & 0170000) !== 0100000
            || (int) $before['uid'] !== $this->journalOwner || (int) $before['nlink'] !== 1
            || ($before['mode'] & 0022) !== 0 || (int) $before['size'] > self::MAX_STATE_BYTES) {
            throw new DomainException($error);
        }
        if (($before['mode'] & 07777) !== self::FILE_MODE) {
            if (!@chmod($path, self::FILE_MODE)) {
                throw new DomainException($error);
            }
            clearstatcache(true, $path);
            $before = @lstat($path);
        }
        if ($before === false || !$this->journal_stat_is_safe($before)) {
            throw new DomainException($error);
        }

        $file = @fopen($path, $create ? 'r+b' : 'rb');
        if (!$file) {
            throw new DomainException($error);
        }
        $opened = fstat($file);
        if ($opened === false || !$this->journal_stat_is_safe($opened)
            || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino']) {
            fclose($file);
            throw new DomainException($error);
        }
        return $file;
    }

    private function read_state($file, string $error): string
    {
        $stat = fstat($file);
        if ($stat === false || (int) $stat['size'] > self::MAX_STATE_BYTES || !rewind($file)) {
            throw new DomainException($error);
        }
        $state = stream_get_contents($file, self::MAX_STATE_BYTES + 1);
        if ($state === false || strlen($state) > self::MAX_STATE_BYTES) {
            throw new DomainException($error);
        }
        return $state;
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
        try {
            $file = $this->open_journal($this->marker($identity), false, 'journalfailed');
        } catch (DomainException $e) {
            if (!$this->journalWarningLogged) {
                error_log('[civicrm_addressbook] Ignoring unavailable or unsafe filing journal');
                $this->journalWarningLogged = true;
            }
            return false;
        }
        if (!$file) {
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
        $link = json_decode($this->read_state($file, 'journalfailed'), true);
        return !empty($link['id']) && !empty($link['cid']) ? $link : true;
    }

    private function mark_confirmed(string $identity, int $id, int $contact): array|bool
    {
        $file = null;
        try {
            $file = $this->open_journal($this->marker($identity), true, 'journalfailed');
            if (!flock($file, LOCK_EX)) {
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
                        $reply = $this->file($mail, (int) $id);
                        $reply['attachments'] = [];
                        foreach (array_unique($selected) as $part) {
                            $label = 'attachmentconfirmed';
                            try {
                                $this->attach($mail, $reply['id'], $parts[$part]);
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

    private function attach(rcube_message $mail, int $activity, array $part): void
    {
        $site = rcmail::get_instance()->config->get('civicrm_api4_url');
        $key = hash('sha256', implode("\0", [$site, $activity, $part['id']]));
        $file = $this->open_journal('attachment-' . $key, true, 'attachmentfailed');
        try {
            if (!flock($file, LOCK_EX)) {
                throw new DomainException('attachmentfailed');
            }
            $state = $this->read_state($file, 'attachmentfailed');
            if (ctype_digit($state) && (int) $state > 0) {
                return;
            }
            if ($state !== '') {
                throw new DomainException('attachmentuncertain');
            }
            $bytes = $mail->get_part_body($part['id']);
            if (!is_string($bytes) || ($bytes === '' && $part['size'] > 0)) {
                throw new DomainException('attachmentfailed');
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
        $key = hash('sha256', implode("\0", [$config->get('civicrm_api4_url'), $contact, $identity]));
        $file = $this->open_journal($key, true, 'journalfailed');
        try {
            if (!flock($file, LOCK_EX)) {
                throw new DomainException('journalfailed');
            }
            $state = $this->read_state($file, 'journalfailed');
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
        if (strlen($state) > self::MAX_STATE_BYTES || !rewind($file) || fwrite($file, $state) !== strlen($state)
            || !ftruncate($file, strlen($state)) || !fflush($file)) {
            throw new DomainException('journalfailed');
        }
    }
}
