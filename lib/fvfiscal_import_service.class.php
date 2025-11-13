<?php
/*
 * Focus Science DF-e importer service.
 */

require_once __DIR__ . '/../class/FvFocusJob.class.php';
require_once __DIR__ . '/../class/FvNfeIn.class.php';
require_once __DIR__ . '/../lib/fvfiscal.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT . '/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/stock/class/mouvementstock.class.php';

/**
 * Pull DF-e documents from Focus Science API and persist inside Dolibarr.
 */
class FvFiscalScienceImporter
{
    /** @var DoliDB */
    private $db;

    /** @var Conf */
    private $conf;

    /** @var Translate|null */
    private $langs;

    /** @var int */
    private $minInterval;

    /** @var bool */
    private $autoScience;

    /**
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        global $conf, $langs;

        $this->db = $db;
        $this->conf = $conf;
        $this->langs = $langs;

        $this->minInterval = $this->resolveMinInterval();
        $this->autoScience = $this->resolveAutoScience();
    }

    /**
     * Cron entry point executed by Dolibarr scheduler.
     *
     * @param int $fk_cronjob
     * @return int
     */
    public function run($fk_cronjob = 0)
    {
        $systemUser = $this->resolveSystemUser();
        if (!$systemUser) {
            dol_syslog(__METHOD__ . ': unable to resolve system user', LOG_ERR);
            return -1;
        }

        $lastJob = $this->fetchLastJob();
        if ($lastJob && !$this->shouldExecute($lastJob)) {
            dol_syslog(__METHOD__ . ': skipped due to FV_FISCAL_IMPORT_CRON_MIN policy', LOG_DEBUG);
            return 0;
        }

        $job = $this->createJob($systemUser, $lastJob);
        if (!$job) {
            return -1;
        }

        $startedAt = dol_now();
        $job->started_at = $startedAt;
        $job->attempt_count = (int) (($lastJob['attempt_count'] ?? 0) + 1);
        $job->update($systemUser);

        try {
            $collection = $this->collectDocuments($lastJob);
            $documents = $collection['documents'];
            $persistResult = $this->persistDocuments($systemUser, $documents, $collection['next_since']);

            $job->status = 1;
            $job->finished_at = dol_now();
            $job->response_json = json_encode(array_merge($persistResult, array(
                'fetched' => count($documents),
                'auto_science' => $this->autoScience,
                'next_since' => $persistResult['next_since'] ?? null,
            )));
            $job->update($systemUser);

            return (int) $persistResult['processed'];
        } catch (Throwable $throwable) {
            $job->status = -1;
            $job->finished_at = dol_now();
            $job->error_message = $throwable->getMessage();
            $job->response_json = json_encode(array(
                'exception' => get_class($throwable),
                'message' => $throwable->getMessage(),
            ));
            $job->update($systemUser);

            dol_syslog(__METHOD__ . ': ' . $throwable->getMessage(), LOG_ERR);

            return -1;
        }
    }

    /**
     * Determine minimum interval between executions.
     *
     * @return int Minutes
     */
    private function resolveMinInterval()
    {
        $value = getenv('FV_FISCAL_IMPORT_CRON_MIN');
        if ($value === false || trim((string) $value) === '') {
            $value = $this->getConfigString('FVFISCAL_IMPORT_CRON_MIN', '15');
        }

        $minutes = (int) $value;
        if ($minutes <= 0) {
            $minutes = 15;
        }

        return $minutes;
    }

    /**
     * Determine if automatic creation of supplier invoice/stock should happen.
     *
     * @return bool
     */
    private function resolveAutoScience()
    {
        $value = getenv('FV_FISCAL_IMPORT_SCIENCE_AUTO');
        if ($value === false) {
            $value = $this->getConfigString('FVFISCAL_IMPORT_SCIENCE_AUTO', '');
        }

        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower((string) $value);
        if ($normalized === '1' || $normalized === 'true' || $normalized === 'yes' || $normalized === 'on') {
            return true;
        }

        return false;
    }

    /**
     * Retrieve last registered job execution.
     *
     * @return array<string, mixed>|null
     */
    private function fetchLastJob()
    {
        $sql = 'SELECT rowid, attempt_count, status, started_at, finished_at, response_json';
        $sql .= ' FROM ' . MAIN_DB_PREFIX . "fv_focus_job";
        $sql .= " WHERE job_type = 'science.dfe.import'";
        $sql .= ' AND entity IN (' . getEntity('fv_focus_job') . ')';
        $sql .= ' ORDER BY rowid DESC LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__METHOD__ . ': ' . $this->db->lasterror(), LOG_ERR);
            return null;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        if (!$obj) {
            return null;
        }

        return array(
            'id' => (int) $obj->rowid,
            'attempt_count' => (int) $obj->attempt_count,
            'status' => (int) $obj->status,
            'started_at' => $this->db->jdate($obj->started_at),
            'finished_at' => $this->db->jdate($obj->finished_at),
            'response' => $obj->response_json,
        );
    }

    /**
     * Check whether execution is allowed according to cron min interval.
     *
     * @param array<string, mixed> $job
     * @return bool
     */
    private function shouldExecute(array $job)
    {
        $reference = (int) ($job['finished_at'] ?: $job['started_at']);
        if ($reference <= 0) {
            return true;
        }

        $elapsed = dol_now() - $reference;
        return $elapsed >= ($this->minInterval * 60);
    }

    /**
     * Register a Focus job to track the execution.
     *
     * @param User                       $user
     * @param array<string, mixed>|null  $lastJob
     * @return FvFocusJob|null
     */
    private function createJob(User $user, $lastJob)
    {
        $payload = array(
            'auto_science' => $this->autoScience,
            'min_interval' => $this->minInterval,
        );
        if ($lastJob && !empty($lastJob['response'])) {
            $payload['previous_response'] = $lastJob['response'];
        }

        $job = new FvFocusJob($this->db);
        $job->entity = $this->conf->entity;
        $job->status = 0;
        $job->job_type = 'science.dfe.import';
        $job->attempt_count = $lastJob ? ((int) $lastJob['attempt_count']) : 0;
        $job->payload_json = json_encode($payload);
        $job->scheduled_for = dol_now();

        if ($job->create($user) <= 0) {
            dol_syslog(__METHOD__ . ': unable to create focus job - ' . $job->error, LOG_ERR);
            return null;
        }

        return $job;
    }

    /**
     * Extract checkpoint value from last job response.
     *
     * @param array<string, mixed>|null $lastJob
     * @return string|null
     */
    private function resolveCheckpoint($lastJob)
    {
        if (!$lastJob || empty($lastJob['response'])) {
            return null;
        }

        $decoded = json_decode((string) $lastJob['response'], true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!empty($decoded['next_since']) && is_string($decoded['next_since'])) {
            return $decoded['next_since'];
        }

        if (!empty($decoded['checkpoint']) && is_string($decoded['checkpoint'])) {
            return $decoded['checkpoint'];
        }

        if (!empty($decoded['last_nsu']) && is_string($decoded['last_nsu'])) {
            return $decoded['last_nsu'];
        }

        return null;
    }

    /**
     * Collect documents from remote Focus Science service.
     *
     * @param array<string, mixed>|null $lastJob
     * @return array{documents: array<int, array<string, mixed>>, next_since: (string|null)}
     */
    private function collectDocuments($lastJob)
    {
        $endpoint = $this->resolveEndpoint();
        if ($endpoint === '') {
            throw new RuntimeException('Focus Science endpoint not configured');
        }

        $since = $this->resolveCheckpoint($lastJob);
        $nextSince = $since;
        $documents = array();
        $nextToken = null;

        do {
            $query = array();
            if ($since) {
                $query['since'] = $since;
            }
            if ($nextToken) {
                $query['page_token'] = $nextToken;
            }

            $response = $this->performRequest($endpoint, '/science/dfes', $query);
            if (!is_array($response)) {
                throw new RuntimeException('Unexpected response from Focus Science');
            }

            if (!empty($response['data']) && is_array($response['data'])) {
                foreach ($response['data'] as $item) {
                    if (is_array($item)) {
                        $documents[] = $item;
                    }
                }
            }

            $nextToken = isset($response['next_page_token']) && is_string($response['next_page_token']) ? $response['next_page_token'] : null;

            if (!$nextToken && !empty($response['pagination']) && is_array($response['pagination'])) {
                $nextToken = isset($response['pagination']['next']) ? (string) $response['pagination']['next'] : null;
            }

            $candidateSince = null;
            foreach (array('next_since', 'checkpoint', 'last_checkpoint', 'last_nsu', 'since') as $key) {
                if (!empty($response[$key]) && is_string($response[$key])) {
                    $candidateSince = (string) $response[$key];
                    break;
                }
            }
            if ($candidateSince !== null) {
                $nextSince = $candidateSince;
            }
        } while ($nextToken);

        return array(
            'documents' => $documents,
            'next_since' => $nextSince,
        );
    }

    /**
     * Persist DF-e documents into Dolibarr tables.
     *
     * @param User                                     $user
     * @param array<int, array<string, mixed>>         $documents
     * @param string|null                              $initialSince
     * @return array<string, mixed>
     */
    private function persistDocuments(User $user, array $documents, $initialSince = null)
    {
        $created = 0;
        $updated = 0;
        $invoiceCount = 0;
        $stockCount = 0;
        $nextSince = $initialSince;
        $processed = 0;

        foreach ($documents as $document) {
            $processed++;

            $nfe = $this->findOrCreateNfeIn($user, $document, $created, $updated);

            if ($this->autoScience && $nfe instanceof FvNfeIn) {
                $invoiceCount += $this->maybeCreateSupplierInvoice($user, $nfe, $document);
                $stockCount += $this->maybeApplyStockMovement($user, $nfe, $document);
            }

            $checkpoint = $this->extractString($document, array('checkpoint', 'nsu', 'last_nsu', 'last_update', 'issued_at', 'issue_at'));
            if ($checkpoint !== '') {
                $nextSince = $checkpoint;
            }
        }

        return array(
            'processed' => $processed,
            'created' => $created,
            'updated' => $updated,
            'invoices' => $invoiceCount,
            'stock_moves' => $stockCount,
            'next_since' => $nextSince,
        );
    }

    /**
     * Find existing NFeIn or create a new entry.
     *
     * @param User                   $user
     * @param array<string, mixed>   $data
     * @param int                    $createdCounter Reference incremented when a record is created
     * @param int                    $updatedCounter Reference incremented when a record is updated
     * @return FvNfeIn|null
     */
    private function findOrCreateNfeIn(User $user, array $data, &$createdCounter, &$updatedCounter)
    {
        $nfeKey = $this->extractString($data, array('nfe_key', 'chave', 'access_key'));
        if ($nfeKey === '') {
            dol_syslog(__METHOD__ . ': skipping DF-e without key', LOG_WARNING);
            return null;
        }

        $rowId = $this->findNfeInIdByKey($nfeKey);
        $nfe = new FvNfeIn($this->db);

        if ($rowId > 0 && $nfe->fetch($rowId) > 0) {
            $changed = $this->applyNfeInData($nfe, $data);
            if ($changed) {
                if ($nfe->update($user) < 0) {
                    throw new RuntimeException($nfe->error ?: 'Unable to update NF-e entry');
                }
                $updatedCounter++;
            }

            return $nfe;
        }

        $nfe->entity = $this->conf->entity;
        $nfe->status = 0;
        $nfe->nfe_key = $nfeKey;
        $nfe->ref = $this->extractString($data, array('ref', 'number', 'numero', 'nfe_number', 'reference'), substr($nfeKey, -9));
        $nfe->fk_soc = $this->resolveThirdparty($user, $data);
        if ($nfe->fk_soc <= 0) {
            throw new RuntimeException('Unable to resolve thirdparty for NF-e ' . $nfeKey);
        }
        $nfe->doc_type = $this->extractString($data, array('doc_type', 'document_type'), 'nfe');
        $this->applyNfeInData($nfe, $data);

        if ($nfe->create($user) <= 0) {
            throw new RuntimeException($nfe->error ?: 'Unable to create NF-e entry');
        }

        $createdCounter++;

        return $nfe;
    }

    /**
     * Try to resolve thirdparty identifier from DF-e payload.
     *
     * @param array<string, mixed> $data
     * @return int
     */
    protected function resolveThirdparty(User $user, array $data)
    {
        if (!empty($data['fk_soc'])) {
            return (int) $data['fk_soc'];
        }

        $document = $this->normalizeDocument($this->extractString($data, array('cnpj', 'cpf', 'document', 'identifier', 'issuer_document', 'emitente_documento')));
        if ($document === '') {
            throw new RuntimeException('DF-e issuer document not available');
        }

        $existingId = $this->findThirdpartyIdByDocument($document);
        if ($existingId > 0) {
            return $existingId;
        }

        $societe = $this->instantiateSociete();
        $societe->entity = $this->conf->entity;
        $societe->status = 1;
        $societe->client = 0;
        $societe->fournisseur = 1;

        $name = $this->extractString($data, array(
            'issuer_name',
            'emitente_nome',
            'emitente_razao_social',
            'razao_social',
            'social_name',
            'company_name',
            'name',
            'nome',
            'xNome',
        ), $document);
        $societe->name = $name;
        $societe->nom = $name;

        $addressData = $this->extractAddressPayload($data);
        $street = $this->extractString($addressData, array('street', 'logradouro', 'address', 'endereco'));
        $number = $this->extractString($addressData, array('number', 'numero'));
        $neighborhood = $this->extractString($addressData, array('neighborhood', 'bairro'));
        $composed = trim(trim($street . ' ' . $number) . ' ' . $neighborhood);
        if ($composed === '') {
            $composed = $this->extractString($data, array('address', 'issuer_address'));
        }
        $societe->address = $composed;
        $societe->zip = $this->extractString($addressData, array('zip', 'cep', 'postal_code', 'zipcode'));
        $societe->town = $this->extractString($addressData, array('city', 'municipio', 'cidade', 'town'));
        $societe->state_code = $this->extractString($addressData, array('state', 'uf', 'state_code', 'estado'));
        $societe->country_code = $this->extractString($addressData, array('country', 'pais', 'country_code'));

        $email = $this->extractString($data, array('email', 'issuer_email', 'emitente_email', 'contact_email'));
        $societe->email = $email;

        $societe->tva_intra = $document;
        $societe->idprof2 = $document;
        if (strlen($document) === 11) {
            $societe->idprof1 = $document;
        }

        if (method_exists($societe, 'getAvailableCode')) {
            $code = $societe->getAvailableCode('fournisseur');
            if (is_string($code) && $code !== '') {
                $societe->code_fournisseur = $code;
            }
        }

        $note = 'Automatically created by Focus Science importer';
        if (!empty($societe->note_private)) {
            $societe->note_private .= "\n" . $note;
        } else {
            $societe->note_private = $note;
        }

        if ($societe->create($user) <= 0) {
            throw new RuntimeException($societe->error ?: 'Unable to create thirdparty');
        }

        if (!empty($societe->id)) {
            return (int) $societe->id;
        }

        if (!empty($societe->rowid)) {
            return (int) $societe->rowid;
        }

        return 0;
    }

    /**
     * Apply DF-e data to Dolibarr NF-e object.
     *
     * @param FvNfeIn                $document
     * @param array<string, mixed>   $data
     * @return bool
     */
    private function applyNfeInData(FvNfeIn $document, array $data)
    {
        $changed = false;

        $issue = $this->parseDatetime($data['issue_at'] ?? ($data['issued_at'] ?? ($data['data_emissao'] ?? null)));
        if ($issue && (int) $document->issue_at !== (int) $issue) {
            $document->issue_at = $issue;
            $changed = true;
        }
        $arrival = $this->parseDatetime($data['arrival_at'] ?? ($data['data_entrada'] ?? null));
        if ($arrival && (int) $document->arrival_at !== (int) $arrival) {
            $document->arrival_at = $arrival;
            $changed = true;
        }

        $fields = array(
            'nfe_key' => array('nfe_key', 'chave', 'chave_nfe'),
            'ref' => array('ref', 'referencia', 'reference'),
            'operation_type' => array('operation_type', 'tipo_operacao'),
        );
        foreach ($fields as $field => $keys) {
            $value = $this->extractString($data, $keys);
            if ($value !== '' && $document->{$field} !== $value) {
                $document->{$field} = $value;
                $changed = true;
            }
        }

        $amountFields = array(
            'total_products' => array('total_products', 'valor_produtos'),
            'total_tax' => array('total_tax', 'valor_impostos', 'total_taxes'),
            'total_amount' => array('total_amount', 'valor_total'),
        );
        foreach ($amountFields as $field => $keys) {
            $value = $this->extractNumeric($data, $keys);
            if ($value !== null && price2num($document->{$field}, 'MT') != price2num($value, 'MT')) {
                $document->{$field} = price2num($value, 'MT');
                $changed = true;
            }
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json !== false && $document->json_payload !== $json) {
            $document->json_payload = $json;
            $changed = true;
        }

        return $changed;
    }

    /**
     * Create supplier invoice if data is available.
     *
     * @param User      $user
     * @param FvNfeIn   $nfe
     * @param array     $data
     * @return int 1 when invoice created, 0 otherwise
     */
    private function maybeCreateSupplierInvoice(User $user, FvNfeIn $nfe, array $data)
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            return 0;
        }

        if ($this->supplierInvoiceExists($nfe)) {
            return 0;
        }

        $invoice = new FactureFournisseur($this->db);
        $invoice->socid = $nfe->fk_soc;
        $invoice->entity = $nfe->entity;
        $invoice->date = $nfe->issue_at ?: dol_now();
        $invoice->ref_supplier = $nfe->ref ?: $nfe->nfe_key;
        $invoice->libelle = 'NF-e ' . ($nfe->ref ?: $nfe->nfe_key);

        if ($invoice->create($user) <= 0) {
            dol_syslog(__METHOD__ . ': unable to create supplier invoice - ' . $invoice->error, LOG_WARNING);
            return 0;
        }

        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $desc = $this->extractString($item, array('description', 'descricao', 'product', 'name'), 'NF-e item');
            $qty = (float) ($item['quantity'] ?? $item['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $total = $this->extractNumeric($item, array('total', 'valor_total', 'amount'));
            $unit = $this->extractNumeric($item, array('unit_price', 'valor_unitario', 'price'));
            if ($unit === null && $total !== null) {
                $unit = $total / $qty;
            }
            if ($unit === null) {
                $unit = $nfe->total_amount > 0 ? ($nfe->total_amount / count($data['items'])) : 0;
            }

            $result = $invoice->addline(
                $desc,
                price2num($unit, 'MU'),
                $qty,
                0,
                0,
                0,
                $this->extractInt($item, array('fk_product', 'product_id', 'id_product')),
                0,
                '',
                '',
                0,
                0,
                0,
                price2num($unit, 'MU'),
                $desc,
                0,
                array(),
                '',
                '',
                0,
                0,
                0,
                'HT',
                0,
                0,
                -1,
                0,
                0,
                0
            );

            if ($result <= 0) {
                dol_syslog(__METHOD__ . ': unable to add invoice line - ' . $invoice->error, LOG_WARNING);
            }
        }

        return 1;
    }

    /**
     * Create stock movement when product items available.
     *
     * @param User      $user
     * @param FvNfeIn   $nfe
     * @param array     $data
     * @return int Number of stock movements performed
     */
    private function maybeApplyStockMovement(User $user, FvNfeIn $nfe, array $data)
    {
        if (empty($data['items']) || !is_array($data['items'])) {
            return 0;
        }

        $count = 0;
        foreach ($data['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = $this->extractInt($item, array('fk_product', 'product_id', 'id_product'));
            if ($productId <= 0) {
                continue;
            }
            $qty = (float) ($item['quantity'] ?? $item['qty'] ?? 0);
            if ($qty == 0) {
                continue;
            }

            if ($this->stockMovementExists($nfe, $productId)) {
                continue;
            }

            $movement = new MouvementStock($this->db);
            if (!method_exists($movement, '_create')) {
                dol_syslog(__METHOD__ . ': stock movement creation not supported on this Dolibarr version', LOG_WARNING);
                continue;
            }
            $movement->origin = $nfe;
            $movement->origin_type = 'fvnfein';

            $label = 'NF-e ' . ($nfe->ref ?: $nfe->nfe_key);
            $movementId = $movement->_create($user, $productId, 0, $qty, $label, '', $nfe->entity, 0, null, '', '', 0);
            if ($movementId > 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Perform HTTP request using Dolibarr helper.
     *
     * @param string                   $endpoint
     * @param string                   $path
     * @param array<string, string>    $query
     * @return array<string, mixed>|null
     */
    private function performRequest($endpoint, $path, array $query)
    {
        $url = rtrim($endpoint, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = array('Accept: application/json');
        $token = $this->resolveToken();
        if ($token !== '') {
            if (stripos($token, 'bearer ') === 0 || stripos($token, 'basic ') === 0) {
                $headers[] = 'Authorization: ' . $token;
            } else {
                $headers[] = 'Authorization: Bearer ' . $token;
            }
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension not available');
        }

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ));

        $content = curl_exec($curl);
        if ($content === false) {
            $error = curl_error($curl);
            $code = curl_errno($curl);
            curl_close($curl);
            throw new RuntimeException('cURL error ' . $code . ': ' . $error);
        }

        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode >= 400) {
            throw new RuntimeException('HTTP ' . $httpCode . ' received from Focus Science');
        }

        if ($content === '' || $content === null) {
            return array();
        }

        $decoded = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Invalid JSON returned by Focus Science: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Resolve API endpoint.
     *
     * @return string
     */
    private function resolveEndpoint()
    {
        if (!empty($this->conf->global->FVFISCAL_FOCUS_ENDPOINT)) {
            return $this->conf->global->FVFISCAL_FOCUS_ENDPOINT;
        }

        return '';
    }

    /**
     * Resolve API token either from configuration or environment.
     *
     * @return string
     */
    private function resolveToken()
    {
        $token = '';
        if (!empty($this->conf->global->FVFISCAL_FOCUS_TOKEN)) {
            $token = fvfiscal_decrypt_value($this->conf->global->FVFISCAL_FOCUS_TOKEN);
        } elseif (($env = getenv('FV_FISCAL_FOCUS_TOKEN')) !== false) {
            $token = (string) $env;
        }

        return trim((string) $token);
    }

    /**
     * Find NF-e rowid by access key.
     *
     * @param string $nfeKey
     * @return int
     */
    private function findNfeInIdByKey($nfeKey)
    {
        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "fv_nfe_in";
        $sql .= ' WHERE entity IN (' . getEntity('fv_nfe_in') . ')';
        $sql .= " AND nfe_key = '" . $this->db->escape($nfeKey) . "'";
        $sql .= ' ORDER BY rowid DESC LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->rowid : 0;
    }

    /**
     * Attempt to find existing thirdparty matching the provided identifier.
     *
     * @param string $document
     * @return int
     */
    protected function findThirdpartyIdByDocument($document)
    {
        if ($document === '') {
            return 0;
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "societe";
        $sql .= ' WHERE entity IN (' . getEntity('societe') . ')';
        $escaped = $this->db->escape($document);
        $sql .= " AND (tva_intra = '" . $escaped . "'";
        $sql .= " OR idprof1 = '" . $escaped . "'";
        $sql .= " OR idprof2 = '" . $escaped . "')";
        $sql .= ' ORDER BY rowid ASC LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }

        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $obj ? (int) $obj->rowid : 0;
    }

    /**
     * Instantiate Societe object, allowing overrides during testing.
     *
     * @return Societe
     */
    protected function instantiateSociete()
    {
        return new Societe($this->db);
    }

    /**
     * Parse numeric values from payload.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     * @return float|null
     */
    private function extractNumeric(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Extract integer value from payload.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     * @return int
     */
    private function extractInt(array $data, array $keys)
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            if (is_numeric($data[$key])) {
                return (int) $data[$key];
            }
        }

        return 0;
    }

    /**
     * Extract string from payload.
     *
     * @param array<string, mixed> $data
     * @param array<int, string>   $keys
     * @param string               $default
     * @return string
     */
    private function extractString(array $data, array $keys, $default = '')
    {
        foreach ($keys as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            if (is_array($data[$key])) {
                continue;
            }
            $value = trim((string) $data[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Resolve best effort address payload from DF-e data.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function extractAddressPayload(array $data)
    {
        $candidates = array();
        foreach (array('address', 'issuer_address', 'emitente_endereco', 'ender_emit', 'endereco') as $key) {
            if (!empty($data[$key]) && is_array($data[$key])) {
                $candidates[] = $data[$key];
            }
        }

        if (!empty($data['issuer']) && is_array($data['issuer']) && !empty($data['issuer']['address']) && is_array($data['issuer']['address'])) {
            $candidates[] = $data['issuer']['address'];
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return array();
    }

    /**
     * Normalize document identifiers by stripping non-numeric characters.
     *
     * @param string $value
     * @return string
     */
    protected function normalizeDocument($value)
    {
        if ($value === '') {
            return '';
        }

        $normalized = preg_replace('/[^0-9]/', '', (string) $value);
        if (!is_string($normalized)) {
            return '';
        }

        return $normalized;
    }

    /**
     * Parse datetime string to Dolibarr timestamp.
     *
     * @param mixed $value
     * @return int|null
     */
    private function parseDatetime($value)
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;
            if ($timestamp > 0 && $timestamp < 2147483647) {
                return $timestamp;
            }
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    /**
     * Resolve system user used to create records.
     *
     * @return User|null
     */
    private function resolveSystemUser()
    {
        global $user;

        if ($user instanceof User && $user->id > 0) {
            return $user;
        }

        $system = new User($this->db);
        if ($system->fetch(0, '', '', '', '', 'admin') > 0) {
            return $system;
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "user WHERE admin = 1 ORDER BY rowid ASC LIMIT 1";
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($obj && $system->fetch((int) $obj->rowid) > 0) {
                return $system;
            }
        }

        if (!empty($user) && $user instanceof User) {
            return $user;
        }

        return null;
    }

    /**
     * Check if a supplier invoice already exists for the NF-e.
     *
     * @param FvNfeIn $nfe
     * @return bool
     */
    private function supplierInvoiceExists(FvNfeIn $nfe)
    {
        $ref = $nfe->ref ?: $nfe->nfe_key;
        if ($ref === '') {
            return false;
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "facture_fourn";
        $sql .= ' WHERE entity IN (' . getEntity('facture_fourn') . ')';
        $sql .= " AND ref_supplier = '" . $this->db->escape($ref) . "'";
        $sql .= ' AND fk_soc = ' . ((int) $nfe->fk_soc);
        $sql .= ' LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }

        $exists = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $exists;
    }

    /**
     * Check if a stock movement was already registered.
     *
     * @param FvNfeIn $nfe
     * @param int     $productId
     * @return bool
     */
    private function stockMovementExists(FvNfeIn $nfe, $productId)
    {
        if ($nfe->id <= 0 || $productId <= 0) {
            return false;
        }

        $sql = 'SELECT rowid FROM ' . MAIN_DB_PREFIX . "stock_mouvement";
        $sql .= ' WHERE fk_origin = ' . ((int) $nfe->id);
        $sql .= " AND origintype = 'fvnfein'";
        $sql .= ' AND fk_product = ' . ((int) $productId);
        $sql .= ' LIMIT 1';

        $resql = $this->db->query($sql);
        if (!$resql) {
            return false;
        }

        $exists = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);

        return $exists;
    }

    /**
     * Helper to fetch configuration string from Dolibarr globals.
     *
     * @param string $key
     * @param string $default
     * @return string
     */
    private function getConfigString($key, $default = '')
    {
        if (function_exists('getDolGlobalString')) {
            return getDolGlobalString($key, $default);
        }

        if (!empty($this->conf->global->{$key})) {
            return (string) $this->conf->global->{$key};
        }

        return $default;
    }
}
