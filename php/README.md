# Pergunta.Online PHP

Versão funcional em PHP + MySQL do Pergunta.Online. Não usa Vinext, React, Node ou build frontend. A camada de apresentação usa templates Mustache em `templates/`, enquanto os arquivos PHP ficam responsáveis por validação, consultas e preparação do contexto.

## Requisitos

- PHP 8.1 ou superior
- MySQL 8.0+ ou MariaDB compatível
- extensão `pdo_mysql`
- Apache ou Nginx apontando o document root para esta pasta, ou para uma pasta que a contenha

Não é necessário Composer para renderizar os templates; o projeto inclui um renderer Mustache pequeno, suficiente para variáveis, escaping, seções, seções invertidas e partials usados pela aplicação.

## Estrutura de views

- `templates/partials/`: cabeçalho, rodapé e estruturas compartilhadas;
- `templates/admin/`: telas administrativas;
- `templates/*.mustache`: páginas públicas, autenticação, instalação e formulário de pergunta;
- `includes/layout.php`: prepara o contexto comum e chama o renderer;
- `includes/mustache.php`: renderer dos arquivos `.mustache`.

Os valores `{{variavel}}` são escapados por padrão. HTML pré-sanitizado usa `{{{variavel}}}` somente nos poucos pontos em que é necessário preservar quebras de linha.

## Configuração

Edite diretamente o arquivo `config.php`:

```php
return [
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'pergunta_online',
        'user' => 'pergunta_online',
        'password' => 'troque-esta-senha',
    ],
    'app_url' => '',
];
```

`app_url` pode ficar vazio para detectar automaticamente o domínio e o subdiretório. Em produção, altere a senha diretamente no `config.php` do servidor e não faça commit da credencial real no repositório público.

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

O `config.php` bloqueia execução direta e o `.htaccess` também nega acesso ao arquivo no Apache. Em Nginx, o arquivo PHP continua sendo executado normalmente e não imprime as configurações, mas recomenda-se manter a configuração do servidor impedindo a entrega de código-fonte PHP. Use HTTPS em produção para que o cookie de sessão seja marcado como `Secure`.
