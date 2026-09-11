<?php

use Glpi\Exception\Http\BadRequestHttpException;
use GlpiPlugin\Sccd\Queue;
use GlpiPlugin\Sccd\SccdClient;

Session::checkRight('plugin_sccd', READ);

$item = new Queue();

if (isset($_POST['send_to_sccd'])) {
    Session::checkRight('plugin_sccd', UPDATE);
    $item->check($_POST['id'], UPDATE);

    // Grava o texto editado antes de "enviar", para o registro refletir o
    // que de fato foi para o SCCD.
    $item->update([
        'id'      => $_POST['id'],
        'content' => $_POST['content'],
    ]);
    $item->getFromDB($_POST['id']);

    SccdClient::send($item);

    $item->update([
        'id'             => $_POST['id'],
        'sccd_sent'      => 1,
        'sccd_sent_date' => $_SESSION['glpi_currenttime'],
    ]);
    Html::back();
} elseif (isset($_POST['update'])) {
    Session::checkRight('plugin_sccd', UPDATE);
    $item->check($_POST['id'], UPDATE);
    $item->update($_POST);
    Html::back();
} elseif (isset($_GET['id'])) {
    Html::header(Queue::getTypeName(), $_SERVER['PHP_SELF'], 'admin', Queue::class);
    $item->display(['id' => $_GET['id']]);
    Html::footer();
} else {
    throw new BadRequestHttpException();
}
