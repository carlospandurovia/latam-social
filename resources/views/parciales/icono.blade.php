{{-- 9.17i: los iconos de las tarjetas de integración, en un solo sitio.

     Van EN LÍNEA y no como una fuente de iconos ni como un `<img>`: son seis
     dibujos: traer un fichero entero de iconos --o pedirle uno por HTTP a un
     tercero-- para pintar seis trazos es carga y una dependencia externa en una
     pantalla del admin. Y en línea heredan el color del texto, que es lo que
     hace que el estado se lea de un vistazo.

     `aria-hidden` porque el icono NO informa: al lado siempre está el título en
     texto. Un lector de pantalla que lo lea sólo repetiría lo que ya dijo. --}}
@php
    $trazos = [
        // Un sobre: el correo saliente.
        'correo' => 'M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75'
            .'m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916'
            .'l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75',
        // Un documento con líneas: el comprobante.
        'factura' => 'M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5'
            .'a3.375 3.375 0 0 0-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25'
            .'c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z',
        // Un escudo con un visto: el certificado que firma.
        'certificado' => 'M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6'
            .'A11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622'
            .'0-1.31-.21-2.571-.598-3.751A11.959 11.959 0 0 1 12 2.714Z',
        // Una lista numerada: las series y sus folios.
        'folios' => 'M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Z'
            .'m.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Z'
            .'m.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Z'
            .'m.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z',
        // Dos flechas en círculo: la conversión de moneda.
        'cambio' => 'M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5',
        // Un enchufe: la conexión con el proveedor.
        'enchufe' => 'M14.121 7.629A3 3 0 0 0 9.017 9.43c-.023.212-.002.425.028.636l.506 3.541'
            .'a4.5 4.5 0 0 1-.43 2.65L9 16.5l1.539-.513a2.25 2.25 0 0 1 1.422 0l.655.218'
            .'a2.25 2.25 0 0 0 1.718-.122L15 15.75M8.25 12H12m9 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
    ];
@endphp

@if (isset($trazos[$nombre]))
  <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
       stroke-width="1.5" stroke="currentColor" aria-hidden="true" focusable="false"
       class="{{ $clase ?? 'h-5 w-5' }}">
    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $trazos[$nombre] }}" />
  </svg>
@endif
