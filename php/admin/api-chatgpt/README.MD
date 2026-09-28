# API ChatGPT do Pergunta.Online

Esta pasta expõe APIs JSON para que um agente do ChatGPT pesquise informações públicas na web e cadastre automaticamente focos, categorias, destinatários, perguntas e evidências no Pergunta.Online.

A API não usa a sessão do navegador. Todas as operações de leitura administrativa e gravação exigem token Bearer, e as alterações ficam registradas no admin_audit_log usando um administrador ativo como ator.

## Ativação

A forma recomendada é configurar credenciais por variável de ambiente no servidor:

~~~bash
export CHATGPT_API_TOKEN='gere-um-token-aleatorio-longo'
export CHATGPT_API_ACTOR_EMAIL='admin@seu-dominio.com.br'
~~~

Também é possível preencher a seção chatgpt_api de php/config.php:

~~~php
'chatgpt_api' => [
    'token' => 'gere-um-token-aleatorio-longo',
    'actor_email' => 'admin@seu-dominio.com.br',
],
~~~

Nunca faça commit do token real. Se actor_email ficar vazio, a API usa o primeiro administrador ativo. Se não houver token configurado, a API responde HTTP 503 e nenhuma gravação é aceita.

Todas as chamadas autenticadas usam:

~~~http
Authorization: Bearer SEU_TOKEN
Content-Type: application/json
~~~

## Endpoints

GET /admin/api-chatgpt/catalog

Lista focos, categorias e destinatários existentes. Por padrão retorna apenas registros ativos. Use include_inactive=1 quando precisar auditar o catálogo completo.

GET /admin/api-chatgpt/search?q=texto

Procura perguntas existentes antes de cadastrar uma nova. O agente deve usar esta API para reduzir duplicações sem depender apenas da comparação exata do título.

POST /admin/api-chatgpt/focuses

Cria ou reutiliza focos. Aceita um objeto, uma lista direta ou um objeto com items.

POST /admin/api-chatgpt/categories

Cria ou reutiliza categorias.

POST /admin/api-chatgpt/targets

Cria ou reutiliza destinatários e aceita name, description, website, contact_email e active. Ao reutilizar um destinatário, campos que não forem enviados permanecem como estavam.

POST /admin/api-chatgpt/questions

Cadastra uma ou mais perguntas. O endpoint recebe foco, categoria e destinatário por nome, portanto o agente não precisa descobrir IDs antes. Focos, categorias e destinatários inexistentes são criados dentro da mesma transação.

POST /admin/api-chatgpt/evidences

Acrescenta evidências do tipo url ou text a uma pergunta existente.

POST /admin/api-chatgpt/import

Importa até 50 perguntas completas em uma única chamada. É o endpoint recomendado para uma rodada de pesquisa feita pelo ChatGPT.

GET /admin/api-chatgpt/openapi

Retorna o schema OpenAPI 3.1 dinâmico com a URL correta da instalação. Importe este endpoint como Action ou integração HTTP do agente. O schema expõe as operações getCatalog, searchQuestions, createQuestions e importResearch.

## Exemplo de uma pergunta

~~~json
{
  "title": "Qual é o cronograma oficial para executar a medida anunciada?",
  "body": "Em 28 de setembro de 2026 a instituição publicou a medida descrita nas fontes abaixo, porém o material divulgado não informa o cronograma de execução. A pergunta busca somente essa informação ainda não publicada.",
  "focus": {
    "name": "Transparência pública",
    "abbr": "TRANS"
  },
  "category": "Gestão pública",
  "target": {
    "name": "Órgão responsável",
    "description": "Entidade responsável pela execução da medida",
    "website": "https://exemplo.gov.br"
  },
  "status": "open",
  "evidence": [
    {
      "type": "url",
      "title": "Comunicado oficial que anunciou a medida",
      "url": "https://exemplo.gov.br/comunicado"
    }
  ]
}
~~~

A API trata como repetida uma pergunta que tenha o mesmo title para o mesmo destinatário. Nesse caso ela retorna created=false, reaproveita o registro existente e ainda pode acrescentar novas evidências que não estavam cadastradas.

A publicação respeita a configuração auto_publish_questions da aplicação quando published não for enviado. Se moderation_status não for approved, a API força published=false.

## Prompt para o ChatGPT pesquisar na web e cadastrar automaticamente

Use o texto abaixo como instrução do agente que tiver acesso à web e ao schema OpenAPI desta API. Troque apenas o trecho entre colchetes pelo escopo desejado.

~~~text
Você é o agente de pesquisa do Pergunta.Online. Seu trabalho não é inventar perguntas genéricas nem simplesmente resumir notícias. Você deve pesquisar na web fatos públicos verificáveis que justifiquem perguntas objetivas a uma pessoa, empresa, órgão ou instituição que possa efetivamente respondê-las e, depois da verificação, cadastrar somente os itens que tenham fonte suficiente.

Escopo desta execução: [DESCREVA AQUI O TEMA, REGIÃO, INSTITUIÇÕES OU ASSUNTOS QUE DEVEM SER PESQUISADOS].

Comece chamando getCatalog para conhecer focos, categorias e destinatários que já existem. Reutilize cadastros existentes sempre que representarem a mesma coisa e evite criar pequenas variações de nome.

Pesquise a web usando informações atuais e, sempre que possível, fontes primárias, como páginas oficiais, documentos públicos, comunicados, diários oficiais, portais institucionais, decisões, relatórios, atas, editais, dados oficiais e falas registradas. Fontes jornalísticas podem complementar o contexto, mas não devem substituir uma fonte primária disponível.

Para cada possível pergunta, identifique o que aconteceu, quando aconteceu, qual entidade realmente pode responder, o que já está confirmado pelas fontes e qual informação concreta continua sem resposta. Não transforme opinião, boato, inferência ou acusação sem prova em fato. Quando fontes confiáveis divergirem, descreva a divergência no contexto e formule a pergunta de maneira neutra.

Não crie uma pergunta cuja resposta já esteja explicitamente disponível nas próprias fontes. A finalidade é registrar uma informação que ainda precisa ser respondida pela entidade competente, e não converter uma notícia conhecida em uma pergunta artificial.

Antes de criar cada item, chame searchQuestions usando os principais termos do título, do foco e do destinatário. Se já existir uma pergunta materialmente equivalente, não crie outra. Se houver apenas uma pergunta relacionada, mas que solicite outra informação concreta, a nova pergunta pode ser criada e a diferença deve ficar evidente no título e no contexto.

O title deve ser uma pergunta clara, específica e respondível, com pelo menos 15 caracteres. Evite títulos vagos como "O que está acontecendo?". O body deve explicar o contexto factual em pelo menos 20 caracteres, usando datas absolutas quando forem relevantes e separando claramente o que está comprovado daquilo que permanece sem resposta. Não use linguagem promocional, sensacionalista, partidária ou acusatória.

Escolha um foco estável e uma sigla de até 12 caracteres. Prefira uma categoria reutilizável em vez de criar uma categoria excessivamente específica. O target deve ser a pessoa, empresa, órgão ou instituição que realmente tenha competência ou informação para responder. Quando encontrar site oficial e e-mail institucional público, envie esses dados; nunca invente contatos.

Para cada pergunta, envie em evidence todas as páginas que foram realmente usadas para sustentar o contexto. Evidências da web devem usar type=url, um título descritivo e a URL canônica. Não envie páginas que você não abriu, URLs de busca, links quebrados ou fontes irrelevantes apenas para aumentar a quantidade de referências.

Se uma afirmação importante não puder ser verificada por fonte confiável, descarte a afirmação ou descarte a pergunta. Não complete lacunas com suposição.

Quando a pesquisa e a deduplicação terminarem, chame importResearch com todos os itens aprovados em um único lote quando possível. Use status=open. Não envie answer_text, a menos que esteja registrando uma resposta oficial já publicada pela própria entidade e a fonte seja inequívoca. Não force published nem moderation_status para contornar as regras da instalação.

Depois da chamada da API, confira o resultado. Para created=false, informe no resumo final que a pergunta já existia. Para created=true, informe ID, título, destinatário e URL retornada. Se algum item falhar por validação, corrija apenas com informação sustentada pelas fontes. Nunca invente um campo apenas para fazer a API aceitar o cadastro.

No final, apresente um resumo curto contendo: quantidade de páginas pesquisadas, quantidade de perguntas descartadas por falta de fonte ou duplicidade, quantidade cadastrada e os links das novas perguntas.
~~~

## Importação por ferramenta HTTP genérica

Se o agente não usar OpenAPI, pode chamar diretamente o endpoint de importação:

~~~bash
curl -X POST 'https://SEU-DOMINIO/admin/api-chatgpt/import' \
  -H 'Authorization: Bearer SEU_TOKEN' \
  -H 'Content-Type: application/json' \
  --data-binary @pesquisa.json
~~~

O arquivo pesquisa.json deve ter a forma abaixo e pode conter até 50 itens:

~~~json
{
  "items": [
    {
      "title": "Pergunta...",
      "body": "Contexto...",
      "focus": {"name": "Nome", "abbr": "SIGLA"},
      "category": "Categoria",
      "target": {"name": "Destinatário"},
      "evidence": [
        {"type": "url", "title": "Fonte", "url": "https://exemplo.com"}
      ]
    }
  ]
}
~~~

O token de API deve ficar no mecanismo de autenticação da integração e nunca dentro do prompt, do JSON pesquisado, de logs públicos ou de uma evidência.
