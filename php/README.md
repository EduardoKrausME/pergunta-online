# Pergunta.Online PHP

Versão funcional em PHP + MySQL do Pergunta.Online. Não usa Vinext, React, Node ou build frontend.

## Requisitos

- PHP 8.1 ou superior
- MySQL 8.0+ ou MariaDB compatível
- extensão `pdo_mysql`
- Apache ou Nginx apontando o document root para esta pasta, ou para uma pasta que a contenha

## Configuração

Crie o banco vazio e configure as variáveis de ambiente no servidor:

```text
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=pergunta_online
DB_USER=pergunta_online
DB_PASS=troque-esta-senha
APP_URL=https://seu-dominio.com
```

`APP_URL` é opcional quando o PHP está servido diretamente por um domínio/subdiretório convencional.

Depois acesse `install.php`. O instalador cria as tabelas e pede o primeiro administrador. Depois disso, novos usuários podem se cadastrar em `register.php` e recebem sempre o perfil `user`.

## Funcionalidades

- home pública com busca, focos, assuntos e estados da pergunta;
- cadastro público de usuários;
- login com `password_hash()`/`password_verify()`, sessão regenerada e limitação básica de tentativas;
- criação de perguntas por usuários autenticados;
- endosso “Boa!” e perguntas salvas, persistidos no MySQL;
- cronômetro de silêncio a partir de `waiting_since`;
- página individual da pergunta;
- administração com indicadores;
- gestão de usuários: ativo/inativo e user/admin;
- gestão de perguntas: aberta, aguardando resposta, pauta assumida, respondida ou arquivada;
- registro da resposta na própria pergunta;
- CSRF em todas as ações POST e consultas preparadas via PDO.

## Segurança e produção

Não coloque credenciais diretamente nos arquivos PHP. Configure-as no ambiente do PHP-FPM/Apache. Em produção use HTTPS para que o cookie de sessão seja marcado como `Secure`. O `.htaccess` bloqueia o acesso direto ao `schema.sql` em Apache; em Nginx, crie regra equivalente ou mantenha a pasta fora de listagem pública.
