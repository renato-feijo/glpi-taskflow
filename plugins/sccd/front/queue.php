<?php

use GlpiPlugin\Sccd\Queue;

Session::checkRight('plugin_sccd', READ);

Html::header(Queue::getTypeName(), $_SERVER['PHP_SELF'], 'admin', Queue::class);

Search::show(Queue::class);

Html::footer();
