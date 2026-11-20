<?php

class civicrm_addressbook_instance extends rcube_addressbook
{
    private const SEARCH_MAX_BYTES = 256;
    private const SEARCH_MAX_CHARS = 128;
    private const SEARCH_MAX_WORDS = 10;
    private const SEARCH_MAX_PREDICATES = 24;

    public $primary_key = 'ID';
    public $readonly = true;
    public $ready = true;
    public $searchonly = true;
    public $coltypes = ['name' => ['limit' => 1], 'email' => ['limit' => 1], 'phone' => ['limit' => 1]];

    private $filter;
    private $result;

    public function get_name() { return 'CiviCRM'; }
    public function get_result() { return $this->result; }
    public function get_search_set() { return $this->filter; }
    public function set_search_set($filter): void { $this->filter = $filter; }

    public function reset(): void
    {
        $this->filter = null;
        $this->result = null;
    }

    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        $this->reset();
        if ($fields === 'ID' || $fields === ['ID']) {
            $ids = is_array($value) ? $value : explode(',', (string) $value);
            $ids = array_values(array_filter($ids, static fn($id) => ctype_digit((string) $id)));
            if ($ids) {
                $this->filter = ['id', 'IN', $ids];
            }
        } else {
            $map = ['name' => 'display_name', 'firstname' => 'first_name', 'surname' => 'last_name', 'email' => 'email_primary.email'];
            $conditions = [];
            $predicateCount = 0;
            foreach ((array) $fields as $i => $field) {
                $term = (string) (is_array($value) ? ($value[$i] ?? '') : $value);
                if (strlen($term) > self::SEARCH_MAX_BYTES) {
                    return $this->reject_search($select, $nocount);
                }
                $term = trim($term);
                if (!mb_check_encoding($term, 'UTF-8') || mb_strlen($term) > self::SEARCH_MAX_CHARS) {
                    return $this->reject_search($select, $nocount);
                }
                if (mb_strlen($term) < 3) {
                    continue;
                }
                $strict = ($mode & self::SEARCH_STRICT) !== 0;
                $pattern = addcslashes($term, '\\\\%_') . '%';
                if (!$strict && !($mode & self::SEARCH_PREFIX)) {
                    $pattern = '%' . $pattern;
                }
                $columns = $field === '*' ? $map : array_intersect_key($map, [$field => true]);
                $predicateCount += count($columns);
                if ($predicateCount > self::SEARCH_MAX_PREDICATES) {
                    return $this->reject_search($select, $nocount);
                }
                $alternatives = [];
                foreach ($columns as $column) {
                    $alternatives[] = [$column, $strict ? '=' : 'LIKE', $strict ? $term : $pattern];
                }
                if (isset($columns['name']) && !$strict) {
                    // CiviCRM display names can start with a salutation. Match each
                    // typed word against the underlying names in either order.
                    $name = preg_replace('/^(?:Frau|Herr)\s+/iu', '', $term);
                    $words = preg_split('/[\s,]+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
                    if ($words === false || count($words) > self::SEARCH_MAX_WORDS) {
                        return $this->reject_search($select, $nocount);
                    }
                    if ($words && mb_strlen(implode('', $words)) >= 3) {
                        $predicateCount += count($words) * 2;
                        if ($predicateCount > self::SEARCH_MAX_PREDICATES) {
                            return $this->reject_search($select, $nocount);
                        }
                        $parts = [];
                        foreach ($words as $word) {
                            $prefix = addcslashes($word, '\\\\%_') . '%';
                            $parts[] = ['OR', [['first_name', 'LIKE', $prefix], ['last_name', 'LIKE', $prefix]]];
                        }
                        $alternatives[] = ['AND', $parts];
                    }
                }
                if ($alternatives) {
                    $conditions[] = count($alternatives) === 1 ? $alternatives[0] : ['OR', $alternatives];
                }
            }
            if ($conditions) {
                $this->filter = [is_array($value) ? 'AND' : 'OR', $conditions];
            }
        }
        if (!$this->filter && rcmail::get_instance()->config->get('civicrm_log_searches', false)) {
            error_log('[civicrm_addressbook] search skipped: no eligible field or search term');
        }
        return $select ? $this->list_records(null, 0, $nocount) : $this->count();
    }

    private function reject_search($select, $nocount)
    {
        $this->filter = null;
        $this->set_error(self::ERROR_SEARCH, 'CiviCRM contact search is too complex.');
        if (rcmail::get_instance()->config->get('civicrm_log_searches', false)) {
            error_log('[civicrm_addressbook] search rejected: complexity limit exceeded');
        }
        return $select ? $this->list_records(null, 0, $nocount) : $this->count();
    }

    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $size = max(1, (int) $this->page_size);
        $offset = max(0, $this->list_page - 1) * $size;
        $limit = $subset ? min(abs($subset), $size) : $size;
        if ($subset < 0) {
            $available = max(0, min($size, $this->count()->count - $offset));
            $offset += max(0, $available - $limit);
        }
        return $this->result = $this->query($this->filter, $offset, $limit, false, !$nocount);
    }

    public function count()
    {
        return $this->result = $this->query($this->filter, 0, 1, true, true);
    }

    public function get_record($id, $assoc = false)
    {
        $filter = ctype_digit((string) $id) ? ['id', '=', (int) $id] : null;
        $this->result = $this->query($filter, 0, 1, false, false);
        return $assoc ? $this->result->first() : $this->result;
    }

    private function query($filter, $offset, $limit, $countOnly, $includeCount)
    {
        $result = new rcube_result_set(0, $offset);
        if (!$filter) {
            return $result;
        }
        $status = 0;
        $stage = 'configuration';
        $diagnostic = '';
        $select = $countOnly ? [] : ['id', 'display_name', 'email_primary.email', 'phone_primary.phone'];
        if ($includeCount) {
            $select[] = 'row_count';
        }
        $params = [
            'select' => $select,
            'where' => [['is_deleted', '=', false], ['is_deceased', '=', false],
                ['email_primary.email', 'IS NOT EMPTY'], $filter],
            'orderBy' => ['display_name' => 'ASC', 'id' => 'ASC'],
            'offset' => $offset, 'limit' => $limit,
        ];
        try {
            $config = rcmail::get_instance()->config;
            $stage = 'transport';
            $response = civicrm_api4_http::post('Contact/get', $params);
            $status = $response->getStatusCode();
            $stage = 'http';
            if ($status >= 300 && $status < 400) {
                $path = parse_url($response->getHeaderLine('Location'), PHP_URL_PATH);
                $stage = basename((string) $path) === 'wp-login.php' ? 'wordpress-login-redirect' : 'redirect';
            }
            $body = (string) $response->getBody();
            $data = json_decode($body, true);
            if ($status !== 200 || !is_array($data) || isset($data['error_message'])) {
                $format = is_array($data) ? 'json' : (str_starts_with(ltrim($body), '<') ? 'markup' : 'other');
                $diagnostic = ' body=' . $format;
                $id = $data['error_id'] ?? null;
                $message = $data['error_message'] ?? null;
                if (!$id && is_string($message) && preg_match('/Error ID:\s*([a-z0-9_-]+)/i', $message, $match)) {
                    $id = $match[1];
                }
                if (is_string($id) && preg_match('/^[a-z0-9_-]{1,80}$/iD', $id)) {
                    $diagnostic .= ' error_id=' . $id;
                }
                if (is_string($message)) {
                    $stage = 'civicrm-error';
                } elseif ($status === 200) {
                    $stage = 'decode-json';
                }
                throw new \UnexpectedValueException();
            }
            $stage = 'api-response';
            if (!is_array($data['values'] ?? null)) {
                throw new \UnexpectedValueException('Invalid CiviCRM response');
            }
            $result->count = (int) ($data['countMatched'] ?? $data['count'] ?? count($data['values']));
            foreach ($data['values'] as $contact) {
                $result->add(['ID' => $contact['id'], 'name' => $contact['display_name'],
                    'email' => $contact['email_primary.email'], 'phone' => $contact['phone_primary.phone'] ?? '']);
            }
            if ($config->get('civicrm_log_searches', false)) {
                error_log(sprintf('[civicrm_addressbook] Contact.get HTTP %d ok rows=%d count=%d offset=%d', $status, count($data['values']), $result->count, $offset));
            }
        } catch (\Exception $e) {
            // Exception messages and response bodies may contain contacts or credentials.
            $detail = get_class($e);
            if ($e instanceof \GuzzleHttp\Exception\ConnectException) {
                $context = $e->getHandlerContext();
                $detail .= ' curl_errno=' . (int) ($context['errno'] ?? 0);
                foreach (['namelookup_time', 'connect_time', 'pretransfer_time', 'starttransfer_time', 'total_time'] as $metric) {
                    if (isset($context[$metric])) {
                        $detail .= sprintf(' %s_ms=%d', $metric, round($context[$metric] * 1000));
                    }
                }
            }
            error_log(sprintf('[civicrm_addressbook] Contact.get failed stage=%s http=%d%s %s', $stage, $status, $diagnostic, $detail));
            $this->set_error(self::ERROR_NO_CONNECTION, 'CiviCRM contact lookup failed.');
        }
        return $result;
    }
}

class civicrm_api4_http
{
    public static function post(string $route, array $params)
    {
        $config = rcmail::get_instance()->config;
        $url = rtrim($config->get('civicrm_api4_url'), '/') . '/' . $route;
        return self::send($url, ['form_params' => ['params' => json_encode($params, JSON_THROW_ON_ERROR)]]);
    }

    public static function attach(int $activity, string $name, string $type, string $bytes)
    {
        $base = rtrim(rcmail::get_instance()->config->get('civicrm_api4_url'), '/');
        $url = preg_replace('~(/|%2f)api4$~i', '${1}rest', $base, 1, $replaced);
        if (!$replaced) {
            throw new RuntimeException('Invalid API base route');
        }
        // Attachment.create accepts the decoded bytes as a regular POST field.
        $fields = ['entity' => 'Attachment', 'action' => 'create', 'json' => '1',
            'entity_table' => 'civicrm_activity', 'entity_id' => (string) $activity,
            'name' => $name, 'mime_type' => $type, 'content' => $bytes];
        $parts = [];
        foreach ($fields as $key => $value) {
            $parts[] = ['name' => $key, 'contents' => $value];
        }
        return self::send($url, ['multipart' => $parts], 60);
    }

    private static function send(string $url, array $options, int $timeout = 12)
    {
        $config = rcmail::get_instance()->config;
        $client = new \GuzzleHttp\Client(['timeout' => $timeout, 'connect_timeout' => 4, 'allow_redirects' => false]);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('HTTPS required');
        }
        $headers = ['X-Requested-With' => 'XMLHttpRequest', 'X-Civi-Auth' => 'Bearer ' . $config->get('civicrm_api4_key')];
        if ($siteKey = $config->get('civicrm_site_key')) {
            $headers['X-Civi-Key'] = $siteKey;
        }

        return $client->post($url, $options + [
            'http_errors' => false,
            'headers' => $headers,
        ]);
    }
}
