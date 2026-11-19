<?php

class civicrm_addressbook_instance extends rcube_addressbook
{
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
            foreach ((array) $fields as $i => $field) {
                $term = trim((string) (is_array($value) ? ($value[$i] ?? '') : $value));
                if (mb_strlen($term) < 3) {
                    continue;
                }
                $strict = ($mode & self::SEARCH_STRICT) !== 0;
                $pattern = addcslashes($term, '\\\\%_') . '%';
                if (!$strict && !($mode & self::SEARCH_PREFIX)) {
                    $pattern = '%' . $pattern;
                }
                $columns = $field === '*' ? $map : array_intersect_key($map, [$field => true]);
                foreach ($columns as $column) {
                    $conditions[] = [$column, $strict ? '=' : 'LIKE', $strict ? $term : $pattern];
                }
            }
            if ($conditions) {
                $this->filter = [is_array($value) ? 'AND' : 'OR', $conditions];
            }
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
            $url = rtrim($config->get('civicrm_api4_url'), '/') . '/Contact/get';
            $client = new \GuzzleHttp\Client(['timeout' => 5, 'connect_timeout' => 2, 'allow_redirects' => false]);
            if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
                throw new \RuntimeException('HTTPS required');
            }
            $response = $client->post($url, [
                'headers' => ['X-Requested-With' => 'XMLHttpRequest', 'X-Civi-Auth' => 'Bearer ' . $config->get('civicrm_api4_key')],
                'form_params' => ['params' => json_encode($params, JSON_THROW_ON_ERROR)],
            ]);
            $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            if (isset($data['error_message']) || !is_array($data['values'] ?? null)) {
                throw new \UnexpectedValueException('Invalid CiviCRM response');
            }
            $result->count = (int) ($data['countMatched'] ?? $data['count'] ?? count($data['values']));
            foreach ($data['values'] as $contact) {
                $result->add(['ID' => $contact['id'], 'name' => $contact['display_name'],
                    'email' => $contact['email_primary.email'], 'phone' => $contact['phone_primary.phone'] ?? '']);
            }
        } catch (\Exception $e) {
            $this->set_error(self::ERROR_NO_CONNECTION, 'CiviCRM contact lookup failed.');
        }
        return $result;
    }
}
