<?php
declare(strict_types=1);
require_once __DIR__ . '/server/records.php';
cv_headers();
$rpcId=null;
function cv_tool_schema(): array {
    $empty=['type'=>'object','properties'=>(object)[],'additionalProperties'=>false];
    $id=['type'=>'string']; $version=['type'=>'integer','minimum'=>1];
    $day=['type'=>'string','description'=>'Data local em America/Sao_Paulo, formato YYYY-MM-DD.'];
    $tools=[
        ['name'=>'consultar_painel','description'=>'Consulta os registros pessoais e o resumo financeiro do mes. Valores monetarios sao inteiros em centavos.','inputSchema'=>['type'=>'object','properties'=>['month'=>['type'=>'string','description'=>'YYYY-MM; padrao: mes atual.']],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true]],
        ['name'=>'listar_registros','description'=>'Consulta tarefas, habitos, agenda, treinos, refeicoes, notas, metas ou lancamentos. Retorna id e revision para atualizacoes seguras.','inputSchema'=>['type'=>'object','properties'=>['kind'=>['type'=>'string','enum'=>CV_KINDS],'from'=>$day,'to'=>$day],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true]],
        ['name'=>'salvar_registro','description'=>'Cria um registro ou atualiza por id e revision. Registre apenas informacoes fornecidas ou confirmadas na conversa. Alimentacao usa descricao e reflexao qualitativa, sem calorias ou metas de peso. Para lancamentos use amount_cents e direction nos details.','inputSchema'=>['type'=>'object','required'=>['kind','title','day','details'],'properties'=>['id'=>$id,'revision'=>$version,'kind'=>['type'=>'string','enum'=>CV_KINDS],'title'=>['type'=>'string','maxLength'=>200],'day'=>$day,'details'=>['type'=>'object','properties'=>['notes'=>['type'=>'string'],'direction'=>['type'=>'string','enum'=>['income','expense']],'amount_cents'=>['type'=>'integer','minimum'=>1],'category'=>['type'=>'string'],'area'=>['type'=>'string','enum'=>['pessoal','casa','trabalho']],'priority'=>['type'=>'string','enum'=>['baixa','normal','alta']],'recurrence'=>['type'=>'string','enum'=>['once','daily','weekly']],'weekdays'=>['type'=>'array','items'=>['type'=>'integer','minimum'=>1,'maximum'=>7],'description'=>'1=segunda, 7=domingo'],'time'=>['type'=>'string','description'=>'HH:mm'],'end_time'=>['type'=>'string'],'location'=>['type'=>'string'],'activity'=>['type'=>'string','enum'=>['caminhada','corrida','forca','futebol','mobilidade','descanso','outro']],'duration_min'=>['type'=>'integer','minimum'=>0],'meal'=>['type'=>'string','enum'=>['cafe','almoco','lanche','jantar','outro']],'reflection'=>['type'=>'string','description'=>'Observacoes qualitativas discutidas com o usuario.' ],'progress'=>['type'=>'integer','minimum'=>0,'maximum'=>100]],'additionalProperties'=>false]],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false]],
        ['name'=>'concluir_registro','description'=>'Define explicitamente a conclusao de tarefa, habito, treino ou evento; nao alterna o estado implicitamente.','inputSchema'=>['type'=>'object','required'=>['id','revision','done'],'properties'=>['id'=>$id,'revision'=>$version,'done'=>['type'=>'boolean'],'day'=>$day],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true]],
        ['name'=>'arquivar_registro','description'=>'Arquiva ou restaura um registro. O historico permanece recuperavel.','inputSchema'=>['type'=>'object','required'=>['id','revision','archived'],'properties'=>['id'=>$id,'revision'=>$version,'archived'=>['type'=>'boolean']],'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>true,'idempotentHint'=>true]]
    ];
    foreach($tools as &$tool) {
        $tool['annotations']['openWorldHint']=false;
        $tool['securitySchemes']=[['type'=>'oauth2','scopes'=>$tool['annotations']['readOnlyHint'] ? ['read'] : ['read','write']]];
        $tool['_meta']=['securitySchemes'=>$tool['securitySchemes']];
        if($tool['name']==='salvar_registro') {
            $p=&$tool['inputSchema']['properties']['details']['properties'];
            $p['recurrence']['enum'][]='monthly';
            $p['month_day']=['type'=>'integer','minimum'=>1,'maximum'=>31];
            $p['size']=['type'=>'string','enum'=>['habit','mini']];
            unset($p);
        }
    }
    return $tools;
}
try {
    $origin=$_SERVER['HTTP_ORIGIN'] ?? '';
    $u=parse_url(cv_url()); $expected=$u['scheme'].'://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '');
    if ($origin!=='' && $origin!==$expected) cv_fail('Origem nao autorizada.',403);
    $token=cv_token();
    if ($_SERVER['REQUEST_METHOD']!=='POST') { header('Allow: POST'); cv_fail('Este servidor usa HTTP sem fluxo SSE.',405); }
    $version=$_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '2025-06-18';
    if (!in_array($version,['2025-06-18','2024-11-05'],true)) cv_fail('Versao de protocolo nao suportada.');
    $in=cv_input(); $rpcId=$in['id'] ?? null;
    if (($in['jsonrpc'] ?? '')!=='2.0' || !is_string($in['method'] ?? null)) cv_fail('Requisicao JSON-RPC invalida.');
    if (!array_key_exists('id',$in)) { http_response_code(202); exit; }
    $method=$in['method']; $args=$in['params'] ?? [];
    if ($method==='initialize') $result=['protocolVersion'=>'2025-06-18','capabilities'=>['tools'=>['listChanged'=>false]],'serverInfo'=>['name'=>'controlevida','version'=>'1.0.0']];
    elseif ($method==='ping') $result=(object)[];
    elseif ($method==='tools/list') $result=['tools'=>cv_tool_schema()];
    elseif ($method==='tools/call') {
        $name=$args['name'] ?? ''; $data=$args['arguments'] ?? [];
        if (!is_array($data)) cv_fail('Argumentos invalidos.');
        if (!in_array($name,['consultar_painel','listar_registros'],true)) cv_token('write');
        try {
            if ($name==='consultar_painel') $out=['today'=>date('Y-m-d'),'finance'=>cv_summary($token['user_id'],$data['month'] ?? date('Y-m')),'records'=>cv_list($token['user_id'])];
            elseif ($name==='listar_registros') $out=cv_list($token['user_id'],$data);
            else {
                if ($name==='salvar_registro') $out=cv_save($token['user_id'],$data,'mcp');
                elseif ($name==='concluir_registro') $out=cv_mark($token['user_id'],$data,'mcp');
                elseif ($name==='arquivar_registro') $out=cv_mark($token['user_id'],$data,'mcp',true);
                else cv_fail('Ferramenta desconhecida.');
            }
            $result=['content'=>[['type'=>'text','text'=>json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]]];
        } catch(DomainException $e) { $result=['content'=>[['type'=>'text','text'=>$e->getMessage()]],'isError'=>true]; }
    } else cv_json(['jsonrpc'=>'2.0','id'=>$rpcId,'error'=>['code'=>-32601,'message'=>'Metodo desconhecido.']]);
    cv_json(['jsonrpc'=>'2.0','id'=>$rpcId,'result'=>$result]);
} catch(DomainException $e) {
    if ($e->getCode()===401) header('WWW-Authenticate: Bearer resource_metadata="'.cv_url().'/metadata.php?resource=1"');
    cv_json(['jsonrpc'=>'2.0','id'=>$rpcId,'error'=>['code'=>-32000,'message'=>$e->getMessage()]],$e->getCode() ?: 400);
} catch(Throwable $e) { cv_json(['jsonrpc'=>'2.0','id'=>$rpcId,'error'=>['code'=>-32603,'message'=>'Servico indisponivel.']],503); }
