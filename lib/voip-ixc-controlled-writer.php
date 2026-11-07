<?php
declare(strict_types=1);

/**
 * Dedicated IXC VoIP writer; NEVER uses the MCP read-only secret.
 * CLI only. All network calls are HTTPS to the fixed IXC host.
 * Editing is disabled without a separate root-owned credential and operator gate.
 */
final class VoipIxcControlledWriter
{
    public const ENDPOINT = 'https://cda.jrconect.com/webservice/v1/';
    public const CREDENTIAL_FILE = '/etc/jrconect/voip-ixc-writer.basic';
    private string $credential;
    private $transport;

    public function __construct(string $credentialFile = self::CREDENTIAL_FILE, ?callable $transport = null)
    {
        if (!is_file($credentialFile) || is_link($credentialFile)) {
            throw new RuntimeException('Credencial IXC de escrita nao instalada.');
        }
        $st = stat($credentialFile);
        if (!is_array($st) || ($st['mode'] & 0077) !== 0 ||
            (function_exists('posix_geteuid') && ($st['uid'] !== posix_geteuid()))) {
            throw new RuntimeException('Credencial de escrita deve pertencer ao root e ter modo 0600.');
        }
        $raw = file_get_contents($credentialFile);
        if (!is_string($raw)) throw new RuntimeException('Credencial inacessivel.');
        $credential = trim($raw);
        if (!preg_match('/^[^:\r\n ]{1,150}:[^\r\n ]{6,700}$/D', $credential)) {
            throw new RuntimeException('Credencial Basic IXC invalida.');
        }
        $this->credential = $credential;
        $this->transport = $transport;
    }

    private function request(string $method, string $resource, ?string $id, array $body): array
    {
        if (!in_array($resource, ['cliente_contrato', 'vd_contratos_produtos'], true) ||
            !in_array($method, ['GET','PUT'], true) ||
            ($method==='PUT' && ($resource!=='cliente_contrato' || $id!=='4740'))) {
            throw new RuntimeException('Rota de escrita IXC nao autorizada.');
        }
        if ($id !== null && !preg_match('/^[0-9]{1,12}$/D',$id)) throw new RuntimeException('ID invalido');
        $uri = self::ENDPOINT.$resource.($id !== null?'/'.$id:'');
        if ($this->transport !== null) {
            $result=($this->transport)($method,$uri,$body);
            if (!is_array($result)) throw new RuntimeException('Resposta HTTP invalida');
            return $result;
        }
        if (!function_exists('curl_init')) throw new RuntimeException('Extensao cURL indisponivel.');
        $ch = curl_init($uri);
        $json = json_encode($body,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CUSTOMREQUEST=>$method,
            CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,
            CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_HTTPHEADER=>[
                'Authorization: Basic '.base64_encode($this->credential),
                'Content-Type: application/json','Accept: application/json',
                ...($method==='GET'?['ixcsoft: listar']:[]),
            ],
            CURLOPT_POSTFIELDS=>$json,
        ]);
        try {
            $raw=curl_exec($ch);
            $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
            if (!is_string($raw) || $status < 200 || $status >= 300 ||
                strlen($raw)>1048576) throw new RuntimeException('IXC indisponivel ou HTTP incorreto.');
        } finally { curl_close($ch); }
        $result=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($result) || isset($result['error']) ||
            ($result['success']??null)===false) throw new RuntimeException('Falha de resposta IXC.');
        return $result;
    }

    private function exact(string $resource, string $id): array
    {
        $body = ['qtype'=>$resource.'.id','query'=>$id,'oper'=>'=','page'=>'1',
            'rp'=>'20','sortname'=>$resource.'.id','sortorder'=>'desc'];
        $resp=$this->request('GET',$resource,null,$body);
        $rows=$resp['registros']??$resp['data']??null;
        $total=$resp['total']??$resp['total_registros']??null;
        if (!is_array($rows) || count($rows)!==1 || (int)$total!==1 ||
            (string)($rows[0]['id']??'')!==$id) {
            throw new RuntimeException('IXC nao retornou um registro unico.');
        }
        return $rows[0];
    }

    public function readFresh(string $contractId): array
    {
        if ($contractId!=='4740') throw new RuntimeException('Piloto limitado ao contrato 4740.');
        $contract=$this->exact('cliente_contrato',$contractId);
        $product=$this->exact('vd_contratos_produtos','571');
        if ((string)($product['id_contrato']??'')!==$contractId ||
            (string)($product['id_produto']??'')!=='74') {
            throw new RuntimeException('Produto 74/571 divergente.');
        }
        if (($contract['status']??null)!=='A') throw new RuntimeException('Contrato piloto nao ativo.');
        return ['contract'=>$contract,'product_rows'=>[$product]];
    }

    public static function payload(array $contract,string $previous,string $next): array
    {
        if ((string)($contract['id']??'')!=='4740' ||
            ($contract['status']??null)!=='A' ||
            ($contract['obs_contrato']??null)!==$previous ||
            substr_count($previous,'#JRC_VOIP_SOLICITAR#')!==1 ||
            str_contains($previous,'JRC_VOIP_CONTRATO=') ||
            !str_starts_with($next,strstr($previous,'#JRC_VOIP_SOLICITAR#',true)) ||
            count($contract)<35) {
            throw new RuntimeException('Contrato mudou ou dados insuficientes para PUT.');
        }
        $required=['tipo','id_cliente','id_vd_contrato','contrato','id_tipo_contrato',
            'id_modelo','id_filial','data','status','endereco_padrao_cliente',
            'obs_contrato','alerta_contrato'];
        foreach ($required as $key) if (!array_key_exists($key,$contract))
            throw new RuntimeException('Campo exigido IXC ausente.');
        $expected = str_replace('#JRC_VOIP_SOLICITAR#','JRC_VOIP_CONTRATO=4740;DID=', $previous);
        if (!preg_match('/^11[0-9]{8}$/D',substr($next,-10)) ||
            $next!==$expected.substr($next,-10)) {
            throw new RuntimeException('Nova observacao nao corresponde a substituicao segura.');
        }
        $fields=$contract;
        unset($fields['id']);
        foreach ($fields as $key=>$value) {
            if (!is_string($key) || !preg_match('/^[a-z_0-9]{1,80}$/D',$key) ||
                !(is_string($value)||is_int($value)||is_float($value)||
                  is_bool($value)||$value===null)) {
                throw new RuntimeException('Contrato possui campo nao serializavel.');
            }
        }
        $fields['obs_contrato']=$next;
        return $fields;
    }

    public function writeObservation(string $id,string $previous,string $next): void
    {
        if ($id!=='4740') throw new RuntimeException('Escrita fora do contrato piloto.');
        $before=$this->readFresh($id);
        $contract=$before['contract'];
        $payload=self::payload($contract,$previous,$next);
        // Full record PUT: no guessed defaults, no partial patch.
        // The caller has already reserved the DID and handles ambiguous errors.
        $this->request('PUT','cliente_contrato',$id,$payload);
        $after=$this->readFresh($id);
        foreach ($contract as $field=>$value) {
            if ($field==='obs_contrato') continue;
            if (!array_key_exists($field,$after['contract']) ||
                $after['contract'][$field]!==$value) {
                throw new RuntimeException('Campo de contrato alterado inesperadamente pelo PUT.');
            }
        }
        if (($after['contract']['obs_contrato']??null)!==$next) {
            throw new RuntimeException('Observacao nao confirmada apos PUT.');
        }
    }
}
