<?php
declare(strict_types=1);

/**
 * Leitor de metadados de ofertas. Nunca importa URLs internas nem executa scripts.
 * O link de destino da oferta continua sendo definido pelo editor.
 */
final class OfferMetadataImporter
{
    private const MAX_BYTES = 1048576;
    private const MAX_REDIRECTS = 3;

    public static function validatePublicUrl(string $url): string
    {
        $url = trim($url);
        if (strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Informe uma URL HTTPS válida.');
        }
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) && (int)$parts['port'] !== 443
            || filter_var($host, FILTER_VALIDATE_IP)
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !str_contains($host, '.')
            || preg_match('/\.(local|localhost|internal|test|invalid|example|onion|lan)$/i', $host)
        ) {
            throw new InvalidArgumentException('Somente domínios públicos HTTPS são permitidos.');
        }
        return $url;
    }

    /** @return array{url:string,title:string,description:string,price:string,currency:string,image:string,manual_required:bool} */
    public function import(string $url): array
    {
        if (!extension_loaded('curl') || !extension_loaded('dom')) {
            throw new RuntimeException('O servidor precisa das extensões PHP curl e dom.');
        }
        $current = self::validatePublicUrl($url);
        for ($redirect = 0; $redirect <= self::MAX_REDIRECTS; $redirect++) {
            $parts = parse_url($current);
            $host = strtolower($parts['host']);
            $records = dns_get_record($host, DNS_A | DNS_AAAA);
            $ips = [];
            foreach ($records ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ($ip === null || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new RuntimeException('O domínio possui endereço de rede não público.');
                }
                $ips[] = $ip;
            }
            if (!$ips) {
                throw new RuntimeException('Não foi possível resolver um IP público para o domínio.');
            }

            $body = '';
            $ch = curl_init($current);
            $ip = $ips[0];
            $resolved = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_PROXY => '',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_USERAGENT => 'CuponsMetadataBot/1.0',
                CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
                CURLOPT_RESOLVE => [$host . ':443:' . $resolved],
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                        return 0;
                    }
                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);
            if (defined('CURLOPT_PROTOCOLS_STR')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'https');
            } else {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
            $redirectUrl = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $error = curl_error($ch);
            curl_close($ch);

            if ($status >= 300 && $status < 400 && $redirectUrl !== '') {
                if ($redirect === self::MAX_REDIRECTS) {
                    throw new RuntimeException('Limite de redirecionamentos excedido.');
                }
                $current = self::validatePublicUrl($redirectUrl);
                continue;
            }
            if ($ok === false || $status !== 200 || !preg_match('~^(text/html|application/xhtml\+xml)~', $contentType)) {
                throw new RuntimeException('Não foi possível importar a página. Preencha os dados manualmente.');
            }
            return $this->parseHtml($body, $current);
        }
        throw new RuntimeException('Não foi possível concluir a importação.');
    }

    /** @return array{url:string,title:string,description:string,price:string,currency:string,image:string,manual_required:bool} */
    public function parseHtml(string $html, string $url): array
    {
        $url = self::validatePublicUrl($url);
        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($doc);
        $meta = static function (string $key) use ($xpath): string {
            $nodes = $xpath->query('//meta[@property="' . $key . '" or @name="' . $key . '"]/@content');
            return trim((string)($nodes?->item(0)?->nodeValue ?? ''));
        };
        $title = $meta('og:title') ?: $meta('twitter:title');
        if ($title === '') {
            $title = trim((string)($xpath->query('//title')?->item(0)?->textContent ?? ''));
        }
        $description = $meta('og:description') ?: $meta('description');
        $price = $meta('product:price:amount') ?: $meta('og:price:amount');
        $currency = $meta('product:price:currency') ?: $meta('og:price:currency') ?: 'BRL';
        $image = $meta('og:image') ?: $meta('twitter:image');

        foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $script) {
            $json = json_decode($script->textContent, true);
            $candidates = is_array($json) ? ($json['@graph'] ?? (array_is_list($json) ? $json : [$json])) : [];
            foreach ($candidates as $item) {
                if (!is_array($item) || !str_contains(strtolower(implode(',', (array)($item['@type'] ?? ''))), 'product')) {
                    continue;
                }
                $title = $title ?: trim((string)($item['name'] ?? ''));
                $description = $description ?: trim(strip_tags((string)($item['description'] ?? '')));
                $offer = $item['offers'] ?? [];
                if (array_is_list($offer)) {
                    $offer = $offer[0] ?? [];
                }
                if (is_array($offer)) {
                    $price = $price ?: (string)($offer['price'] ?? $offer['lowPrice'] ?? '');
                    $currency = (string)($offer['priceCurrency'] ?? $currency);
                }
                $images = $item['image'] ?? '';
                $image = $image ?: (is_array($images) ? (string)($images[0] ?? '') : (string)$images);
                break;
            }
        }
        if ($image !== '' && !str_starts_with($image, 'https://')) {
            $image = '';
        }
        $price = preg_match('/^\d+(?:[.,]\d{1,2})?$/', trim($price)) ? str_replace(',', '.', trim($price)) : '';
        return [
            'url' => $url,
            'title' => mb_substr(strip_tags($title), 0, 255),
            'description' => mb_substr(strip_tags($description), 0, 1500),
            'price' => $price,
            'currency' => mb_substr($currency, 0, 3),
            'image' => $image,
            'manual_required' => $title === '' || $price === '',
        ];
    }
}
