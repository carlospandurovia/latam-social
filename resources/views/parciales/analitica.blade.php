{{-- La medición, sin proveedor atado (L-5, §21).

     ### Qué hace

     Emite el fragmento del proveedor **configurado en «Sitio público»** y ata
     los `data-evento` que la `L-3` y la `L-4` dejaron puestos por toda la
     portada. Cambiar de Google a Meta es un desplegable, no un despliegue.

     ### Y qué NO hace

     **No se emite fuera de producción**, y esto no es una precaución teórica: es
     literalmente el agujero que `9.22b` cerró para el correo. Se restaura un
     volcado de producción en el servidor de pruebas —cosa que se hace todas las
     semanas— y ese volcado trae dentro el identificador bueno de la propiedad,
     así que cada clic de una prueba se cuenta como una visita real. No rompe
     nada, y por eso nadie lo nota: los números simplemente dejan de significar
     algo. `Instalacion::esProduccion()` decide, igual que en `9.22a`.

     ### Por qué el identificador se puede escribir aquí sin miedo

     Porque entra dentro de un `<script>`, y ahí una comilla no es una errata: es
     una inyección en todas las páginas públicas. Lo comprueban **las dos
     puertas** —el formulario del panel y `ck_ss_medidor_id` en la base, con
     `COLLATE utf8mb4_bin`— así que lo que llega aquí sólo puede tener letras,
     números, punto y guion. Aun así va por `e()`: la defensa que se salta «esta
     vez» es la que falta el día que alguien cambie la regla de arriba.

     ### El puente de eventos

     Nueve líneas, sin librería. Lee `data-evento` del elemento pulsado y se lo
     pasa a lo que haya cargado. Si no hay nada cargado no hace nada y no falla:
     un `data-evento` en un botón nunca puede impedir que el botón funcione. --}}
@php($medidor = $medicion['proveedor'])
@php($medidorId = $medicion['id'])

@if ($medicion['emite'])
  @switch($medidor)
    @case('ga4')
      <script async src="https://www.googletagmanager.com/gtag/js?id={{ e($medidorId) }}"></script>
      <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ e($medidorId) }}');
      </script>
      @break

    @case('gtm')
      <script>
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});
        var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';
        j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','{{ e($medidorId) }}');
      </script>
      @break

    @case('meta')
      <script>
        !function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
        n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,
        'script','https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '{{ e($medidorId) }}');
        fbq('track', 'PageView');
      </script>
      @break

    @case('plausible')
      <script defer data-domain="{{ e($medidorId) }}"
              src="https://plausible.io/js/script.js"></script>
      @break
  @endswitch

  <script>
    document.addEventListener('click', function (e) {
      var el = e.target.closest('[data-evento]');
      if (!el) { return; }
      var nombre = el.getAttribute('data-evento');
      try {
        if (typeof gtag === 'function') { gtag('event', nombre); }
        if (typeof fbq === 'function') { fbq('trackCustom', nombre); }
        if (window.plausible) { window.plausible(nombre); }
        if (window.dataLayer && typeof gtag !== 'function') { window.dataLayer.push({ event: nombre }); }
      } catch (err) { /* medir nunca puede romper un boton */ }
    });
  </script>
@endif
