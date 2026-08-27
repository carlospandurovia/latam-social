#!/usr/bin/env python3
"""
Extrae las restricciones CHECK de los esquemas de referencia y produce:

  1. Una copia del esquema SIN ninguna clausula CHECK  -> simula Percona 5.7,
     que las analiza y las ignora en silencio.
  2. Los TRIGGER equivalentes, generados por la clase real
     App\\Shared\\Database\\Restriccion (no por una reimplementacion: si el
     generador de produccion tiene un fallo, esta prueba tiene que verlo).

Sirve para responder la unica pregunta que importa de DEC-042: la base que se
va a desplegar en produccion, .se comporta igual que la de desarrollo?
"""
import json, re, subprocess, sys
from pathlib import Path

RAIZ = Path(__file__).resolve().parent.parent
SQL = RAIZ / 'tools' / 'sql'
SALIDA = SQL / 'generado'
# \b obligatorio: sin el, `PRIMARY` casa con la columna `primary_color` y
# `KEY` con `key_hash`. Una columna descartada aqui = una restriccion que
# existe como CHECK en desarrollo y NO se genera como trigger en produccion.
# Es DEC-042 reapareciendo por la puerta de atras, y en silencio.
NO_COLUMNA = re.compile(r'^\s*(?:(?:CONSTRAINT|KEY|UNIQUE|PRIMARY|INDEX|FULLTEXT)\b|\))', re.I)

def checks_en_alter(texto):
    """
    Los CHECK declarados por ALTER TABLE, no dentro del CREATE TABLE.

    Se paso por alto en la primera version: `users` recibia sus dos CHECK por
    ALTER y el generador no los veia. Ni se quitaban de la copia sin-CHECK ni se
    generaba su trigger. En Percona 5.7 eso son dos reglas que simplemente no
    existen, sin ningun aviso.

    Devuelve [(tabla, nombre, expresion, ini, fin)] con ini/fin del tramo
    `ADD CONSTRAINT ... CHECK (...)` para poder borrarlo.
    """
    salida = []
    for a in re.finditer(r'ALTER\s+TABLE\s+`?(\w+)`?', texto, re.I):
        tabla = a.group(1)
        fin_sent = texto.find(';', a.end())
        if fin_sent == -1: fin_sent = len(texto)
        tramo = texto[a.end():fin_sent]
        for m in re.finditer(r'ADD\s+CONSTRAINT\s+`?(\w+)`?\s+CHECK\s*\(', tramo, re.I):
            i, prof, lit = m.end(), 1, False
            while i < len(tramo) and prof > 0:
                c = tramo[i]
                if lit:
                    if c == "'": lit = (i+1 < len(tramo) and tramo[i+1] == "'")
                elif c == "'": lit = True
                elif c == '(': prof += 1
                elif c == ')': prof -= 1
                i += 1
            expr = re.sub(r'\s+', ' ', re.sub(r'\s*--[^\n]*', '', tramo[m.end():i-1])).strip()
            salida.append((tabla, m.group(1), expr, a.end()+m.start(), a.end()+i))
    return salida


def bloques_create_table(texto):
    """Devuelve [(tabla, cuerpo_completo, inicio, fin)] equilibrando parentesis."""
    salida = []
    for m in re.finditer(r'CREATE TABLE\s+`?(\w+)`?\s*\(', texto, re.I):
        i, prof, en_lit = m.end(), 1, False
        while i < len(texto) and prof > 0:
            c = texto[i]
            if en_lit:
                if c == "'": en_lit = (i+1 < len(texto) and texto[i+1] == "'")
            elif c == "'": en_lit = True
            elif c == '(': prof += 1
            elif c == ')': prof -= 1
            i += 1
        salida.append((m.group(1), texto[m.end():i-1], m.start(), i))
    return salida

# `VARBINARY` faltaba, y el hueco lo destapo `password_links.used_ip` en 5.9:
# `^BINARY` no casa con `VARBINARY(16)`, asi que la columna no entraba en la
# lista y `ck_pl_used` se genero como un trigger que decia `used_ip` a secas en
# vez de `NEW.``used_ip```. MySQL lo rechaza con «Undeclared variable», que es
# ruidoso y por eso se vio; el problema es que el mismo hueco existia desde la
# Fase 2 en `audit_logs.ip_address` y `creator_identity_verifications.ip_address`
# --dos columnas VARBINARY que nunca aparecieron en un CHECK--. El dia que
# apareciera una, la regla no habria existido en Percona.
TIPO = re.compile(
    r'^(?:BIG|SMALL|TINY|MEDIUM)?INT|^(?:VAR)?CHAR|^(?:TINY|MEDIUM|LONG)?(?:TEXT|BLOB)|'
    r'^DECIMAL|^NUMERIC|^FLOAT|^DOUBLE|^BOOL|^DATE|^TIME|^YEAR|^ENUM|^SET|^JSON|'
    r'^(?:VAR)?BINARY|^BIT',
    re.I)

def checks_de(cuerpo):
    """[(nombre, expresion)] equilibrando parentesis y respetando literales."""
    salida = []
    for m in re.finditer(r'CONSTRAINT\s+`?(\w+)`?\s+CHECK\s*\(', cuerpo, re.I):
        i, prof, en_lit = m.end(), 1, False
        while i < len(cuerpo) and prof > 0:
            c = cuerpo[i]
            if en_lit:
                if c == "'": en_lit = (i+1 < len(cuerpo) and cuerpo[i+1] == "'")
            elif c == "'": en_lit = True
            elif c == '(': prof += 1
            elif c == ')': prof -= 1
            i += 1
        expr = cuerpo[m.end():i-1].strip()
        expr = re.sub(r'\s*--[^\n]*', '', expr)          # comentarios de linea
        expr = re.sub(r'\s+', ' ', expr).strip()
        salida.append((m.group(1), expr, m.start(), i))
    return salida


def columnas_de(cuerpo):
    """
    Solo definiciones de columna reales.

    Dos guardas, porque una sola no basta:

     1. Se borran antes los CHECK. Sin esto, la linea de continuacion de un CHECK
        multilinea (`  OR (approved_by_user_id IS NOT NULL ...`) parece una
        definicion de columna llamada `OR`, y `OR` acaba reescrito como
        NEW.`OR` en el trigger. El disparador no se crea, nadie lo mira, y la
        restriccion simplemente no existe en produccion.

     2. El segundo token tiene que ser un tipo SQL. Es la red por si la primera
        guarda se queda corta con alguna sintaxis nueva.
    """
    limpio, borrar = cuerpo, []
    for _, _, ini, fin in checks_de(cuerpo):
        borrar.append((ini, fin))
    for ini, fin in reversed(borrar):
        limpio = limpio[:ini] + ' ' * (fin - ini) + limpio[fin:]

    cols = []
    for linea in limpio.split('\n'):
        if NO_COLUMNA.match(linea) or not linea.strip() or linea.strip().startswith('--'):
            continue
        m = re.match(r'\s*`?(\w+)`?\s+(\S+)', linea)
        if m and TIPO.match(m.group(2)):
            cols.append(m.group(1))
    return cols

def fuera_de_literales(expr):
    """Concatena solo los tramos que NO estan entre comillas simples."""
    fuera, en_lit, buf = [], False, []
    i = 0
    while i < len(expr):
        c = expr[i]
        if en_lit:
            if c == "'":
                if i+1 < len(expr) and expr[i+1] == "'": i += 1
                else: en_lit = False
        elif c == "'":
            en_lit = True; fuera.append(''.join(buf)); buf = []
        else:
            buf.append(c)
        i += 1
    fuera.append(''.join(buf))
    return ' '.join(fuera)

def main():
    SALIDA.mkdir(exist_ok=True)
    declaraciones, sin_check_total, columnas_globales = [], [], {}
    for f in sorted(SQL.glob('*.sql')):
        if f.parent.name == 'generado' or f.name.startswith(('sonda-', 'ejemplo-')):
            continue
        texto = f.read_text(encoding='utf-8')
        nuevo = texto
        for tabla, cuerpo, ini, fin in bloques_create_table(texto):
            cols = columnas_de(cuerpo)
            columnas_globales.setdefault(tabla, [])
            columnas_globales[tabla] += [c for c in cols if c not in columnas_globales[tabla]]
            # columnas anadidas luego por ALTER TABLE ... ADD COLUMN
            for a in re.finditer(r'ALTER\s+TABLE\s+`?(\w+)`?(.*?);', texto, re.I | re.S):
                for ac in re.finditer(r'ADD\s+COLUMN\s+`?(\w+)`?', a.group(2), re.I):
                    columnas_globales.setdefault(a.group(1), [])
                    if ac.group(1) not in columnas_globales[a.group(1)]:
                        columnas_globales[a.group(1)].append(ac.group(1))
            for nombre, expr, _, _ in checks_de(cuerpo):
                visible = fuera_de_literales(expr)
                usadas = [c for c in cols
                          if re.search(r'(?<![`\w.])' + re.escape(c) + r'(?![`\w])', visible)]
                if not usadas:
                    print(f'  !! {nombre}: ninguna columna declarada aparece en la expresion')
                    continue
                declaraciones.append({
                    'tabla': tabla, 'nombre': nombre, 'expresion': expr,
                    'columnas': usadas,
                    'mensaje': f'Restriccion {nombre} incumplida.',
                })
        # CHECK declarados por ALTER TABLE
        for tabla, nombre, expr, _, _ in checks_en_alter(texto):
            cols_tabla = columnas_globales.get(tabla, [])
            visible = fuera_de_literales(expr)
            usadas = [c for c in cols_tabla
                      if re.search(r'(?<![`\w.])' + re.escape(c) + r'(?![`\w])', visible)]
            if not usadas:
                print(f'  !! {nombre}: ninguna columna conocida en la expresion (ALTER)')
                continue
            declaraciones.append({
                'tabla': tabla, 'nombre': nombre, 'expresion': expr,
                'columnas': usadas,
                'mensaje': f'Restriccion {nombre} incumplida.',
            })

        # quita cada CHECK del texto (de atras hacia adelante para no mover indices)
        for tabla, cuerpo, ini, fin in reversed(bloques_create_table(nuevo)):
            c2 = cuerpo
            for nombre, expr, cini, cfin in reversed(checks_de(cuerpo)):
                resto = c2[cfin:]
                extra = len(resto) - len(resto.lstrip(' ,\n'))   # coma y salto sobrantes
                c2 = c2[:cini].rstrip().rstrip(',') + ('\n' if '\n' in resto[:extra] else '') + resto[extra:]
            # Si lo ultimo que queda antes del cierre es una coma seguida solo de
            # comentarios y espacios, el CREATE TABLE no compila. Pasaba con
            # creator_availability, cuyo ultimo CHECK iba precedido de 6 lineas
            # de comentario que sobrevivian al borrado.
            c2 = re.sub(r',(\s*(?:--[^\n]*\n\s*)*)$', r'\1', c2)
            nuevo = nuevo[:ini] + f'CREATE TABLE {tabla} (' + c2 + nuevo[fin-1:]
        for _, _, _, ini, fin in reversed(checks_en_alter(nuevo)):
            resto = nuevo[fin:]
            nuevo = nuevo[:ini].rstrip().rstrip(',') + resto
        # una sentencia ALTER que se quedo sin nada que anadir
        nuevo = re.sub(r'ALTER\s+TABLE\s+`?\w+`?\s*;', '', nuevo, flags=re.I)
        (SALIDA / f'{f.stem}-sin-check.sql').write_text(nuevo, encoding='utf-8')
        sin_check_total.append(f.stem)

    (SALIDA / 'declaraciones.json').write_text(json.dumps(declaraciones, indent=1), encoding='utf-8')
    print(f'{len(declaraciones)} restricciones declaradas en {len(sin_check_total)} esquemas')

    r = subprocess.run(['php', str(RAIZ / 'tools' / 'generar-triggers.php'),
                        str(SALIDA / 'declaraciones.json')],
                       capture_output=True, text=True)
    if r.returncode != 0:
        print(r.stderr); sys.exit(1)
    (SALIDA / 'triggers.sql').write_text(r.stdout, encoding='utf-8')
    print(f'triggers.sql: {r.stdout.count("CREATE TRIGGER")} disparadores')

main()
