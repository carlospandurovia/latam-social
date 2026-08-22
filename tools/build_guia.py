import pathlib, re

# La raiz sale de la ubicacion de este script, no de una ruta fija: una ruta
# absoluta del entorno de quien lo escribio solo funciona en ese entorno.
RAIZ = pathlib.Path(__file__).resolve().parent.parent
D = RAIZ / "marca" / "derivados"

_n = [0]
def uniq(s):
    """Reasigna el id del degradado para poder incrustar varios SVG en la misma página."""
    _n[0] += 1
    u = f"g{_n[0]}"
    return s.replace('id="g"', f'id="{u}"').replace('url(#g)', f'url(#{u})')

def inner(p, size=None):
    s = uniq((D/p).read_text(encoding="utf-8"))
    if size:
        s = re.sub(r'width="[\d.]+" height="[\d.]+"', f'width="{size}" height="{size}"', s, count=1)
    else:
        s = re.sub(r'\swidth="[\d.]+"\s+height="[\d.]+"', '', s, count=1)
    return s

def raw(path, size):
    s = uniq(pathlib.Path(path).read_text(encoding="utf-8"))
    return re.sub(r'width="[\d.]+" height="[\d.]+"', f'width="{size}" height="{size}"', s, count=1)

OLD = str(RAIZ / "marca" / "08_Favicon.svg")

out = pathlib.Path(__file__).with_name("guia_tpl.html").read_text(encoding="utf-8")
out = out.replace("<!--LOGO-->", inner("logo-horizontal.svg"))
out = out.replace("<!--LOGO_DARK-->", inner("logo-horizontal-dark.svg"))
for sz in (16, 32, 56):
    out = out.replace(f"<!--FAV_OLD_{sz}-->", raw(OLD, sz))
    out = out.replace(f"<!--FAV_NEW_{sz}-->", inner("favicon.svg", sz))
out = out.replace("<!--MASKABLE_56-->", inner("icon-maskable.svg", 56))
(RAIZ / "design" / "guia-visual.html").write_text(out, encoding="utf-8")
print("guia-visual.html", len(out), "bytes")
