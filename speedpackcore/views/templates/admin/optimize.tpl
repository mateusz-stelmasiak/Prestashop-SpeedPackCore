{**
 * SpeedPack Core - Optimize: picture copies, critical CSS and the server's headers.
 *
 * @author    Alhambra
 * @copyright 2026 Mateusz Stelmasiak (Alhambra)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}
<div class="panel spc-opt" id="spc-optimize" data-url="{$spc_opt.url|escape:'html':'UTF-8'}" data-texts="{$spc_opt.texts|escape:'html':'UTF-8'}">
  <h3><i class="icon-picture"></i> {l s='Pictures' mod='speedpackcore'}</h3>
  <p>
    {l s='WebP' mod='speedpackcore'}: {if $spc_opt.webp}<span class="spc-opt-ok">{l s='this server can write it' mod='speedpackcore'}</span>{else}<span class="spc-opt-no">{l s='this server cannot write it (PHP GD with WebP is needed)' mod='speedpackcore'}</span>{/if}
    · AVIF: {if $spc_opt.avif}<span class="spc-opt-ok">{l s='this server can write it' mod='speedpackcore'}</span>{else}<span class="spc-opt-no">{l s='not on this server' mod='speedpackcore'}</span>{/if}
  </p>
  <p class="help-block">{l s='Makes a WebP (and AVIF) copy next to every product, category and brand picture, kept only when it is smaller. The originals stay as they are. It runs in steps while this page is open; you can stop and start again, finished pictures are skipped.' mod='speedpackcore'}</p>
  <p>
    <button type="button" class="btn btn-default" data-spc-convert{if !$spc_opt.webp && !$spc_opt.avif} disabled{/if}><i class="icon-refresh"></i> {l s='Convert pictures' mod='speedpackcore'}</button>
    <span class="spc-opt-progress" data-spc-convert-state></span>
  </p>
  <div class="spc-opt-bar" data-spc-convert-bar hidden><span></span></div>

  <h3><i class="icon-code"></i> {l s='Critical CSS' mod='speedpackcore'}</h3>
  <p class="help-block">{l s='Reads four real pages of the shop in this browser (home page, a category, a product, a CMS page), at computer and phone width, and keeps the CSS their top part needs. That CSS goes inline in the page and the theme stylesheets load without holding up the first paint. Make it again after changing the theme.' mod='speedpackcore'}</p>
  <table class="table spc-opt-crit">
    <thead><tr><th>{l s='Page' mod='speedpackcore'}</th><th>{l s='Size' mod='speedpackcore'}</th><th>{l s='Made' mod='speedpackcore'}</th></tr></thead>
    <tbody>
      {foreach from=$spc_opt.critical item=c}
        <tr data-spc-crit="{$c.page|escape:'html':'UTF-8'}">
          <td>{$c.name|escape:'html':'UTF-8'}</td>
          <td data-spc-crit-kb>{if $c.kb}{$c.kb|escape:'html':'UTF-8'} kB{else}–{/if}</td>
          <td data-spc-crit-at>{if $c.at}{$c.at|escape:'html':'UTF-8'}{else}{l s='not yet' mod='speedpackcore'}{/if}</td>
        </tr>
      {/foreach}
    </tbody>
  </table>
  <form method="post" class="spc-opt-actions">
    <button type="button" class="btn btn-default" data-spc-critical><i class="icon-magic"></i> {l s='Make critical CSS' mod='speedpackcore'}</button>
    <button type="submit" name="submitSpcCriticalClear" class="btn btn-link">{l s='Remove it' mod='speedpackcore'}</button>
    <span class="spc-opt-progress" data-spc-critical-state></span>
  </form>
  <div class="spc-opt-frame" data-spc-frame></div>

  <h3><i class="icon-cogs"></i> {l s='Server headers' mod='speedpackcore'}</h3>
  {if $spc_opt.server == 'nginx'}
    <p>{l s='This shop runs on nginx, which does not read .htaccess. Add these lines to the server block of the shop and reload nginx:' mod='speedpackcore'}</p>
  {else}
    <p>
      {if $spc_opt.headers}<span class="spc-opt-ok">{l s='The block is in .htaccess.' mod='speedpackcore'}</span>{else}{l s='Switch on "Server headers" below to write the block to .htaccess.' mod='speedpackcore'}{/if}
      {l s='On nginx, the same in its own words:' mod='speedpackcore'}
    </p>
  {/if}
  <pre class="spc-opt-pre">{$spc_opt.nginx|escape:'html':'UTF-8'}</pre>
</div>
