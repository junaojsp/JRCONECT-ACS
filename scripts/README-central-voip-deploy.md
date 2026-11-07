# Instalacao controlada da Central VoIP no ACS

Esta PR adiciona apenas um instalador isolado em `scripts/deploy-central-voip.sh`.

## Executar DEPOIS do merge desta PR

No SSH do ACS como root, copiar e executar este bloco:

```bash
git -C /opt/jrconect-acs-git fetch origin main && git -C /opt/jrconect-acs-git show FETCH_HEAD:scripts/deploy-central-voip.sh > /tmp/jrc-deploy-central-voip.sh && bash -n /tmp/jrc-deploy-central-voip.sh && bash /tmp/jrc-deploy-central-voip.sh
```

A atualizacao usa `git fetch`, sem `git pull`, `reset`, `checkout` ou modificacao da arvore de trabalho. O script usa somente os dois arquivos esperados na publicacao `/var/www/gacs`. Nao reinicia componentes.

## O que faz

- Confirma que o diretorio Git corresponde a `junaojsp/JRCONECT-ACS-MCP` e ha commit valido em `FETCH_HEAD`.
- Verifica `/var/www/gacs/dashboard.php` e `views/layouts/header.php`.
- Extrai do GitHub a nova pagina VoIP e verifica que o menu esta na versao aprovada.
- Inclui o item Central VoIP no menu do header **atual de producao**, preservando outras alteracoes locais.
- Valida PHP antes e depois de publicar; impede link duplicado.
- Faz backup de ambos os arquivos em `/var/backups/jrconect-acs/central-voip-<data>`.
- Em erro apos inicio das alteracoes, restaura o backup. Nao altera banco, cron, MCP ou IXC.

## Verificacao

Acessar `https://painelacs.jrconect.com/voip-central.php` autenticado, confirmar item do menu, e realizar smoke tests nas paginas existentes.

## Rollback manual (somente se necessario)

O script imprime caminho do backup. A restauracao deve ser feita com esse caminho especifico, mantendo permissao e ownership dos arquivos. Se `voip-central.php` nao existia antes da publicacao, remover somente esse arquivo apos restaurar o header. Nao fazer `git reset --hard`.

**Atencao:** O caminho `/var/www/gacs` foi identificado como provavel ambiente de publicacao; o script valida marcadores desse diretorio e aborta se eles nao existirem, sem tocar em outras instalacoes. A confirmacao da URL depende do teste no navegador.
