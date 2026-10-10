{*
 * APLINE Simple Google Auth module for PrestaShop 9.
 * @author Arkadiusz Pielechowski
 *
 * Google Identity Services renders the button itself from these data-*
 * attributes (Google brand guidelines forbid a custom design). We only
 * pass configuration. data-use_fedcm_for_button is mandatory for
 * Chrome 2024+ where FedCM is required for the GIS button to keep working.
 * data-locale makes Google draw the button text in the shop language.
 * Google reads one g_id_onload per page, so with several buttons on a
 * page (checkout tabs) only the first one carries it.
 *}
<div class="apline-simple-google-auth asga-wrapper" data-asga-context="{$asga_context|escape:'html':'UTF-8'}">
  {if $asga_render_onload}
  <div id="g_id_onload"
       data-client_id="{$asga_client_id|escape:'html':'UTF-8'}"
       data-login_uri="{$asga_callback_url|escape:'html':'UTF-8'}"
       data-auto_prompt="{if $asga_auto_prompt}true{else}false{/if}"
       data-use_fedcm_for_button="true"
       data-context="{$asga_gis_context|escape:'html':'UTF-8'}">
  </div>
  {/if}
  <div class="g_id_signin"
       data-type="standard"
       data-theme="{$asga_theme|escape:'html':'UTF-8'}"
       data-size="{$asga_size|escape:'html':'UTF-8'}"
       data-text="{$asga_text|escape:'html':'UTF-8'}"
       data-shape="{$asga_shape|escape:'html':'UTF-8'}"
       {if $asga_locale}data-locale="{$asga_locale|escape:'html':'UTF-8'}"{/if}
       {if $asga_state}data-state="{$asga_state|escape:'html':'UTF-8'}"{/if}
       data-logo_alignment="left">
  </div>
  {if $asga_consent}
  <p class="asga-consent">{$asga_consent nofilter}</p>
  {/if}
</div>
