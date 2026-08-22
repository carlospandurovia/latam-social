"""Convierte el texto vivo de los SVG del kit a contornos (paths) y recorta el viewBox."""
import re, pathlib
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen

# La raiz sale de la ubicacion de este script, no de una ruta fija: una ruta
# absoluta del entorno de quien lo escribio solo funciona en ese entorno.
RAIZ = pathlib.Path(__file__).resolve().parent.parent

FONTS = {
    ("DejaVu Sans", "700"): "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    ("DejaVu Sans", "400"): "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
}
_cache = {}
def font(weight):
    key = ("DejaVu Sans", weight)
    if key not in _cache:
        f = TTFont(FONTS[key])
        _cache[key] = (f, f.getGlyphSet(), f["cmap"].getBestCmap(), f["head"].unitsPerEm, f["hmtx"])
    return _cache[key]

def text_to_paths(txt, x, y, size, weight, spacing, fill, anchor="start"):
    f, gs, cmap, upem, hmtx = font(weight)
    s = size / upem
    glyphs, total = [], 0.0
    for ch in txt:
        gname = cmap.get(ord(ch))
        if gname is None:
            total += size * 0.5 + spacing
            continue
        adv = hmtx[gname][0] * s
        glyphs.append((gname, total, adv))
        total += adv + spacing
    start = x - total / 2 if anchor == "middle" else x
    out = []
    for gname, off, _ in glyphs:
        pen = SVGPathPen(gs)
        gs[gname].draw(pen)
        d = pen.getCommands()
        if not d:
            continue
        out.append(
            f'<path d="{d}" fill="{fill}" '
            f'transform="translate({start + off:.3f} {y:.3f}) scale({s:.6f} {-s:.6f})"/>'
        )
    return "\n    ".join(out)

TEXT_RE = re.compile(r'<text\b([^>]*)>(.*?)</text>', re.S)
ATTR_RE = re.compile(r'(\S+)="([^"]*)"')

def convert(svg):
    def repl(m):
        a = dict(ATTR_RE.findall(m.group(1)))
        return text_to_paths(
            m.group(2), float(a["x"]), float(a["y"]), float(a["font-size"]),
            a.get("font-weight", "400"), float(a.get("letter-spacing", 0)),
            a.get("fill", "#000"), a.get("text-anchor", "start"),
        )
    return TEXT_RE.sub(repl, svg)

if __name__ == "__main__":
    src = RAIZ / "marca"
    dst = src / "derivados"
    for name in ["01_Logo_Horizontal", "02_Logo_Vertical", "07_Fondo_Oscuro"]:
        svg = (src / f"{name}.svg").read_text(encoding="utf-8")
        out = convert(svg)
        assert "<text" not in out, name
        (dst / f"{name}_outlined.svg").write_text(out, encoding="utf-8")
        print("outlined:", name)
