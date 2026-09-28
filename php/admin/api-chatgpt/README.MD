# Prompt diário para o ChatGPT

XXXX é exatamente o assunto, termo, pessoa, empresa, órgão, tema ou conjunto de palavras que deve ser pesquisado na WEB.

Todo dia, busque na WEB por XXXX e cadastre automaticamente no Pergunta.Online os resultados que realmente gerem perguntas públicas relevantes, objetivas e verificáveis.

Use obrigatoriamente a API abaixo para consultar o que já existe, evitar duplicidades e cadastrar os novos itens.

API base:

https://pergunta-online.diegoramos.tech/admin/api-chatgpt/

Token:

pergunta-online-academico-2026-chatgpt

Em todas as chamadas envie:

```http
Authorization: Bearer pergunta-online-academico-2026-chatgpt
Content-Type: application/json
```

Antes de pesquisar, consulte o catálogo atual:

```
GET https://pergunta-online.diegoramos.tech/admin/api-chatgpt/catalog.php
```

O catálogo contém focos, categorias e destinatários já cadastrados. Reutilize os registros existentes sempre que representarem a mesma coisa e não crie pequenas variações de nome para entidades que já existem.

Depois pesquise a WEB exatamente pelo conteúdo informado em XXXX, procurando fatos atuais, relevantes e verificáveis. Priorize fontes primárias, como sites oficiais, comunicados, documentos públicos, relatórios, decisões, portais institucionais, legislação, diários oficiais, atas, editais, dados oficiais, vídeos oficiais e publicações feitas pela própria pessoa ou organização envolvida.

Fontes jornalísticas e outras fontes independentes podem complementar o contexto, principalmente quando não existir uma fonte primária suficiente, mas não transforme opinião, boato, especulação, postagem sem comprovação ou inferência em fato.

Para cada informação encontrada, analise primeiro se realmente existe uma pergunta ainda sem resposta. Não crie uma pergunta apenas para converter uma notícia conhecida em uma pergunta artificial.

A pergunta precisa:

- ser dirigida a uma pessoa, empresa, órgão ou instituição capaz de responder;
- ser clara, específica e respondível;
- pedir uma informação, explicação, posicionamento, compromisso, prazo ou prestação de contas que ainda não esteja respondida pelas próprias fontes;
- ter contexto factual suficiente para que outra pessoa entenda por que a pergunta existe;
- usar datas absolutas quando forem relevantes;
- evitar linguagem promocional, partidária, sensacionalista ou acusatória;
- ser sustentada pelas URLs realmente consultadas.

Antes de cadastrar cada pergunta, procure possíveis duplicidades:

```
GET https://pergunta-online.diegoramos.tech/admin/api-chatgpt/search.php?q=TERMOS_DA_PERGUNTA
```

Pesquise usando os principais termos do assunto, do destinatário e da pergunta. Se já existir uma pergunta materialmente equivalente, não crie outra.

Se existir uma pergunta relacionada, mas a nova pergunta pedir uma informação diferente e concreta, ela pode ser cadastrada.

Quando identificar uma pergunta válida, monte o item neste formato:

```json
{
  "title": "Pergunta clara e específica?",
  "body": "Contexto factual explicando o que aconteceu, quando aconteceu, o que já está comprovado e qual informação ainda falta.",
  "focus": {
    "name": "Nome do foco",
    "abbr": "SIGLA"
  },
  "category": "Categoria",
  "target": {
    "name": "Pessoa, empresa, órgão ou instituição responsável",
    "description": "Descrição curta do destinatário",
    "website": "https://site-oficial.example",
    "contact_email": ""
  },
  "status": "open",
  "evidence": [
    {
      "type": "url",
      "title": "Título descritivo da fonte",
      "url": "https://fonte.example/documento"
    }
  ]
}
```

Não invente website, e-mail, nome, sigla, data ou qualquer outro dado apenas para preencher o JSON. Quando uma informação opcional não estiver disponível, deixe-a vazia ou não a envie.

Cada URL em `evidence` deve ter sido realmente aberta e utilizada para sustentar o contexto. Não envie página de busca, URL quebrada, fonte irrelevante ou fonte que não sustente nenhuma afirmação do texto.

Quando houver várias perguntas válidas, envie todas juntas, com no máximo 50 itens por chamada:

```
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/import.php
```

Corpo:

```json
{
  "items": [
    {
      "title": "Pergunta 1?",
      "body": "Contexto da pergunta 1.",
      "focus": {
        "name": "Nome do foco",
        "abbr": "SIGLA"
      },
      "category": "Categoria",
      "target": {
        "name": "Destinatário"
      },
      "status": "open",
      "evidence": [
        {
          "type": "url",
          "title": "Fonte utilizada",
          "url": "https://fonte.example"
        }
      ]
    }
  ]
}
```

Se precisar cadastrar ou atualizar entidades separadamente, use:

```
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/focuses.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/categories.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/targets.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/questions.php
POST https://pergunta-online.diegoramos.tech/admin/api-chatgpt/evidences.php
```

O schema OpenAPI está disponível em:

```
GET https://pergunta-online.diegoramos.tech/admin/api-chatgpt/openapi.php
```

Ao receber a resposta da API, verifique o campo `created`.

Se `created=true`, a pergunta foi criada.

Se `created=false`, a pergunta já existia e foi reutilizada; não tente criar outra versão apenas mudando algumas palavras.

Se a API rejeitar algum item, corrija somente erros objetivos usando informação que possa ser confirmada nas fontes. Nunca invente dados para fazer a validação passar.

Repita esse processo todos os dias pesquisando exatamente o conteúdo informado em XXXX.

Ao final de cada execução, apresente um resumo contendo:

- o que foi pesquisado;
- quantas fontes foram realmente consultadas;
- quantas perguntas foram descartadas porque a resposta já estava publicada;
- quantas foram descartadas por duplicidade;
- quantas foram cadastradas;
- ID, título, destinatário e URL de cada pergunta criada ou reutilizada.
