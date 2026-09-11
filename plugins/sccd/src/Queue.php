<?php

namespace GlpiPlugin\Sccd;

class Queue extends \CommonDBTM
{
    public static $rightname = 'plugin_sccd';

    public static function getTypeName($nb = 0)
    {
        return __('SCCD');
    }

    public static function getIcon()
    {
        return 'ti ti-send';
    }

    public static function getMenuContent()
    {
        $menu = [];
        if (static::canView()) {
            $menu['title'] = static::getTypeName();
            $menu['page']  = static::getSearchURL(false);
            $menu['icon']  = static::getIcon();
        }
        return count($menu) ? $menu : false;
    }

    public function showForm($ID, array $options = [])
    {
        $this->initForm($ID, $options);
        $this->showFormHeader($options);

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Chamado') . "</td>";
        echo "<td>#" . $this->fields['tickets_id'] . " — " . $this->fields['name'] . "</td>";
        echo "<td>" . __('Status') . "</td>";
        echo "<td>" . \Ticket::getStatus($this->fields['status']) . "</td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td>" . __('Enviado ao SCCD') . "</td>";
        echo "<td>" . ($this->fields['sccd_sent'] ? \Html::convDateTime($this->fields['sccd_sent_date']) : __('Não')) . "</td>";
        echo "<td colspan='2'></td>";
        echo "</tr>";

        echo "<tr class='tab_bg_1'>";
        echo "<td colspan='4'>";
        echo "<label for='content'>" . __('Texto a enviar') . "</label><br>";
        echo "<textarea class='form-control' rows='10' name='content'>" . \Html::entities_deep($this->fields['content']) . "</textarea>";
        echo "</td>";
        echo "</tr>";

        $this->showFormButtons([
            'candel'     => false,
            'addbuttons' => [
                'send_to_sccd' => __('Enviar para SCCD'),
            ],
        ]);

        return true;
    }
}
