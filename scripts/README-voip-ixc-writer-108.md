# PR #108 — Motor de atribuição automática real no IXC, piloto 4740

## Entrega
Esta PR implementa a **escrita real via HTTP PUT** usando conta de escrita dedicada, com limite rígido ao contrato **4740**, e integra o motor `VoipAutoAssignment` já existente. O resultado é:
1. Ler contrato 4740 com marcador `#JRC_VOIP_SOLICITAR#` e produto 74 registro 571.
2. Reservar atomicamente o primeiro DID comprovado `disponivel`, sem tocar nos 13 vínculos existentes.
3. Relê o contrato imediatamente antes do PUT e confere que **a observação não foi alterada** por outra pessoa.
4. Enviar o registro completo do contrato para `PUT /webservice/v1/cliente_contrato/4740`, alterando só `obs_contrato`.
5. Reler contrato e produto 74, comparar todos os demais campos com a versão anterior; só após confirmação registrar DID `ocupado` no estoque.
6. Em falha, timeout, divergência ou erro de leitura, **reservar permanece bloqueado para revisão**. Não liberar automaticamente nem repetir a escrita cegamente.

## Proteções
- **Não reutiliza** o token existente no MCP de consulta. Requer credencial independente em `/etc/jrconect/voip-ixc-writer.basic`, root-only e modo `0600`, no formato `usuario:token`. Não publicar ou registrar o conteúdo.
- Sem rota HTTP, sem novo cron, sem mudança no MCP, sem alteração do monitor de 15 minutos. A PR não grava nada sozinha ao ser mesclada/instalada.
- Execução real só pelo CLI root com `--execute-4740`, credencial de escrita separada, arquivo root `/etc/jrconect/voip-ixc-writer-approved` modo 0600 **e** variável de ambiente exata `JRC_VOIP_CONFIRM_WRITE=4740:571`.
- Usa HTTPS com TLS verificado; host único `cda.jrconect.com`, rotas IXC limitadas.
- Se o contrato tiver menos de 35 campos, faltar algum campo-chave, tiver estado diferente de `A`, marcador múltiplo, status inseguro ou outro vínculo, **bloqueia** antes do PUT.
- O PUT IXC exige registro completo; nenhum campo do contrato é preenchido por inferência. Retorna falha se o servidor mudar outro campo, protegendo atribuição posterior.
- Sem ativação SIP na VoiceCopy: esta automação vincula administrativamente DID ao contrato/estoque e registra observação no IXC, não configura ramal ou operadora.

## Checklist antes de executar com escrita real
1. Validar em homologação (ou com aprovação operacional) a aceitação de todos os campos no PUT; o IXC pode normalizar valores ou aplicar efeitos não documentados. A presença de 47 campos no GET não demonstra que o PUT é seguro. **Não pular homologação.**
2. Confirmar que a credencial dedicada possui somente acesso mínimo à edição e leitura do contrato, sem ações financeiras involuntárias. O sistema atual MCP permanecerá read-only.
3. No ACS, fazer backup de `/var/lib/jrconect-voip/stock.json` e snapshot protegido completo do contrato 4740 (fora do GitHub e de chats).
4. Instalar apenas `lib/voip-ixc-controlled-writer.php` e `scripts/voip-ixc-assign-4740.php` em diretório root-only separado; manter o módulo `lib/voip-auto-assign.php` da PR #102 no host, sem modificar o MCP.
5. Instalar a credencial de escrita em `/etc/jrconect/voip-ixc-writer.basic` e rodar `php ... --dry-run`. Essa verificação faz chamadas GET, não modifica estoque ou IXC.
6. Só após conferir snapshot/payload e autorizar explicitamente o piloto, instalar arquivo de autorização root-only e executar o CLI com a variável de ambiente exata. Evitar execução repetida se houver timeout.
7. Validar número no campo `obs_contrato`, número ocupado e vínculo com contrato 4740 no painel; verificar os outros 13 vínculos e quantidade remanescente disponível.

## Instalação dos arquivos da PR (NÃO executa escrita)
```sh
set -e
REPO=/opt/jrconect-acs-git
DIR=/opt/jrconect-voip-writer
git -C "$REPO" fetch https://github.com/junaojsp/JRCONECT-ACS-MCP.git main
install -d -m 0700 "$DIR" "$DIR/lib" "$DIR/scripts"
for P in lib/voip-ixc-controlled-writer.php scripts/voip-ixc-assign-4740.php; do
 git -C "$REPO" show "FETCH_HEAD:$P" > "$DIR/$P"
 php -l "$DIR/$P"
done
```

O CLI contém caminhos de importação padrão para `/var/www/gacs/lib`, portanto a aplicação via etapa posterior deve instalar o writer no webroot ou modificar a chamada para usar o módulo localizado no diretório root-only. **Não habilitar escrita até revisar esse caminho e validar o PUT em homologação.**

## Pendências de ativação total
Esta PR entrega a escrita executável para o caso 4740, protegida por autorização explícita. **Ainda não monitora/grava automaticamente outros contratos**: depois de homologar o piloto, a próxima implantação poderá integrar a execução a um job com flag, consulta frequente, alerta de erros, limite de atribuições por ciclo e reconciliação de reservas. Não associar todo contrato que tenha produto 74: exigir o marcador exato, status elegível e ausência de vínculo anterior.

Referência oficial: https://api-provedor.ixcsoft.com.br/ seção PUT Contratos.
