{**
 * SpeedPack Core
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
{if $spc.twice}
  <div class="alert alert-warning">{l s='These separate modules are still on: %s. SpeedPack Core already does their work, so uninstall them, or the same part runs twice.' sprintf=[$spc.twice] mod='speedpackcore'}</div>
{/if}
<div class="panel">
  <h3><i class="icon-bolt"></i> SpeedPack Core {$spc.version|escape:'html':'UTF-8'}</h3>
  <p>{l s='Four parts, each with its own switch below. They work alone and together: SmartPrefetch fetches the page while the pointer moves, InstantNav swaps it in without a white flash, InstantCart makes add to cart instant and CartSpeed makes the cart page lighter.' mod='speedpackcore'}</p>
  <p>{foreach from=$spc.sections item=section}<a class="btn btn-default" href="#spc-{$section.id|escape:'html':'UTF-8'}">{$section.title|escape:'html':'UTF-8'}</a> {/foreach}</p>
</div>
