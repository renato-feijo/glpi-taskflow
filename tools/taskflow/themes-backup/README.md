# Backups de tema

Versões anteriores de `files/_themes/*.scss`, guardadas **fora** daquele
diretório de propósito: o `Dockerfile` copia `files/_themes/` inteiro para
`/opt/glpi-themes/`, e o entrypoint instala cada `.scss` de lá no volume. Um
backup ali viraria uma paleta extra selecionável na interface.

Em ordem cronológica — a última linha é a que está no ar.

| Arquivo | Paleta | Accent |
|---|---|---|
| `moderndark-teal-2026-09-09.scss` | Teal sobre navy `#08111B` | `#49D4D0`, texto escuro |
| `moderndark-runpod-2026-09-09.scss` | RunPod, medida do painel de referência: `#12101F` / `#211641` | `#5D29F0` em preenchimento, `#BBB6FD` em link |
| *(em `files/_themes/`)* | Violeta Noturno (FinanciaAuto v1.0): `#11101D` / `#201A33` | `#B6A4F6` com texto `#211431` |

A primeira tentativa RunPod (tokens de chrome, bordas a 1,04:1 — as "linhas quase
invisíveis") não está aqui: ela nunca sobreviveu a uma revisão e está só em
`939a51c048`, se um dia for preciso.

Para voltar:

```sh
cp tools/themes-backup/<arquivo>.scss files/_themes/moderndark.scss
git commit -am 'Revert ModernoDark to <paleta>' && git push
```

Atenção ao voltar para uma versão anterior ao Violeta Noturno: nenhuma delas
carrega fontes, então `public/fonts/` fica órfão. Os arquivos não incomodam
(nada os referencia), mas se a reversão for definitiva vale removê-los.

O push reconstrói a imagem e o redeploy reinstala o tema no volume. Depois,
limpar o cache em *Configuração > Geral > Manutenção* — o CSS compilado fica
em cache pelo `front/css.php`.

Equivalente sem backup em arquivo: `git show c6151667d5:files/_themes/moderndark.scss`.
