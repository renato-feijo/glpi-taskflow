# Instruções para o Claude Code neste repositório

## Guard rail: ações irreversíveis exigem autorização explícita, sempre

Este repositório tem CI/CD automático: qualquer `git push` na branch
`11.0/bugfixes` já dispara build + deploy em produção
(`taskflow.renatofeijo.com.br`). Além disso, mudanças de dados são aplicadas
rodando scripts PHP diretamente contra o banco de produção (ver
`tools/*.php`).

Por isso, as ações abaixo só podem acontecer depois que o usuário confirmar
**aquela ação específica**, na conversa em andamento — nunca por inferência
do que foi discutido antes, e nunca dentro de um subagente/fork lançado para
outra finalidade (mesmo que ele "herde" o contexto da conversa):

- `git commit` e `git push` neste repositório.
- Rodar qualquer script de `tools/` sem `--dry-run` (ou seja, que escreve no
  banco de produção).
- Qualquer alteração em produção via `docker exec` no host `taskflow-01`.

Um subagente/fork cujo prompt não pediu explicitamente uma dessas ações não
deve realizá-la por conta própria, mesmo que a conversa mãe já tenha
discutido a tarefa maior. Se a próxima etapa de um plano for escrever um
script, rodá-lo contra produção, ou commitar/pushar, isso deve acontecer no
turno principal da conversa (ou em um subagente pedido explicitamente para
fazer só isso, depois do "sim" do usuário) — não silenciosamente dentro de um
fork lançado para pesquisa/leitura.
