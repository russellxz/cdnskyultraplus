<?php
declare(strict_types=1);

/**
 * Devuelve [HTML, ALT] con logo + botón.
 * NO imprime nada; sólo retorna strings.
 */
function mail_build_brand_html(string $title, string $bodyHtml, string $ctaLabel, string $ctaUrl): array {
  $logo = 'https://cdn.russellxz.click/e37b8238.png';

  // HEREDOC: el cierre HTML; debe ir en columna 0 sin espacios
  $html = <<<HTML
<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0b0b0d;color:#eaf2ff;padding:24px">
  <div style="max-width:560px;margin:0 auto;background:#121828;border:1px solid #2a3344;border-radius:12px;padding:24px">
    <div style="text-align:center;margin-bottom:10px">
      <img src="$logo" alt="SkyUltraPlus" style="width:120px;height:auto"/>
    </div>
    <h2 style="margin:10px 0 6px;text-align:center">$title</h2>
    <div style="line-height:1.55">$bodyHtml</div>
    <p style="text-align:center;margin:18px 0">
      <a href="$ctaUrl" style="display:inline-block;background:linear-gradient(90deg,#0ea5e9,#22d3ee);color:#051425;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:800">$ctaLabel</a>
    </p>
    <p>Si el botón no funciona, copia y pega este enlace en tu navegador:</p>
    <p style="word-break:break-all"><a href="$ctaUrl" style="color:#93c5fd">$ctaUrl</a></p>
    <hr style="border:none;border-top:1px solid #2a3344;margin:16px 0">
    <p style="color:#9fb0c9">SkyUltraPlus CDN • Seguridad y velocidad para tus archivos.</p>
  </div>
</div>
HTML;

  // Texto plano (ALT)
  $alt = strip_tags(
    $title . "\n\n" .
    preg_replace('/<br\s*\/?>/i', "\n", $bodyHtml) .
    "\n\nEnlace: $ctaUrl"
  );

  return [$html, $alt];
}