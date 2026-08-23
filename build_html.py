#!/usr/bin/env python3
"""Construye un dossier HTML navegable a partir de los documentos markdown de Fase 0."""
import re, html, pathlib, markdown

# La raiz sale de la ubicacion de este script, no de una ruta fija: una ruta
# absoluta del entorno de quien lo escribio solo funciona en ese entorno.
BASE = pathlib.Path(__file__).resolve().parent

DOCS = [
    ("readme", "README.md",                              "Portada e índice",     "—"),
    ("doc-00", "docs/00-EXECUTIVE-PRODUCT-DEFINITION.md", "Definición ejecutiva", "00"),
    ("doc-01", "docs/01-MODULE-MAP.md",                   "Mapa de módulos",      "01"),
    ("doc-02", "docs/02-END-TO-END-PROCESSES.md",         "Procesos end-to-end",  "02"),
    ("doc-03", "docs/03-ARCHITECTURE.md",                 "Arquitectura",         "03"),
    ("doc-04", "docs/04-ROADMAP.md",                      "Roadmap",              "04"),
    ("doc-05", "docs/05-DECISION-LOG.md",                 "Decision Log",         "05"),
    ("doc-06", "docs/06-BUSINESS-RULES.md",               "Business Rules",       "06"),
    ("doc-07", "docs/07-RISKS.md",                        "Riesgos",              "07"),
    ("doc-08", "docs/08-DEFINITION-OF-DONE.md",           "Definition of Done",   "08"),
    ("doc-09", "docs/09-NEXT-ITERATION.md",               "Siguiente iteración",  "09"),
    ("doc-10", "docs/10-CRITICAL-REVIEW.md",              "Revisión crítica",     "10"),
    ("doc-11", "docs/11-ADDENDUM-LEGAL-ENTITIES.md",      "Addendum multi-entidad", "11"),
    ("doc-12", "docs/12-ADDENDUM-INTEGRATIONS.md",        "Addendum integraciones", "12"),
    ("doc-13", "docs/13-ADDENDUM-GAMIFICATION.md",         "Addendum gamificación", "13"),
    ("doc-14", "docs/14-BRAND-AND-DESIGN-SYSTEM.md",       "Marca y diseño",        "14"),
    ("doc-15", "docs/15-ARRANQUE-MVP.md",                  "Arranque del MVP",      "15"),
    ("doc-16", "docs/16-RESPUESTAS-NEGOCIO.md",            "Respuestas del negocio","16"),
    ("it-2-1", "docs/fase-2/2.1-ENTIDADES-Y-GLOSARIO.md",  "Entidades y glosario",  "2.1"),
    ("it-2-2", "docs/fase-2/2.2-RELACIONES-Y-CARDINALIDADES.md", "Relaciones", "2.2"),
    ("it-2-3", "docs/fase-2/2.3-NORMALIZACION.md", "Normalización", "2.3"),
    ("it-2-4", "docs/fase-2/2.4-ATRIBUTOS-TIPOS-INDICES.md", "Atributos y tipos", "2.4"),
    ("it-2-5", "docs/fase-2/2.5-RESTRICCIONES-PORTABLES.md", "Restricciones portables", "2.5"),
    ("it-2-6", "docs/fase-2/2.6-CREADOR-IDENTIDAD-Y-PERFIL.md", "Creador", "2.6"),
    ("it-2-7", "docs/fase-2/2.7-CREADOR-PERFIL-COMERCIAL.md", "Perfil comercial", "2.7"),
    ("it-2-8", "docs/fase-2/2.8-CREADOR-FISCAL-Y-PAGOS.md", "Fiscal y pagos", "2.8"),
    ("it-2-9", "docs/fase-2/2.9-CLIENTE.md", "Cliente", "2.9"),
    ("it-2-10", "docs/fase-2/2.10-MARCA-Y-ENTIDADES-LEGALES.md", "Entidades legales", "2.10"),
    ("it-2-11", "docs/fase-2/2.11-CAMPANA.md", "Campaña", "2.11"),
    ("it-2-12", "docs/fase-2/2.12-CONTENIDO-Y-EVIDENCIA.md", "Contenido", "2.12"),
    ("it-2-13", "docs/fase-2/2.13-FINANZAS.md", "Finanzas", "2.13"),
    ("it-2-14", "docs/fase-2/2.14-PAGO-A-TERCEROS.md", "Pago a terceros", "2.14"),
    ("it-2-15", "docs/fase-2/2.15-RETENCIONES.md", "Retenciones", "2.15"),
    ("it-3-1", "docs/fase-3/3.1-PERMISOS.md", "Permisos", "3.1"),
    ("it-3-2", "docs/fase-3/3.2-BITACORA-Y-EDICION.md", "Bitácora", "3.2"),
    ("it-3-3", "docs/fase-3/3.3-PANTALLA-DE-BITACORA.md", "Consulta de bitácora", "3.3"),
    ("it-3-4", "docs/fase-3/3.4-BANDEJA-DE-SOLICITUDES.md", "Solicitudes", "3.4"),
    ("it-3-5", "docs/fase-3/3.5-ACTIVACION.md", "Activación del creador", "3.5"),
]

FILE_TO_ANCHOR = {f: a for a, f, _, _ in DOCS}
FILE_TO_ANCHOR.update({f.split("/")[-1]: a for a, f, _, _ in DOCS})


def slugger(prefix):
    def _s(value, separator):
        v = re.sub(r"[^\w\s-]", "", value.lower(), flags=re.UNICODE).strip()
        v = re.sub(r"[\s_]+", separator, v)
        return f"{prefix}-{v}"[:80]
    return _s


def fix_links(h, current):
    """Convierte enlaces a otros .md en anclas internas."""
    def repl(m):
        href = m.group(1)
        frag = ""
        if "#" in href:
            href, frag = href.split("#", 1)
        key = href.split("/")[-1]
        anchor = FILE_TO_ANCHOR.get(key) or FILE_TO_ANCHOR.get(href)
        return f'href="#{anchor}"' if anchor else 'href="#" class="dead"'
    return re.sub(r'href="([^"]*\.md[^"]*)"', repl, h)


sections, nav = [], []

for anchor, relpath, short, code in DOCS:
    raw = (BASE / relpath).read_text(encoding="utf-8")
    md = markdown.Markdown(
        extensions=["tables", "fenced_code", "sane_lists", "attr_list", "toc"],
        extension_configs={"toc": {"slugify": slugger(anchor)}},
    )
    body = md.convert(raw)
    body = fix_links(body, anchor)

    # Extraer el h1 como título del documento
    m = re.search(r"<h1[^>]*>(.*?)</h1>", body, re.S)
    title = re.sub(r"<[^>]+>", "", m.group(1)).strip() if m else short
    title = re.sub(r"^\d+\s*—\s*", "", title)
    body = re.sub(r"<h1[^>]*>.*?</h1>", "", body, count=1, flags=re.S)

    # Envolver tablas y bloques de código para scroll horizontal
    body = re.sub(r"<table>", '<div class="scroll"><table>', body)
    body = re.sub(r"</table>", "</table></div>", body)

    subs = re.findall(r'<h2 id="([^"]+)">(.*?)</h2>', body, re.S)
    subitems = "".join(
        f'<li><a href="#{sid}">{re.sub(r"<[^>]+>", "", stxt).strip()}</a></li>'
        for sid, stxt in subs
    )

    nav.append(
        f'<li class="nav-doc" data-target="{anchor}">'
        f'<a href="#{anchor}"><span class="nav-code">{code}</span>'
        f'<span class="nav-name">{html.escape(short)}</span></a>'
        f'<ul class="nav-sub">{subitems}</ul></li>'
    )

    sections.append(
        f'<section class="doc" id="{anchor}">'
        f'<div class="doc-head"><span class="doc-code">{code}</span>'
        f'<h1>{html.escape(title)}</h1></div>'
        f'{body}</section>'
    )

TEMPLATE = (BASE / "template.html").read_text(encoding="utf-8")
out = TEMPLATE.replace("<!--NAV-->", "".join(nav)).replace("<!--BODY-->", "".join(sections))
(BASE / "discovery.html").write_text(out, encoding="utf-8")
print("ok", len(out), "bytes")
