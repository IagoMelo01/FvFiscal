<?php
/* Copyright (C) 2025           SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Lightweight helper to render Dolibarr compatible list tables.
 */
class llist
{
    /** @var string */
    private $id;

    /** @var array<int, array<string, mixed>> */
    private $headers = array();

    /** @var string */
    private $emptyMessage = '';

    /**
     * @param string $id HTML identifier
     */
    public function __construct($id)
    {
        $this->id = $id;
    }

    /**
     * @param array<int, array<string, mixed>> $headers
     * @return void
     */
    public function setHeaders(array $headers)
    {
        $this->headers = $headers;
    }

    /**
     * @param string $message
     * @return void
     */
    public function setEmptyMessage($message)
    {
        $this->emptyMessage = $message;
    }

    /**
     * Render the list.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return string
     */
    public function render(array $rows)
    {
        $out = '<div class="div-table-responsive-no-min">';
        $out .= '<table id="'.dol_escape_htmltag($this->id).'" class="noborder centpercent">';
        $out .= '<tr class="liste_titre">';
        foreach ($this->headers as $header) {
            $label = isset($header['label']) ? $header['label'] : '';
            $align = empty($header['align']) ? 'left' : $header['align'];
            $out .= '<th class="'.dol_escape_htmltag($align).'">'.dol_escape_htmltag($label).'</th>';
        }
        $out .= '</tr>';

        if (empty($rows)) {
            $colspan = max(count($this->headers), 1);
            $out .= '<tr class="oddeven">';
            $out .= '<td class="center" colspan="'.$colspan.'">'.dol_escape_htmltag($this->emptyMessage).'</td>';
            $out .= '</tr>';
        } else {
            $var = false;
            foreach ($rows as $row) {
                $var = !$var;
                $rowClass = 'oddeven'.($var ? ' even' : ' odd');
                if (!empty($row['active'])) {
                    $rowClass .= ' rowselected';
                }
                if (!empty($row['class'])) {
                    $rowClass .= ' '.dol_escape_htmltag($row['class']);
                }

                $out .= '<tr class="'.$rowClass.'">';

                $columns = isset($row['columns']) && is_array($row['columns']) ? $row['columns'] : array();
                foreach ($columns as $column) {
                    $value = isset($column['value']) ? $column['value'] : '';
                    $align = empty($column['align']) ? 'left' : $column['align'];
                    $class = 'nowrap '.dol_escape_htmltag($align);
                    if (!empty($column['class'])) {
                        $class .= ' '.dol_escape_htmltag($column['class']);
                    }

                    $out .= '<td class="'.$class.'">';
                    $content = (string) $value;
                    if (empty($column['is_html'])) {
                        $content = dol_escape_htmltag($content);
                    }

                    if (!empty($row['url']) && empty($column['no_link'])) {
                        $out .= '<a href="'.dol_escape_htmltag($row['url']).'">'.$content.'</a>';
                    } else {
                        $out .= $content;
                    }

                    $out .= '</td>';
                }

                $out .= '</tr>';
            }
        }

        $out .= '</table>';
        $out .= '</div>';

        return $out;
    }
}
