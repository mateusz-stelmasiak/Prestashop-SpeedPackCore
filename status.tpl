{**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <h3><i class="icon icon-dashboard"></i> {$spc_status.title|escape:'html':'UTF-8'}</h3>
  <table class="table"><tbody>
    {foreach from=$spc_status.rows key=label item=value}
      <tr><td style="width:260px"><strong>{$label|escape:'html':'UTF-8'}</strong></td><td>{$value|escape:'html':'UTF-8'}</td></tr>
    {/foreach}
  </tbody></table>
  {if $spc_status.help}<p class="help-block">{$spc_status.help|escape:'html':'UTF-8'}</p>{/if}
</div>
