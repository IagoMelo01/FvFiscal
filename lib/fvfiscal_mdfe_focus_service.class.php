<?php
/* Copyright (C) 2025           SuperAdmin */

require_once __DIR__ . '/../class/FvMdfe.class.php';
require_once __DIR__ . '/../class/FvNfeOut.class.php';
require_once __DIR__ . '/fvfiscal_focus.lib.php';

/**
 * Focus API integration for MDF-e operations.
 */
class FvMdfeFocusService extends FvFocusGateway
{
    /**
     * Submit MDF-e creation payload to Focus API.
     *
     * @param FvMdfe $mdfe
     * @param User   $user
     * @return FvMdfe|int
     */
    public function createManifest(FvMdfe $mdfe, $user)
    {
        $this->resetErrors();

        if ($mdfe->id <= 0) {
            return $this->failWith('FvFiscalMdfeNotPersisted');
        }

        $payload = $this->buildCreatePayload($mdfe);
        if ($payload === null) {
            return -1;
        }

        $this->db->begin();

        $job = $this->createFocusJob($user, 'mdfe.create', array(
            'mdfe_id' => $mdfe->id,
            'payload' => $payload,
        ), array(
            'fk_sefaz_profile' => $mdfe->fk_sefaz_profile,
        ));
        if (!$job) {
            $this->db->rollback();

            return -1;
        }

        $response = $this->performFocusRequest('POST', 'mdfe', $payload);
        if ($response === null) {
            $this->db->rollback();

            return -1;
        }

        $this->applyResponseToMdfe($mdfe, $response);
        $mdfe->fk_focus_job = $job->id;
        $mdfe->status = $this->mapRemoteStatus($response['status'] ?? ($response['situacao'] ?? null), FvMdfe::STATUS_PROCESSING);
        $payloadJson = $this->encodeJson($payload);
        if ($payloadJson !== null) {
            $mdfe->json_payload = $payloadJson;
        }
        $responseJson = $this->encodeJson($response);
        if ($responseJson !== null) {
            $mdfe->json_response = $responseJson;
        }

        if ($mdfe->update($user) < 0) {
            $this->db->rollback();

            return $this->failWith($mdfe->error ?: 'MdfeUpdateFailed', $mdfe->errors);
        }

        $this->db->commit();

        return $mdfe;
    }

    /**
     * Request MDF-e cancellation.
     *
     * @param FvMdfe $mdfe
     * @param User   $user
     * @param string $justification
     * @return FvMdfe|int
     */
    public function cancelManifest(FvMdfe $mdfe, $user, $justification)
    {
        $this->resetErrors();

        if (!$mdfe->canCancel()) {
            return $this->failWith('FvFiscalMdfeCannotCancel');
        }

        $justification = trim((string) $justification);
        if (dol_strlen($justification) < 15) {
            return $this->failWith('FvFiscalFocusJustificationTooShort', array('15'));
        }

        $identifier = $this->resolveIdentifier($mdfe);
        if ($identifier === '') {
            return $this->failWith('FvFiscalMdfeMissingIdentifier');
        }

        $payload = array('justificativa' => $justification);

        $this->db->begin();

        $job = $this->createFocusJob($user, 'mdfe.cancel', array(
            'mdfe_id' => $mdfe->id,
            'identifier' => $identifier,
            'payload' => $payload,
        ), array(
            'fk_sefaz_profile' => $mdfe->fk_sefaz_profile,
        ));
        if (!$job) {
            $this->db->rollback();

            return -1;
        }

        $response = $this->performFocusRequest('POST', 'mdfe/' . rawurlencode($identifier) . '/cancelar', $payload);
        if ($response === null) {
            $this->db->rollback();

            return -1;
        }

        $this->applyResponseToMdfe($mdfe, $response);
        $mdfe->fk_focus_job = $job->id;
        $mdfe->status = FvMdfe::STATUS_CANCELLED;
        $responseJson = $this->encodeJson($response);
        if ($responseJson !== null) {
            $mdfe->json_response = $responseJson;
        }

        if ($mdfe->update($user) < 0) {
            $this->db->rollback();

            return $this->failWith($mdfe->error ?: 'MdfeUpdateFailed', $mdfe->errors);
        }

        $this->db->commit();

        return $mdfe;
    }

    /**
     * Request MDF-e closure.
     *
     * @param FvMdfe $mdfe
     * @param User   $user
     * @param array  $payload
     * @return FvMdfe|int
     */
    public function closeManifest(FvMdfe $mdfe, $user, array $payload = array())
    {
        $this->resetErrors();

        if (!$mdfe->canClose()) {
            return $this->failWith('FvFiscalMdfeCannotClose');
        }

        $identifier = $this->resolveIdentifier($mdfe);
        if ($identifier === '') {
            return $this->failWith('FvFiscalMdfeMissingIdentifier');
        }

        $payload = $this->buildClosePayload($mdfe, $payload);

        $this->db->begin();

        $job = $this->createFocusJob($user, 'mdfe.close', array(
            'mdfe_id' => $mdfe->id,
            'identifier' => $identifier,
            'payload' => $payload,
        ), array(
            'fk_sefaz_profile' => $mdfe->fk_sefaz_profile,
        ));
        if (!$job) {
            $this->db->rollback();

            return -1;
        }

        $response = $this->performFocusRequest('POST', 'mdfe/' . rawurlencode($identifier) . '/encerrar', $payload);
        if ($response === null) {
            $this->db->rollback();

            return -1;
        }

        $this->applyResponseToMdfe($mdfe, $response);
        $mdfe->fk_focus_job = $job->id;
        $mdfe->status = FvMdfe::STATUS_CLOSED;
        $responseJson = $this->encodeJson($response);
        if ($responseJson !== null) {
            $mdfe->json_response = $responseJson;
        }

        if ($mdfe->update($user) < 0) {
            $this->db->rollback();

            return $this->failWith($mdfe->error ?: 'MdfeUpdateFailed', $mdfe->errors);
        }

        $this->db->commit();

        return $mdfe;
    }

    /**
     * Map remote status string to MDF-e status constants.
     *
     * @param mixed $value
     * @param int   $default
     * @return int
     */
    protected function mapRemoteStatus($value, $default)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }

        $normalized = dol_string_unaccent(strtolower($value));
        if (in_array($normalized, array('autorizado', 'autorizada', 'authorized'), true)) {
            return FvMdfe::STATUS_AUTHORIZED;
        }
        if (in_array($normalized, array('processando', 'em processamento', 'processing', 'pendente'), true)) {
            return FvMdfe::STATUS_PROCESSING;
        }
        if (in_array($normalized, array('cancelado', 'cancelada'), true)) {
            return FvMdfe::STATUS_CANCELLED;
        }
        if (in_array($normalized, array('encerrado', 'encerrada', 'closed'), true)) {
            return FvMdfe::STATUS_CLOSED;
        }

        return $default;
    }

    /**
     * Build payload used for MDF-e creation.
     *
     * @param FvMdfe $mdfe
     * @return array|null
     */
    private function buildCreatePayload(FvMdfe $mdfe)
    {
        $base = array();
        if (!empty($mdfe->json_payload)) {
            $decoded = json_decode($mdfe->json_payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $base = $decoded;
            }
        }

        $vehicle = isset($base['vehicle']) && is_array($base['vehicle']) ? $base['vehicle'] : array();
        if (empty($vehicle['placa']) && !empty($mdfe->vehicle_plate)) {
            $vehicle['placa'] = $mdfe->vehicle_plate;
        }
        if (empty($vehicle['rntrc']) && !empty($mdfe->vehicle_rntrc)) {
            $vehicle['rntrc'] = $mdfe->vehicle_rntrc;
        }

        $driver = isset($base['driver']) && is_array($base['driver']) ? $base['driver'] : array();
        if (empty($driver['nome']) && !empty($mdfe->driver_name)) {
            $driver['nome'] = $mdfe->driver_name;
        }
        if (empty($driver['documento']) && !empty($mdfe->driver_document)) {
            $driver['documento'] = $mdfe->driver_document;
        }

        $origin = isset($base['origin']) && is_array($base['origin']) ? $base['origin'] : array();
        if (empty($origin['municipio']) && !empty($mdfe->origin_city)) {
            $origin['municipio'] = $mdfe->origin_city;
        }
        if (empty($origin['uf']) && !empty($mdfe->origin_state)) {
            $origin['uf'] = $mdfe->origin_state;
        }

        $destination = isset($base['destination']) && is_array($base['destination']) ? $base['destination'] : array();
        if (empty($destination['municipio']) && !empty($mdfe->destination_city)) {
            $destination['municipio'] = $mdfe->destination_city;
        }
        if (empty($destination['uf']) && !empty($mdfe->destination_state)) {
            $destination['uf'] = $mdfe->destination_state;
        }

        $documents = $this->collectLinkedDocuments($mdfe);
        if (empty($documents)) {
            return $this->failWith('FvFiscalMdfeNoDocuments');
        }

        $payload = array(
            'referencia' => $mdfe->ref,
            'perfil_sefaz' => $mdfe->fk_sefaz_profile,
            'veiculo' => $vehicle,
            'motorista' => $driver,
            'origem' => $origin,
            'destino' => $destination,
            'documentos' => $documents,
        );

        return $payload;
    }

    /**
     * Build payload for MDF-e closure using stored data as defaults.
     *
     * @param FvMdfe                $mdfe
     * @param array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function buildClosePayload(FvMdfe $mdfe, array $overrides)
    {
        $base = array();
        if (!empty($mdfe->json_payload)) {
            $decoded = json_decode($mdfe->json_payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $base = $decoded;
            }
        }

        $closure = array();
        if (!empty($base['closure']) && is_array($base['closure'])) {
            $closure = $base['closure'];
        }

        foreach ($overrides as $key => $value) {
            $closure[$key] = $value;
        }

        if (empty($closure['data_encerramento'])) {
            if (!empty($mdfe->closure_at)) {
                $closure['data_encerramento'] = dol_print_date($mdfe->closure_at, '%Y-%m-%dT%H:%M:%S');
            } else {
                $closure['data_encerramento'] = dol_print_date(dol_now(), '%Y-%m-%dT%H:%M:%S');
            }
        }

        if (empty($closure['municipio']) && !empty($mdfe->closure_city)) {
            $closure['municipio'] = $mdfe->closure_city;
        }
        if (empty($closure['uf']) && !empty($mdfe->closure_state)) {
            $closure['uf'] = $mdfe->closure_state;
        }

        return $closure;
    }

    /**
     * Apply response metadata to MDF-e record.
     *
     * @param FvMdfe                $mdfe
     * @param array<string, mixed>  $response
     * @return void
     */
    private function applyResponseToMdfe(FvMdfe $mdfe, array $response)
    {
        $issue = $this->parseDatetime($response['issue_at'] ?? ($response['data_emissao'] ?? ($response['issued_at'] ?? null)));
        if ($issue) {
            $mdfe->issue_at = $issue;
        }

        $key = $this->extractString($response, array('mdfe_key', 'chave', 'chave_mdfe'));
        if ($key !== '') {
            $mdfe->mdfe_key = $key;
        }

        $protocol = $this->extractString($response, array('protocol_number', 'numero_protocolo', 'protocolo'));
        if ($protocol !== '') {
            $mdfe->protocol_number = $protocol;
        }

        $xmlContent = $this->resolveXmlContent($response, array('xml', 'mdfe_xml', 'documento_xml'));
        if ($xmlContent !== '') {
            $relative = $this->storeDocumentContent('mdfe', $mdfe->id, $xmlContent, 'mdfe.xml');
            if ($relative !== '') {
                $mdfe->xml_path = $relative;
            }
        }

        $pdfContent = $this->resolveBinaryContent($response, array('pdf', 'damdfe_pdf', 'documento_pdf'));
        if ($pdfContent !== '') {
            $relative = $this->storeDocumentContent('mdfe', $mdfe->id, $pdfContent, 'damdfe.pdf');
            if ($relative !== '') {
                $mdfe->pdf_path = $relative;
            }
        }

        $closureDate = $this->parseDatetime($response['closure_at'] ?? ($response['data_encerramento'] ?? null));
        if ($closureDate) {
            $mdfe->closure_at = $closureDate;
        }
        $closureCity = $this->extractString($response, array('closure_city', 'municipio_encerramento', 'municipio_enc')); 
        if ($closureCity !== '') {
            $mdfe->closure_city = $closureCity;
        }
        $closureState = $this->extractString($response, array('closure_state', 'uf_encerramento', 'uf_enc'));
        if ($closureState !== '') {
            $mdfe->closure_state = $closureState;
        }
    }

    /**
     * Retrieve binary content (PDF) from response payload.
     *
     * @param array<string, mixed> $response
     * @param array<int, string>   $keys
     * @return string
     */
    private function resolveBinaryContent(array $response, array $keys)
    {
        foreach ($keys as $key) {
            if (!empty($response[$key])) {
                $content = $this->decodeXmlValue($response[$key]);
                if ($content !== '') {
                    return $content;
                }
            }
        }

        if (!empty($response['downloads']) && is_array($response['downloads'])) {
            foreach ($keys as $key) {
                if (!empty($response['downloads'][$key])) {
                    $content = $this->decodeXmlValue($response['downloads'][$key]);
                    if ($content !== '') {
                        return $content;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Collect NF-e documents linked to the MDF-e.
     *
     * @param FvMdfe $mdfe
     * @return array<int, array<string, mixed>>|null
     */
    private function collectLinkedDocuments(FvMdfe $mdfe)
    {
        if (empty($mdfe->linked_nfe_ids)) {
            $mdfe->linked_nfe_ids = $mdfe->loadLinkedNfeIds();
        }
        if (empty($mdfe->linked_nfe_ids)) {
            return null;
        }

        $ids = array_map('intval', $mdfe->linked_nfe_ids);
        $sql = 'SELECT rowid, nfe_key, protocol_number, total_amount FROM ' . MAIN_DB_PREFIX . 'fv_nfe_out'
            . ' WHERE rowid IN (' . implode(',', $ids) . ')';
        $resql = $this->db->query($sql);
        if (!$resql) {
            $this->failWith($this->db->lasterror());

            return null;
        }

        $documents = array();
        while ($obj = $this->db->fetch_object($resql)) {
            if (empty($obj->nfe_key)) {
                continue;
            }
            $documents[] = array(
                'tipo' => 'nfe',
                'chave' => $obj->nfe_key,
                'protocolo' => $obj->protocol_number,
                'valor' => (float) $obj->total_amount,
            );
        }
        $this->db->free($resql);

        return $documents;
    }

    /**
     * Resolve Focus identifier for MDF-e operations.
     *
     * @param FvMdfe $mdfe
     * @return string
     */
    private function resolveIdentifier(FvMdfe $mdfe)
    {
        if (!empty($mdfe->mdfe_key)) {
            return $mdfe->mdfe_key;
        }
        if (!empty($mdfe->ref)) {
            return $mdfe->ref;
        }

        return '';
    }
}
