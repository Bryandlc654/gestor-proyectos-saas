<?php

namespace App\Support;

/**
 * Plantilla HTML de los correos de Vértice.
 *
 * Correo transaccional con tablas e estilos en línea (compatible con Gmail, Outlook y Apple Mail).
 * Cada correo incluye siempre una versión en texto plano como alternativa.
 */
final class EmailTemplate
{
    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    /**
     * @param array{
     *   title: string,
     *   paragraphs?: array<int, string>,
     *   preheader?: string,
     *   eyebrow?: string,
     *   cta_label?: ?string,
     *   cta_url?: ?string,
     *   note?: ?string,
     *   app_name?: string,
     *   app_url?: string,
     *   logo_url?: ?string,
     *   footer?: ?string
     * } $data
     */
    public static function render(array $data): string
    {
        $title = (string)($data['title'] ?? '');
        $eyebrow = trim((string)($data['eyebrow'] ?? ''));
        $preheader = trim((string)($data['preheader'] ?? ''));
        $appName = (string)($data['app_name'] ?? 'Vértice');
        $appUrl = rtrim((string)($data['app_url'] ?? ''), '/');
        $ctaLabel = trim((string)($data['cta_label'] ?? ''));
        $ctaUrl = trim((string)($data['cta_url'] ?? ''));
        $note = trim((string)($data['note'] ?? ''));
        $footer = trim((string)($data['footer'] ?? ''));
        $logoUrl = trim((string)($data['logo_url'] ?? ($appUrl !== '' ? $appUrl . '/assets/favicon.png' : '')));

        $paragraphs = [];
        foreach ((array)($data['paragraphs'] ?? []) as $paragraph) {
            $paragraph = trim((string)$paragraph);
            if ($paragraph !== '') {
                $paragraphs[] = '<p style="margin:0 0 14px;font-family:' . self::FONT . ';font-size:15px;line-height:1.65;color:#475569;">'
                    . nl2br(self::e($paragraph)) . '</p>';
            }
        }

        $eyebrowHtml = $eyebrow !== ''
            ? '<p style="margin:0 0 8px;font-family:' . self::FONT . ';font-size:11px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#0e66ff;">' . self::e($eyebrow) . '</p>'
            : '';

        $ctaHtml = '';
        if ($ctaLabel !== '' && $ctaUrl !== '') {
            $ctaHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">'
                . '<tr><td align="center" bgcolor="#0e66ff" style="border-radius:10px;">'
                . '<a href="' . self::e($ctaUrl) . '" target="_blank" rel="noopener" '
                . 'style="display:inline-block;padding:13px 28px;font-family:' . self::FONT . ';font-size:14px;font-weight:600;line-height:1.2;color:#ffffff;text-decoration:none;border-radius:10px;">'
                . self::e($ctaLabel) . ' &rarr;</a>'
                . '</td></tr></table>'
                . '<p style="margin:14px 0 0;font-family:' . self::FONT . ';font-size:12px;line-height:1.6;color:#94a3b8;">'
                . 'Si el botón no funciona, copia este enlace en tu navegador:<br>'
                . '<a href="' . self::e($ctaUrl) . '" target="_blank" rel="noopener" style="color:#0e66ff;text-decoration:underline;word-break:break-all;">' . self::e($ctaUrl) . '</a>'
                . '</p>';
        }

        $noteHtml = $note !== ''
            ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:24px;background:#f0f5fb;border:1px solid #dde7f4;border-radius:10px;">'
            . '<tr><td style="padding:14px 16px;font-family:' . self::FONT . ';font-size:13px;line-height:1.6;color:#2a5588;">'
            . nl2br(self::e($note)) . '</td></tr></table>'
            : '';

        $logoHtml = $logoUrl !== ''
            ? '<img src="' . self::e($logoUrl) . '" width="30" height="30" alt="' . self::e($appName) . '" style="display:inline-block;vertical-align:middle;border:0;border-radius:8px;">'
            : '<span style="display:inline-block;width:30px;height:30px;line-height:30px;text-align:center;background:#0e66ff;border-radius:8px;font-family:' . self::FONT . ';font-size:15px;font-weight:700;color:#ffffff;">V</span>';

        $footerNote = $footer !== ''
            ? self::e($footer)
            : 'Recibiste este correo porque tienes una cuenta en ' . self::e($appName) . '.';
        $footerLink = $appUrl !== ''
            ? '<a href="' . self::e($appUrl) . '" target="_blank" rel="noopener" style="color:#64748b;text-decoration:none;font-weight:600;">' . self::e((string)parse_url($appUrl, PHP_URL_HOST)) . '</a>'
            : '';

        $preheaderHtml = $preheader !== ''
            ? '<div style="display:none;max-height:0;height:0;overflow:hidden;opacity:0;color:transparent;">' . self::e($preheader) . '</div>'
            : '';

        return '<!DOCTYPE html>'
            . '<html lang="es" xmlns="http://www.w3.org/1999/xhtml">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="x-apple-disable-message-reformatting">'
            . '<meta name="color-scheme" content="light">'
            . '<title>' . self::e($title) . '</title>'
            . '<!--[if mso]><style>body,table,td,a{font-family:Arial,Helvetica,sans-serif!important}</style><![endif]-->'
            . '<style>'
            . '@media (max-width:600px){'
            . '.email-container{width:100%!important;border-radius:0!important}'
            . '.email-padding{padding-left:22px!important;padding-right:22px!important}'
            . '.email-title{font-size:20px!important}'
            . '}'
            . '</style>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:#f1f5f9;-webkit-text-size-adjust:100%;">'
            . $preheaderHtml
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">'
            . '<tr><td align="center" style="padding:32px 12px;">'
            . '<table role="presentation" class="email-container" width="560" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:560px;max-width:560px;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;">'
            // Cabecera
            . '<tr><td style="background:#072a5b;padding:20px 32px;" class="email-padding">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td style="vertical-align:middle;white-space:nowrap;">' . $logoHtml
            . '<span style="display:inline-block;vertical-align:middle;margin-left:10px;font-family:' . self::FONT . ';font-size:17px;font-weight:700;letter-spacing:-.2px;color:#ffffff;">' . self::e($appName) . '</span>'
            . '</td>'
            . '<td align="right" style="vertical-align:middle;font-family:' . self::FONT . ';font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:#8fbcff;">Gestión que conecta</td>'
            . '</tr></table>'
            . '</td></tr>'
            . '<tr><td style="height:4px;background:#15bdf4;font-size:0;line-height:0;">&nbsp;</td></tr>'
            // Contenido
            . '<tr><td class="email-padding" style="padding:32px;">'
            . $eyebrowHtml
            . '<h1 class="email-title" style="margin:0 0 14px;font-family:' . self::FONT . ';font-size:22px;font-weight:700;line-height:1.3;letter-spacing:-.3px;color:#072a5b;">' . self::e($title) . '</h1>'
            . implode('', $paragraphs)
            . $ctaHtml
            . $noteHtml
            . '</td></tr>'
            // Pie
            . '<tr><td class="email-padding" style="border-top:1px solid #eef2f7;background:#fbfdff;padding:20px 32px;">'
            . '<p style="margin:0;font-family:' . self::FONT . ';font-size:12px;line-height:1.7;color:#94a3b8;">'
            . $footerNote
            . ($footerLink !== '' ? '<br>' . $footerLink . ' &middot; &copy; ' . date('Y') . ' ' . self::e($appName) : '')
            . '</p>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr>'
            . '</table>'
            . '</body></html>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
