<?php
declare(strict_types=1);

/**
 * Dedicated GET-only client for unattended VoIP reservation.
 * Never modifies IXC. Does not use/modify the MCP container credential.
 */
final class VoipIxcReservationReader
{
    private string $credential;
    private $transport;
    public const ENDPOINT = 'https://cda.jrconect.com/webservice/v1/';
    public const SECRET = '/etc/jrconect/voip-ixc-writer.basic';

    public function __construct(string $credentialFile = self::SECRET, ?callable $transport = null)
    {
        if (!is_file($credentialFile) || is_link($credentialFile)) {
            throw new RuntimeException('Credencial IXC de consulta ausente.');
        }
        $st=stat($credentialFile);
        if (!is_array($st) || $st['uid'] !== 0 || ($st['mode'] & 0077) !== 0) {
            throw new RuntimeException('Credencial IXC precisa ser root:0600.');
        }
        $raw=file_get_contents($credentialFile);
        $secret=is_string($raw)?trim($raw):'';
        if (!preg_match('/^[^:\\r\\n ]{1,150}:[^\\r\\n ]{6,700}$/D',$secret)) {
            throw new RuntimeException('Credencial IXC invalida.');
        }
        $this->credential=$secret;
        $this->transport=$transport;
    }

    private function exact(string $resource, string $id): array
    {
        if (!in_array($resource,['cliente_contrato','vd_contratos_produtos'],true) ||
            !preg_match('/^[0-9]{1,12}$/D',$id)) {
            throw new RuntimeException('Consulta IXC fora do escopo.');
        }
        $body=[
            'qtype'=>$resource.'.id','query'=>$id,'oper'=>'=',
            'page'=>'1','rp'=>'20','sortname'=>$resource.'.id','sortorder'=>'desc'
        ];
        if ($this->transport !== null) {
            $response=($this->transport)($resource,$id,$body);
        } else {
            if (!function_exists('curl_init')) throw new RuntimeException('cURL nao instalado.');
            $ch=curl_init(self::ENDPOINT.$resource);
            curl_setopt_array($ch,[
                CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'GET',
                CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
                CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_HTTPHEADER=>[
                    'Authorization: Basic '.base64_encode($this->credential),
                    'ixcsoft: listar','Content-Type: application/json','Accept: application/json'
                ],
                CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),
            ]);
            try {
                $raw=curl_exec($ch);
                $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
                if (!is_string($raw) || $status!==200 || strlen($raw)>1048576)
                    throw new RuntimeException('IXC GET nao confirmado.');
            } finally { curl_close($ch); }
            $response=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        }
        if (!is_array($response) || isset($response['error']) ||
            ($response['success']??null)===false) throw new RuntimeException('IXC GET invalido.');
        $rows=$response['registros']??$response['data']??null;
        $total=$response['total']??$response['total_registros']??null;
        if (!is_array($rows) || count($rows)!==1 || (int)$total!==1 ||
            (string)($rows[0]['id']??'')!==$id) {
            throw new RuntimeException('IXC nao retornou registro unico.');
        }
        return $rows[0];
    }

    public function readFresh(string $contractId, string $productRecordId): array
    {
        if (!preg_match('/^[0-9]{1,12}$/D',$contractId) ||
            !preg_match('/^[0-9]{1,12}$/D',$productRecordId)) {
            throw new RuntimeException('IDs invalidos.');
        }
        $contract=$this->exact('cliente_contrato',$contractId);
        $product=$this->exact('vd_contratos_produtos',$productRecordId);
        if ((string)($product['id_contrato']??'')!==$contractId ||
            (string)($product['id_produto']??'')!=='74') {
            throw new RuntimeException('Produto 74 divergente.');
        }
        return ['contract'=>$contract,'product_rows'=>[$product]];
    }
}
