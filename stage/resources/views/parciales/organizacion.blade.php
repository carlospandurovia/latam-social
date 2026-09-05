{{-- Quién es esta empresa, en el formato que leen los buscadores (L-6, §20).

     ### Para qué sirve de verdad

     No es «SEO» en abstracto. Un `Organization` con nombre, logotipo, dirección
     y perfiles sociales es lo que hace que, al buscar la marca, salga algo más
     que un enlace azul: el logotipo, el sitio, las redes. Para una plataforma
     cuyo tráfico va a venir de redes sociales, esa tarjeta es la diferencia
     entre parecer una empresa y parecer una página.

     ### Y por qué no dice ni una palabra escrita a mano

     Todo sale de «Sitio público» y de la sociedad operadora, igual que la franja
     de confianza de la `L-4`. Escribir aquí la razón social sería el mismo
     defecto en un sitio donde además nadie lo vería: un dato mal en un JSON-LD
     no se nota mirando la página, se nota seis meses después en un buscador.

     `sameAs` se rellena con las redes publicadas. Vacío no se pinta: declararle
     a un buscador un array vacío es afirmar «no tenemos redes», que no es lo
     mismo que «todavía no están configuradas».

     Y lo que **todavía es el valor de fábrica** no llega hasta aquí: lo filtra
     el compositor. Salió mirando este JSON —el domicilio decía «Por completar,
     Perú»— y en un documento legal eso al menos le grita al operador que lo
     complete, pero aquí se lo estábamos **declarando a un buscador**, que lo
     guarda y lo enseña. Mejor no decir la dirección que decir una que no es.

     Se arma en PHP y se vuelca con `json_encode`, no a mano: un JSON escrito
     con `{{ }}` dentro de una plantilla es una comilla suelta esperando a
     romperlo, y un JSON-LD roto no da ningún error — simplemente deja de
     leerse. --}}
@php
    $organizacion = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Organization',
        'name' => $marca['nombre'],
        'url' => url('/'),
        'logo' => asset('img/brand/logo-horizontal.svg'),
        'image' => asset('img/brand/og-image.png'),
        'description' => $marca['lema'] ?: null,
        'email' => $sitio['correo'],
        'telephone' => $sitio['telefono'],
        'legalName' => $empresa['razon_social'] ?? null,
        'taxID' => $empresa['numero_documento'] ?? null,
        'address' => ($empresa['domicilio'] ?? null) === null ? null : [
            '@type' => 'PostalAddress',
            'streetAddress' => $empresa['domicilio'],
            'addressLocality' => $empresa['ciudad'] ?? null,
            'addressCountry' => $empresa['pais'] ?? null,
        ],
        'sameAs' => $redesDelPie->pluck('url')->values()->all() ?: null,
    ], static fn ($v): bool => $v !== null && $v !== '' && $v !== []);

@endphp
<script type="application/ld+json">{!! json_encode($organizacion, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
