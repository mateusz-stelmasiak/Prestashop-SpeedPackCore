{**
 * SpeedPack Core - the footer credit (opt-in, see "Share the speed" on the settings page).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<p class="spc-credit" style="margin:6px 0;font-size:11px;opacity:.7;text-align:center">
  {l s='Fast pages:' mod='speedpackcore'} <a href="{$spc_credit.url|escape:'html':'UTF-8'}" rel="nofollow noopener" target="_blank">SpeedPack Core</a>{if $spc_credit.clicks} · {l s='%s× faster from click to page on this shop' sprintf=[$spc_credit.clicks] mod='speedpackcore'}{/if}
</p>
