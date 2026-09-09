# Backups de tema

Versões anteriores de `files/_themes/*.scss`, guardadas **fora** daquele
diretório de propósito: o `Dockerfile` copia `files/_themes/` inteiro para
`/opt/glpi-themes/`, e o entrypoint instala cada `.scss` de lá no volume. Um
backup ali viraria uma paleta extra selecionável na interface.

| Arquivo | O que é |
|---|---|
| `moderndark-teal-2026-09-09.scss` | ModernoDark como estava até 09/09/2026: accent teal `#49D4D0` sobre navy `#08111B`. Substituído pela paleta violeta derivada do RunPod (`#5D29F0` / `#BBB6FD` sobre `#06040D`). |

Para voltar:

```sh
cp tools/themes-backup/moderndark-teal-2026-09-09.scss files/_themes/moderndark.scss
git commit -am 'Revert ModernoDark to the teal palette' && git push
```

O push reconstrói a imagem e o redeploy reinstala o tema no volume. Depois,
limpar o cache em *Configuração > Geral > Manutenção* — o CSS compilado fica
em cache pelo `front/css.php`.

Equivalente sem backup em arquivo: `git show c6151667d5:files/_themes/moderndark.scss`.
