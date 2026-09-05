#!/usr/bin/env python3
"""Ninguna frase de la calle esta escrita a mano en una plantilla (L-6, §26).

### Por que existe

`R-2` de la auditoria: el texto de marketing ya vivia en la base, pero **cada
etiqueta de formulario y cada enlace del pie estaban escritos en el
`.blade.php`**, asi que traducir el sitio obligaba a tocar plantillas. La `L-6`
los saco a `lang/es/publico.php`.

Sacarlos una vez es facil. Que sigan fuera dentro de seis iteraciones no lo es:
la proxima persona que anada un campo al formulario escribira «Correo» donde le
toque, y nadie lo notara --el sitio sigue en espanol y se ve igual--. Este
verificador es lo unico que convierte «lo sacamos» en «esta fuera».

### Que comprueba

En las vistas PUBLICAS --las que ve quien todavia no es cliente-- no puede haber
texto suelto. Se mira lo que queda entre `>` y `<` despues de quitar los
comentarios de Blade, y las etiquetas que se leen en voz alta (`alt`,
`aria-label`, `placeholder`, `title`).

El panel se queda fuera a proposito: lo ve quien trabaja aqui, hablamos su
idioma, y meterlo hoy convertiria esto en un trabajo de mil lineas que nadie
terminaria. Cuando llegue, la lista de carpetas es una constante de aqui arriba.

### Y las excepciones

Van en `tools/pruebas/ROTULOS-CRUDOS`, una por linea y **con su motivo**. Mismo
criterio que `RUTAS-ABIERTAS` y `SALIDAS-AL-MUNDO`: no se prohibe la excepcion,
se exige que este escrita. Un nombre de marca --«WhatsApp»-- no se traduce, y
decirlo una vez vale mas que discutirlo cada vez.

Uso:  python3 tools/verificar-rotulos.py
"""
from __future__ import annotations

import pathlib
import re
import sys

RAIZ = pathlib.Path(__file__).resolve().parent.parent
LISTA = RAIZ / 'tools' / 'pruebas' / 'ROTULOS-CRUDOS'

VISTAS = next((p for p in (RAIZ / 'stage' / 'resources' / 'views',
                           RAIZ / 'resources' / 'views') if p.is_dir()), None)

# Lo que ve quien todavia no es cliente. El resto del panel, otro dia.
PUBLICAS = ('layouts/publico.blade.php', 'publico/', 'parciales/icono-red.blade.php',
            'parciales/icono.blade.php', 'parciales/heroe-voces.blade.php',
            'parciales/marca-logo.blade.php')

COMENTARIO = re.compile(r'\{\{--.*?--\}\}', re.S)
# `<script>` y `<style>` no llevan rotulos: llevan codigo.
CODIGO = re.compile(r'<(script|style)\b[^>]*>.*?</\1>', re.I | re.S)
TEXTO = re.compile(r'>([^<>]+)<')
ATRIBUTO = re.compile(r'\b(?:alt|aria-label|placeholder|title)\s*=\s*"([^"]*)"')

# Una palabra de tres letras o mas con letras latinas. Los simbolos --«+», «→»,
# «·»-- no son rotulos y no hace falta traducirlos.
PALABRA = re.compile(r'[A-Za-zÁÉÍÓÚÜÑáéíóúüñ]{3,}')

VERDE, ROJO, AMBAR, GRIS, FIN = '\033[32m', '\033[31m', '\033[33m', '\033[90m', '\033[0m'


def declaradas() -> dict[str, str]:
    fuera: dict[str, str] = {}

    if not LISTA.is_file():
        return fuera

    for linea in LISTA.read_text(encoding='utf-8').splitlines():
        if not linea.strip() or linea.lstrip().startswith('#'):
            continue
        texto, _, motivo = linea.partition('#')
        fuera[texto.strip()] = motivo.strip()

    return fuera


def sin_directivas(fuente: str) -> str:
    """Quita `@if (...)`, `@include([...])` y compania, con sus parentesis.

    Con los parentesis, y ahi estaba el error de la primera version: se quitaba
    solo `@if` y quedaba `($s->title)` suelto, cuyo `->` el buscador de texto
    leia como el cierre de una etiqueta. Resultado: media portada denunciada por
    llevar «title }}» escrito a mano. Un verificador que grita por nada ensena a
    ignorarlo, que es peor que no tenerlo.
    """
    salida: list[str] = []
    i = 0

    while i < len(fuente):
        if fuente[i] == '@' and re.match(r'@\w', fuente[i:]):
            j = i + 1
            while j < len(fuente) and (fuente[j].isalnum() or fuente[j] == '_'):
                j += 1

            k = j
            while k < len(fuente) and fuente[k] in ' \t':
                k += 1

            # Si lleva parentesis, se salta ENTERO contando los niveles: un
            # `.*?` se paraba en el primer `)` y dejaba basura detras.
            if k < len(fuente) and fuente[k] == '(':
                nivel = 0
                while k < len(fuente):
                    if fuente[k] == '(':
                        nivel += 1
                    elif fuente[k] == ')':
                        nivel -= 1
                        if nivel == 0:
                            k += 1
                            break
                    k += 1
                i = k
            else:
                i = j

            salida.append(' ')
            continue

        salida.append(fuente[i])
        i += 1

    return ''.join(salida)


def limpiar(fuente: str) -> str:
    fuente = CODIGO.sub('', COMENTARIO.sub('', fuente))
    # Lo que Blade resuelve no es un rotulo: sale de `__()` o de la base.
    fuente = re.sub(r'\{!!.*?!!\}|\{\{.*?\}\}', ' ', fuente, flags=re.S)

    return sin_directivas(fuente)


def sueltos(fuente: str) -> list[str]:
    """Lo que se lee en la pantalla y no viene de `__()` ni de la base."""
    encontrados: list[str] = []

    for trozo in TEXTO.findall(fuente) + ATRIBUTO.findall(fuente):
        limpio = ' '.join(trozo.split())

        if limpio and PALABRA.search(limpio):
            encontrados.append(limpio)

    return encontrados


def main() -> int:
    if VISTAS is None:
        print(f'{ROJO}  No encuentro las vistas.{FIN}')
        return 2

    lista = declaradas()
    hallazgos: dict[str, list[str]] = {}
    usados: set[str] = set()
    total = 0

    for vista in sorted(VISTAS.rglob('*.blade.php')):
        rel = vista.relative_to(VISTAS).as_posix()

        if not rel.startswith(PUBLICAS):
            continue

        total += 1
        crudos = []

        for texto in sueltos(limpiar(vista.read_text(encoding='utf-8'))):
            if texto in lista:
                usados.add(texto)
                continue
            if texto not in crudos:
                crudos.append(texto)

        if crudos:
            hallazgos[rel] = crudos

    print()
    print('===== rotulos de la calle: ninguno escrito a mano =====')
    print()
    print(f'  Vistas publicas revisadas: {total}    excepciones escritas: {len(lista)}')
    print()

    if hallazgos:
        print(f'{ROJO}  Texto escrito en la plantilla, sin pasar por `__()`:{FIN}')
        for rel, textos in hallazgos.items():
            print(f'      {rel}')
            for t in textos:
                print(f'{GRIS}          «{t}»{FIN}')
        print()
        print(f'{GRIS}      O sale a `lang/es/publico.php`, o se escribe en ROTULOS-CRUDOS'
              f' con su motivo.{FIN}')
        print()
        return 1

    viejas = [t for t in lista if t not in usados]
    if viejas:
        print(f'{ROJO}  Escritos en ROTULOS-CRUDOS pero ya no aparecen en ninguna vista:{FIN}')
        for t in sorted(viejas):
            print(f'      «{t}»')
        print()
        return 1

    for texto in sorted(lista):
        print(f'{AMBAR}  Sin traducir, a proposito:{FIN} «{texto}»')
        print(f'{GRIS}      {lista[texto]}{FIN}')
    if lista:
        print()

    print(f'{VERDE}  Ninguna frase de la calle esta escrita en una plantilla.{FIN}')
    print()
    return 0


if __name__ == '__main__':
    sys.exit(main())
