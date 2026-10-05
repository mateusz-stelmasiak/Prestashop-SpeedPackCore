{**
 * AsyncCart
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel">
  <h3><i class="icon icon-dashboard"></i> {l s='Status' mod='asynccart'}</h3>
  <table class="table"><tbody>
    {foreach from=$asynccart.rows key=label item=value}
      <tr><td style="width:260px"><strong>{$label|escape:'html':'UTF-8'}</strong></td><td>{$value|escape:'html':'UTF-8'}</td></tr>
    {/foreach}
  </tbody></table>
</div>
