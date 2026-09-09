# Fontes auto-hospedadas

Exigidas pelo padrão visual "Violeta Noturno" (ver `files/_themes/moderndark.scss`):
Manrope nos títulos, DM Sans no corpo.

Auto-hospedadas, e não via `@import` do Google Fonts, por dois motivos: o GLPI
é interno do DER/PE, e um import externo faria cada carregamento de página
bater num terceiro, expondo o IP de quem abre um chamado; e o produto precisa
funcionar em rede restrita.

| Arquivo | Família | Subconjunto |
|---|---|---|
| `dm-sans-latin.woff2` | DM Sans (variável, 400–700) | latin |
| `dm-sans-latin-ext.woff2` | DM Sans (variável, 400–700) | latin-ext |
| `manrope-latin.woff2` | Manrope (variável, 400–700) | latin |
| `manrope-latin-ext.woff2` | Manrope (variável, 400–700) | latin-ext |

São **fontes variáveis**: um arquivo por subconjunto cobre toda a faixa de peso,
declarada como `font-weight: 400 700` no `@font-face`. Só `latin` e `latin-ext`
foram baixados — bastam para português; os outros subconjuntos (cirílico, grego,
vietnamita) ficariam sem uso. Total: 104 KB.

`@font-face` fica no arquivo de tema, com `unicode-range` por subconjunto, para
o navegador buscar só o que a página precisa.

## Licença

Ambas sob SIL Open Font License 1.1 — redistribuição permitida, com a licença
incluída (`OFL-DM-Sans.txt`, `OFL-Manrope.txt`).

## Atualizar

Os nomes de arquivo do Google Fonts carregam hash de versão e mudam. Para
renovar, pegar as URLs atuais com um User-Agent moderno (senão vem TTF em vez
de woff2):

```sh
curl -A 'Mozilla/5.0 ... Chrome/142' \
  'https://fonts.googleapis.com/css2?family=Manrope:wght@400..700&family=DM+Sans:wght@400..700&display=swap'
```
