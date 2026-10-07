{**
 * SpeedPack Core - the footer credit (opt-in, see "Share the speed" on the settings page).
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<p class="spc-credit" id="spc-credit" style="clear:both;width:100%;margin:8px 0;padding:10px 15px;font-size:12px;opacity:.8;text-align:center">
  {l s='Fast pages:' mod='speedpackcore'} <a href="{$spc_credit.url|escape:'html':'UTF-8'}" rel="nofollow noopener" target="_blank">SpeedPack Core</a>
  {l s='by' mod='speedpackcore'} <a href="{$spc_credit.author_url|escape:'html':'UTF-8'}" rel="nofollow noopener" target="_blank">{$spc_credit.author|escape:'html':'UTF-8'}</a>{if $spc_credit.clicks} · {l s='%s× faster from click to page on this shop' sprintf=[$spc_credit.clicks] mod='speedpackcore'}{elseif $spc_credit.pages} · {l s='pages %s× faster on this shop' sprintf=[$spc_credit.pages] mod='speedpackcore'}{/if}
</p>
{* displayFooter sits among the footer's columns: the line moves to the very end of the footer *}
<script>(function () { var c = document.getElementById('spc-credit'), f = c && (document.getElementById('footer') || c.closest('footer')); if (f && f !== c.parentNode) { f.appendChild(c); } }());</script>
