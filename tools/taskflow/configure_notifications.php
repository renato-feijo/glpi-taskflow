<?php

/**
 * TaskFlow — liga as notificacoes por e-mail e configura o SMTP.
 *
 * Hoje `use_notifications` esta desligado nesta instalacao: os 24 modelos de
 * notificacao de Ticket existem e estao ativos, mas nada sai. Dois efeitos
 * praticos: nenhum analista sabe que chegou chamado, e o "esqueci minha senha"
 * nao funciona — o que impede um analista de definir a propria senha.
 *
 * Idempotente: grava a configuracao e reexecutar apenas reconfirma.
 *
 * A SENHA DO SMTP NAO FICA NESTE ARQUIVO. Ela vem do ambiente, para nao
 * entrar no historico do git:
 *
 *   TASKFLOW_SMTP_HOST=smtp.exemplo.com.br \
 *   TASKFLOW_SMTP_PORT=587 \
 *   TASKFLOW_SMTP_USER=usuario \
 *   TASKFLOW_SMTP_PASSWORD='...' \
 *   TASKFLOW_ADMIN_EMAIL=suporte@exemplo.com.br \
 *   TASKFLOW_ADMIN_NAME='TaskFlow — Suporte N1' \
 *   php tools/taskflow_configure_notifications.php
 *
 * Opcional: TASKFLOW_SMTP_NO_VERIFY=1 desliga a checagem do certificado do
 * relay (so para relay interno com certificado proprio).
 *
 * Use `--dry-run` para ver o que seria gravado (a senha nunca e exibida).
 * Use `--test` para disparar o e-mail de teste do proprio GLPI depois.
 *
 * Sobre TLS: o GLPI 11 monta um DSN `smtp://usuario:senha@host:porta` e deixa
 * o Symfony Mailer negociar STARTTLS. Os modos MAIL_SMTPTLS e MAIL_SMTPSSL
 * existem mas estao deprecados — o proprio Config.php avisa e pede MAIL_SMTP.
 * Por isso aqui nao ha escolha de modo TLS: use a porta 587 (STARTTLS), que e
 * o caminho suportado. A porta 465 (TLS implicito) exigiria `smtps://`, que o
 * GLPI nao gera.
 */

use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "Este script roda só pela linha de comando\n";
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

$dry_run = in_array('--dry-run', $argv, true);

$testar = in_array('--test', $argv, true);

$host   = getenv('TASKFLOW_SMTP_HOST') ?: null;
$port   = getenv('TASKFLOW_SMTP_PORT') ?: '587';
$user   = getenv('TASKFLOW_SMTP_USER') ?: '';
$pass   = getenv('TASKFLOW_SMTP_PASSWORD');
$from   = getenv('TASKFLOW_ADMIN_EMAIL') ?: null;
$nome   = getenv('TASKFLOW_ADMIN_NAME') ?: 'TaskFlow';
$verify = getenv('TASKFLOW_SMTP_NO_VERIFY') ? 0 : 1;

$erros = [];
if ($host === null)                  { $erros[] = 'TASKFLOW_SMTP_HOST não definido'; }
if ($from === null)                  { $erros[] = 'TASKFLOW_ADMIN_EMAIL não definido'; }
if ($user !== '' && $pass === false) { $erros[] = 'TASKFLOW_SMTP_USER definido sem TASKFLOW_SMTP_PASSWORD'; }

if ($erros !== []) {
    echo "Faltam variáveis de ambiente:\n";
    foreach ($erros as $e) { echo "  - $e\n"; }
    echo "\nVer o comentário no topo deste arquivo.\n";
    exit(1);
}

printf("host      : %s:%s\n", $host, $port);
printf("certificado: %s\n", $verify ? 'verificado' : 'NÃO verificado');
printf("usuário   : %s\n", $user !== '' ? $user : '(sem autenticação)');
printf("senha     : %s\n", $pass ? '(definida, ' . strlen($pass) . ' caracteres)' : '(vazia)');
printf("remetente : %s <%s>\n\n", $nome, $from);

$valores = [
    'use_notifications'     => 1,
    'notifications_mailing' => 1,
    'smtp_mode'             => MAIL_SMTP,   // constante global, ver constants.php
    'smtp_check_certificate' => $verify,
    'smtp_host'             => $host,
    'smtp_port'             => (int) $port,
    'smtp_username'         => $user,
    'admin_email'           => $from,
    'admin_email_name'      => $nome,
    'from_email'            => $from,
    'from_email_name'       => $nome,
];

if ($pass !== false && $pass !== '') {
    // O GLPI guarda a senha do SMTP cifrada; setConfigurationValues cuida
    // disso para a chave smtp_passwd.
    $valores['smtp_passwd'] = $pass;
}

if ($dry_run) {
    echo "Seria gravado:\n";
    foreach ($valores as $k => $v) {
        printf("  %-24s %s\n", $k, $k === 'smtp_passwd' ? '(omitida)' : $v);
    }
    echo "\nSimulação: nada gravado.\n";
    exit(0);
}

Config::setConfigurationValues('core', $valores);
echo "Configuração gravada.\n";

// Relê do banco, não do cache em memória, para confirmar.
global $CFG_GLPI;
$c = new Config();
foreach (['use_notifications', 'notifications_mailing', 'smtp_mode', 'smtp_host', 'smtp_port'] as $k) {
    $achado = $c->find(['context' => 'core', 'name' => $k]);
    printf("  %-24s %s\n", $k, $achado ? reset($achado)['value'] : '?');
}

if ($testar) {
    echo "\nDisparando o e-mail de teste do GLPI (vai para o remetente configurado)...\n";

    // Recarrega a config em memória: setConfigurationValues gravou no banco,
    // mas $CFG_GLPI deste processo ainda tem os valores antigos, e
    // testNotification monta o DSN a partir dele.
    foreach ($valores as $k => $v) { $CFG_GLPI[$k] = $v; }
    if (isset($valores['smtp_passwd'])) {
        $CFG_GLPI['smtp_passwd'] = (new GLPIKey())->encrypt($valores['smtp_passwd']);
    }

    $r = NotificationMailing::testNotification();
    printf("resultado : %s\n", $r['success'] ? 'ACEITO pelo relay' : 'FALHOU');
    if (!$r['success']) {
        printf("erro      : %s\n", $r['error'] ?? '(sem detalhe)');
        if (!empty($r['debug'])) { printf("debug     : %s\n", $r['debug']); }
    }
    exit($r['success'] ? 0 : 1);
}

echo "\nPróximo passo: rodar de novo com --test para confirmar que o relay\n";
echo "aceita, antes de criar as contas dos analistas.\n";
