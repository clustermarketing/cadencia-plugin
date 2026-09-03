# Cadência para WordPress

Extensão oficial que liga um site WordPress à [CadêncIA](https://cadencia.soucluster.com.br), a plataforma de conteúdo da Cluster.

Sozinha ela não faz nada: é a ponte que a plataforma usa para escrever no site. Sem uma conta CadêncIA, fica inerte e não adiciona nada às suas páginas.

## O que ela faz no seu site

- Expõe os campos de SEO do Rank Math e do Yoast pela API REST.
- Publica no `<head>` o JSON-LD (Article, FAQPage, BreadcrumbList) que a plataforma envia, sem despejar JSON no corpo do post.
- Widget opcional "Resuma este artigo com IA".
- Player opcional de narração do artigo.
- Endpoint de verificação de posse do site (Search Console e Bing).
- Aplica os redirects 301 das páginas que a plataforma consolidou, quando duas páginas suas disputavam a mesma busca.

## Instalação

Baixe o `cadencia.zip` da [release mais recente](https://github.com/clustermarketing/cadencia-plugin-wp/releases/latest) e instale por *Plugins → Adicionar novo → Enviar plugin* no wp-admin. A extensão também está no diretório do WordPress.org.

**Vindo da versão 1.6.0 ou anterior?** A pasta mudou de `seo-api-bridge` para `cadencia` na 1.7.0. As duas convivem sem quebrar o site (os identificadores internos são distintos), e a versão nova desativa a antiga sozinha no primeiro carregamento. Pode remover a antiga pelo wp-admin depois.

## Requisitos

WordPress 5.6+, PHP 7.4+. A plataforma autentica por Application Password de um usuário administrador.

## Desenvolvimento

Arquivo único, sem build e sem dependências. `cadencia.php` é o plugin inteiro.

Regras que valem lembrar antes de mexer:

- **Todo identificador leva o prefixo `cadencia_ext_` / `CADENCIA_EXT_`.** A versão anterior, que ainda pode estar instalada na mesma máquina, declara `cadencia_*` sem prefixo. Colidir é fatal error em PHP, ou seja, site do cliente fora do ar.
- **Nomes de option e chaves de meta NÃO levam prefixo** (`cadencia_redirects`, `_cadencia_jsonld`, ...). É onde os dados vivem: renomear faz a extensão ignorar tudo que a versão anterior gravou.
- O código passa pelo Plugin Check do WordPress.org. Nada de SQL direto, toda superglobal com `wp_unslash` antes de `sanitize_*`, tudo escapado na saída, strings com o text domain `cadencia`.

Conferir a sintaxe antes de publicar:

```bash
php -l cadencia.php
```

## Publicar uma versão

1. Suba a versão nos dois lugares que precisam bater: o header `Version:` do `cadencia.php` e o `version` do `plugin.manifest.json`. O workflow falha se divergirem.
2. Acrescente a entrada no changelog do `readme.txt` e ajuste o `Stable tag`.
3. Crie a tag e empurre:

```bash
git tag v1.7.0 && git push origin v1.7.0
```

O workflow empacota o `cadencia.zip`, publica a release e move a tag `latest` para o mesmo commit.

4. No monorepo da CadêncIA, suba a versão em `frontend/src/lib/wordpress-plugin.manifest.json` — é ela que faz o painel oferecer a atualização ao cliente e montar a URL de download.

## Licença

GPLv2 ou posterior.
