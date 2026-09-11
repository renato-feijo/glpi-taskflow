<?php

namespace GlpiPlugin\Sccd;

/**
 * Isola a chamada à API do SCCD. Hoje a API real ainda não está definida —
 * este método só simula sucesso, para que o resto do fluxo (marcar como
 * enviado, guardar a data) já funcione. Trocar o corpo deste método pela
 * chamada HTTP real não deve exigir mudanças em mais nenhum arquivo.
 */
class SccdClient
{
    public static function send(Queue $item): bool
    {
        return true;
    }
}
