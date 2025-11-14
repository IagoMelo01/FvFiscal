<?php
/* Copyright (C) 2025           SuperAdmin */

require_once __DIR__ . '/../class/FvNfeOut.class.php';
require_once __DIR__ . '/../class/FvNfeOutLine.class.php';
require_once __DIR__ . '/fvfiscal_focus.lib.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/price.lib.php';

/**
 * Focus API integration for NF-e emission.
 */
class FvNfeFocusService extends FvFocusGateway
{
    /**
     * Submit NF-e payload to Focus API.
     *
     * @param FvNfeOut $document
     * @param User     $user
     * @param bool     $force
     * @return FvNfeOut|int
     */
    public function submitDocument(FvNfeOut $document, $user, $force = false)
    {
        $this->resetErrors();

        if ($document->id <= 0) {
            return $this->failWith('FvFiscalNfeOutNotPersisted');
        }
        if (!$force && !$document->canIssue()) {
            return $this->failWith('FvFiscalNfeOutCannotIssue');
        }
        if ((int) $document->fk_sefaz_profile <= 0) {
            return $this->failWith('FvFiscalNfeOutMissingSefazProfile');
        }

        $payload = $this->buildCreatePayload($document);
        if (!is_array($payload)) {
            return -1;
        }

        $this->db->begin();

        $job = $this->createFocusJob($user, 'nfe.create', array(
            'nfe_id' => $document->id,
            'payload' => $payload,
        ), array(
            'fk_sefaz_profile' => $document->fk_sefaz_profile,
        ));
        if (!$job) {
            $this->db->rollback();

            return -1;
        }

        $response = $this->performFocusRequest('POST', 'nfe', $payload);
        if ($response === null) {
            $this->db->rollback();

            return -1;
        }

        $this->applyResponseToNfe($document, $response);
        $document->fk_focus_job = $job->id;
        $document->status = $this->mapRemoteStatus($response['status'] ?? ($response['situacao'] ?? null), FvNfeOut::STATUS_PROCESSING);

        $payloadJson = $this->encodeJson($payload);
        if ($payloadJson !== null) {
            $document->json_payload = $payloadJson;
        }
        $responseJson = $this->encodeJson($response);
        if ($responseJson !== null) {
            $document->json_response = $responseJson;
        }

        if ($document->update($user) < 0) {
            $this->db->rollback();

            return $this->failWith($document->error ?: 'NfeOutUpdateFailed', $document->errors);
        }

        $this->db->commit();

        return $document;
    }

    /**
     * Build payload to emit NF-e using local data.
     *
     * @param FvNfeOut $document
     * @return array|null
     */
    private function buildCreatePayload(FvNfeOut $document)
    {
        $base = array();
        if (!empty($document->json_payload)) {
            $decoded = json_decode($document->json_payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $base = $decoded;
            }
        }

        $payload = $base;
        if (empty($payload['referencia'])) {
            $payload['referencia'] = $document->ref ?: ('NF-' . $document->id);
        }
        if (empty($payload['perfil_sefaz'])) {
            $payload['perfil_sefaz'] = $document->fk_sefaz_profile;
        }
        if (empty($payload['serie']) && !empty($document->series)) {
            $payload['serie'] = $document->series;
        }
        if (empty($payload['numero']) && !empty($document->document_number)) {
            $payload['numero'] = $document->document_number;
        }
        if (empty($payload['tipo_operacao']) && !empty($document->operation_type)) {
            $payload['tipo_operacao'] = $document->operation_type;
        }
        if (empty($payload['data_emissao'])) {
            $issue = $document->issue_at ?: dol_now();
            $payload['data_emissao'] = dol_print_date($issue, '%Y-%m-%dT%H:%M:%S');
        }

        $recipientBase = array();
        if (!empty($payload['destinatario']) && is_array($payload['destinatario'])) {
            $recipientBase = $payload['destinatario'];
        }
        $payload['destinatario'] = $this->buildRecipient($document, $recipientBase);
        if (empty($payload['destinatario'])) {
            $this->failWith('FvFiscalNfeOutMissingRecipient');

            return null;
        }

        $itemsBase = array();
        if (!empty($payload['itens']) && is_array($payload['itens'])) {
            $itemsBase = $payload['itens'];
        }
        $payload['itens'] = $this->buildItems($document, $itemsBase);
        if (empty($payload['itens'])) {
            $this->failWith('FvFiscalNfeOutNoItems');

            return null;
        }

        if (empty($payload['observacoes']) && !empty($document->note_public)) {
            $payload['observacoes'] = $document->note_public;
        }

        if (empty($payload['totais']) || !is_array($payload['totais'])) {
            $payload['totais'] = array();
        }
        if (!isset($payload['totais']['valor_produtos'])) {
            $payload['totais']['valor_produtos'] = price2num($document->total_products, 'MU');
        }
        if (!isset($payload['totais']['valor_descontos'])) {
            $payload['totais']['valor_descontos'] = price2num($document->total_discount, 'MU');
        }
        if (!isset($payload['totais']['valor_frete'])) {
            $payload['totais']['valor_frete'] = price2num($document->total_freight, 'MU');
        }
        if (!isset($payload['totais']['valor_seguro'])) {
            $payload['totais']['valor_seguro'] = price2num($document->total_insurance, 'MU');
        }
        if (!isset($payload['totais']['valor_outros'])) {
            $payload['totais']['valor_outros'] = price2num($document->total_other, 'MU');
        }
        if (!isset($payload['totais']['valor_total'])) {
            $payload['totais']['valor_total'] = price2num($document->total_amount, 'MU');
        }
        if (!isset($payload['totais']['valor_impostos'])) {
            $payload['totais']['valor_impostos'] = price2num($document->total_tax, 'MU');
        }

        return $payload;
    }

    /**
     * Populate recipient data for payload.
     *
     * @param FvNfeOut $document
     * @param array    $base
     * @return array
     */
    private function buildRecipient(FvNfeOut $document, array $base)
    {
        $recipient = $base;

        if (!empty($document->fk_soc)) {
            $thirdparty = new Societe($this->db);
            if ($thirdparty->fetch($document->fk_soc) > 0) {
                if (empty($recipient['nome']) && !empty($thirdparty->name)) {
                    $recipient['nome'] = $thirdparty->name;
                }
                if (empty($recipient['email']) && !empty($thirdparty->email)) {
                    $recipient['email'] = $thirdparty->email;
                }
                if (empty($recipient['telefone']) && !empty($thirdparty->phone)) {
                    $recipient['telefone'] = $thirdparty->phone;
                } elseif (empty($recipient['telefone']) && !empty($thirdparty->phone_mobile)) {
                    $recipient['telefone'] = $thirdparty->phone_mobile;
                }

                $documentNumber = $this->cleanDigits($thirdparty->idprof1 ?: $thirdparty->idprof2 ?: $thirdparty->siren ?: $thirdparty->siret);
                if (!empty($documentNumber) && empty($recipient['documento'])) {
                    $recipient['documento'] = $documentNumber;
                }
                if (empty($recipient['ie']) && !empty($thirdparty->idprof2)) {
                    $recipient['ie'] = $this->cleanDigits($thirdparty->idprof2);
                }

                if (empty($recipient['logradouro']) && !empty($thirdparty->address)) {
                    $recipient['logradouro'] = $thirdparty->address;
                }
                if (empty($recipient['municipio']) && !empty($thirdparty->town)) {
                    $recipient['municipio'] = $thirdparty->town;
                }
                if (empty($recipient['uf']) && !empty($thirdparty->state_code)) {
                    $recipient['uf'] = $thirdparty->state_code;
                }
                if (empty($recipient['cep']) && !empty($thirdparty->zip)) {
                    $recipient['cep'] = $this->cleanDigits($thirdparty->zip);
                }
            }
        }

        return $recipient;
    }

    /**
     * Populate item array using stored lines.
     *
     * @param FvNfeOut $document
     * @param array    $baseItems
     * @return array
     */
    private function buildItems(FvNfeOut $document, array $baseItems)
    {
        $items = array();
        foreach ($baseItems as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        $document->fetchLines(true);
        $index = 0;
        foreach ($document->lines as $line) {
            $current = isset($items[$index]) ? $items[$index] : array();

            if (empty($current['numero_item'])) {
                $current['numero_item'] = $index + 1;
            }
            if (empty($current['codigo']) && !empty($line->ref)) {
                $current['codigo'] = $line->ref;
            }
            if (empty($current['descricao']) && !empty($line->description)) {
                $current['descricao'] = $line->description;
            }
            if (empty($current['ncm']) && !empty($line->ncm)) {
                $current['ncm'] = $line->ncm;
            }
            if (empty($current['cfop']) && !empty($line->cfop)) {
                $current['cfop'] = $line->cfop;
            }
            if (empty($current['cest']) && !empty($line->cest)) {
                $current['cest'] = $line->cest;
            }

            $qty = price2num($line->qty, 'MU');
            if (!isset($current['quantidade']) && $qty !== null) {
                $current['quantidade'] = $qty;
            }
            $unit = price2num($line->unit_price, 'MU');
            if (!isset($current['valor_unitario']) && $unit !== null) {
                $current['valor_unitario'] = $unit;
            }
            $total = price2num($line->total_ttc, 'MU');
            if (!isset($current['valor_total']) && $total !== null) {
                $current['valor_total'] = $total;
            }
            $discount = price2num($line->discount_amount, 'MU');
            if (!isset($current['valor_desconto']) && $discount !== null) {
                $current['valor_desconto'] = $discount;
            }
            $net = price2num($line->total_ht, 'MU');
            if (!isset($current['valor_bruto']) && $net !== null) {
                $current['valor_bruto'] = $net;
            }

            if (empty($current['impostos']) || !is_array($current['impostos'])) {
                $current['impostos'] = array();
            }
            if (!isset($current['impostos']['icms']) && ($line->icms_rate || $line->icms_amount)) {
                $current['impostos']['icms'] = array(
                    'aliquota' => price2num($line->icms_rate, 'MU'),
                    'valor' => price2num($line->icms_amount, 'MU'),
                );
            }
            if (!isset($current['impostos']['ipi']) && ($line->ipi_rate || $line->ipi_amount)) {
                $current['impostos']['ipi'] = array(
                    'aliquota' => price2num($line->ipi_rate, 'MU'),
                    'valor' => price2num($line->ipi_amount, 'MU'),
                );
            }
            if (!isset($current['impostos']['pis']) && ($line->pis_rate || $line->pis_amount)) {
                $current['impostos']['pis'] = array(
                    'aliquota' => price2num($line->pis_rate, 'MU'),
                    'valor' => price2num($line->pis_amount, 'MU'),
                );
            }
            if (!isset($current['impostos']['cofins']) && ($line->cofins_rate || $line->cofins_amount)) {
                $current['impostos']['cofins'] = array(
                    'aliquota' => price2num($line->cofins_rate, 'MU'),
                    'valor' => price2num($line->cofins_amount, 'MU'),
                );
            }
            if (!isset($current['impostos']['issqn']) && ($line->issqn_rate || $line->issqn_amount)) {
                $current['impostos']['issqn'] = array(
                    'aliquota' => price2num($line->issqn_rate, 'MU'),
                    'valor' => price2num($line->issqn_amount, 'MU'),
                );
            }

            $items[$index] = $current;
            $index++;
        }

        return $items;
    }

    /**
     * Apply response metadata to NF-e document.
     *
     * @param FvNfeOut $document
     * @param array    $response
     * @return void
     */
    private function applyResponseToNfe(FvNfeOut $document, array $response)
    {
        $issue = $this->parseDatetime($response['issue_at'] ?? ($response['data_emissao'] ?? ($response['issued_at'] ?? null)));
        if ($issue) {
            $document->issue_at = $issue;
        }

        $key = $this->extractString($response, array('nfe_key', 'chave', 'chave_nfe'));
        if ($key !== '') {
            $document->nfe_key = $key;
        }

        $protocol = $this->extractString($response, array('numero_protocolo', 'protocolo', 'protocol_number', 'protocolo_autorizacao'));
        if ($protocol !== '') {
            $document->protocol_number = $protocol;
        }

        $xmlContent = $this->resolveXmlContent($response, array('xml', 'xml_nfe', 'nfe_xml', 'documento_xml', 'arquivo_xml'));
        if ($xmlContent !== '') {
            $relative = $this->storeDocumentContent('nfe', $document->id, $xmlContent, 'nfe.xml');
            if ($relative !== '') {
                $document->xml_path = $relative;
            }
        }

        $pdfContent = $this->resolveBinaryContent($response, array('danfe', 'danfe_pdf', 'pdf', 'documento_pdf', 'url_danfe'));
        if ($pdfContent !== '') {
            $relative = $this->storeDocumentContent('nfe', $document->id, $pdfContent, 'danfe.pdf');
            if ($relative !== '') {
                $document->pdf_path = $relative;
            }
        }
    }

    /**
     * Map Focus status text to NF-e status codes.
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
        if (in_array($normalized, array('autorizado', 'autorizada', 'authorized', 'aprovado'), true)) {
            return FvNfeOut::STATUS_AUTHORIZED;
        }
        if (in_array($normalized, array('processando', 'em processamento', 'processing', 'pendente'), true)) {
            return FvNfeOut::STATUS_PROCESSING;
        }
        if (in_array($normalized, array('cancelado', 'cancelada'), true)) {
            return FvNfeOut::STATUS_CANCELLED;
        }
        if (in_array($normalized, array('erro', 'error', 'rejeitado', 'denegado', 'denied'), true)) {
            return FvNfeOut::STATUS_ERROR;
        }

        return $default;
    }

    /**
     * Resolve binary content (PDF/DANFE) from response payload.
     *
     * @param array $response
     * @param array $keys
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
     * Remove non-digit characters from strings.
     *
     * @param mixed $value
     * @return string
     */
    private function cleanDigits($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }

        return preg_replace('/\D+/', '', $value);
    }
}
