# Referências funcionais para o JRC-ACS 2.0

O usuário aprovou a proposta azul/ciano JR Conect inspirada na Flowbix. As capturas
recebidas dos parceiros são referências visuais e funcionais; não fornecem contrato de API,
código, requisitos de integração ou comprovação da disponibilidade dos recursos no JRC-ACS.

Fontes públicas consultadas: [portfólio Flowbix](https://flowbix.online/) e
[apresentação de gestão de OLT](https://flowbix.online/gestao-olt). São apresentações,
não um manual técnico público do Scout ACS. A documentação em app.flowbix.com/documentacao
é do Sentrya e não deve ser tratada como documentação do Scout.

## Entrega atual na PR 25

- Consulta GenieACS paginada e limitada, sem carregar árvores completas.
- RX do inventário IXC com a mesma tabela/fonte usada pela integração legada.
- Indicadores ópticos de todo o inventário IXC; comunicação e atenção de equipamentos ACS.
- Histórico das observações ACS feitas pelo painel, sem preencher períodos sem coleta.
- Agrupamento cadastral por OLT/slot/PON, ordenado por quantidade de RX crítico.
- Navegação para as funções existentes; dashboard antiga e login preservados.

## Próximas etapas das funções mostradas nas imagens

| Referência recebida | Implementação necessária antes de apresentar como operacional |
| --- | --- |
| Dispositivo: RX/TX, temperatura, tensão e bias | Mapear parâmetros por modelo/firmware, unidade, validade e data da leitura; mostrar indisponibilidade quando ausentes |
| WAN/PPPoE, Wi-Fi e hosts | Reaproveitar consultas existentes e apresentar estados reportados com horário; proteger credenciais |
| Tráfego instantâneo e consumo de 24 horas | Amostrar contadores reais, tratar reinício/overflow e persistir série; uma leitura isolada não mede velocidade |
| Inform no prazo | Comparar último Inform ao intervalo configurado do próprio equipamento e tolerância definida; o painel atual usa janela fixa de cinco minutos |
| Inform recebido por hora | Coletar eventos reais no backend; snapshots de último Inform não permitem reconstruir o número de eventos |
| Firmware alvo | Catálogo de versões aprovadas por modelo/hardware e comparação; atualização exige fluxo próprio de validação |
| Alertas reconhecidos e resolvidos | Persistência de eventos, ciclo de vida, permissões e auditoria; triagem de snapshot não é histórico de alertas |
| Ping, traceroute, Wi-Fi e reinício | Reutilizar ações existentes após verificar suporte TR-069, autorização, registro e retorno; não executar ações pela dashboard |
| Saúde OLT/PON e falhas coletivas | Telemetria OLT/Zabbix/SNMP, associação correta e série temporal; o cadastro IXC não confirma estado ou capacidade da porta |
| Catálogo de chaves, provisionamento e descoberta | Inventariar capacidades existentes e homologar por modelo antes de expor novos fluxos |

A sequência é consolidar a visão geral, modernizar a lista de dispositivos e depois a tela
individual, com abas de visão geral, óptico, WAN, Wi-Fi, hosts e histórico. Os valores das
imagens promocionais não entram na aplicação. Limiares devem ser definidos para a rede JR Conect.
