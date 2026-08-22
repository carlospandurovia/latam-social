"""Genera los assets derivados listos para web a partir del kit de marca."""
import io, re, json, pathlib
import cairosvg
from PIL import Image
import numpy as np

# La raiz sale de la ubicacion de este script, no de una ruta fija: una ruta
# absoluta del entorno de quien lo escribio solo funciona en ese entorno.
RAIZ = pathlib.Path(__file__).resolve().parent.parent

SRC = RAIZ / "marca"
DST = SRC / "derivados"
DST.mkdir(exist_ok=True)

NAVY, PURPLE, MAGENTA, ORANGE = "#070A2B", "#6635D8", "#D73382", "#FF7447"
GRAD = ('<linearGradient id="g" x1="0%" y1="100%" x2="100%" y2="0%">'
        f'<stop offset="0%" stop-color="{ORANGE}"/>'
        f'<stop offset="48%" stop-color="{MAGENTA}"/>'
        f'<stop offset="100%" stop-color="{PURPLE}"/></linearGradient>')

def content_bbox(svg_path, w=2000):
    """Caja del contenido real en unidades del viewBox."""
    png = cairosvg.svg2png(url=str(svg_path), output_width=w)
    im = Image.open(io.BytesIO(png)).convert("RGBA")
    a = np.asarray(im)[:, :, 3]
    ys, xs = np.where(a > 8)
    vb = re.search(r'viewBox="([\d.\s-]+)"', svg_path.read_text()).group(1).split()
    vw, vh = float(vb[2]), float(vb[3])
    s = vw / im.width
    return xs.min()*s, ys.min()*s, xs.max()*s, ys.max()*s, vw, vh

def trim(src_name, out_name, pad_ratio=0.06):
    p = DST / src_name
    x0, y0, x1, y1, vw, vh = content_bbox(p)
    pad = (y1 - y0) * pad_ratio
    nx, ny = x0 - pad, y0 - pad
    nw, nh = (x1 - x0) + 2*pad, (y1 - y0) + 2*pad
    svg = p.read_text(encoding="utf-8")
    svg = re.sub(r'width="[\d.]+" height="[\d.]+" viewBox="[^"]+"',
                 f'width="{nw:.0f}" height="{nh:.0f}" '
                 f'viewBox="{nx:.2f} {ny:.2f} {nw:.2f} {nh:.2f}"', svg, count=1)
    (DST / out_name).write_text(svg, encoding="utf-8")
    print(f"  {out_name}: viewBox recortado {vw:.0f}x{vh:.0f} -> {nw:.0f}x{nh:.0f} "
          f"(se elimina {100-100*(nw*nh)/(vw*vh):.0f}% de lienzo vacío)")
    return nw, nh

print("Recorte de lienzo:")
trim("01_Logo_Horizontal_outlined.svg", "logo-horizontal.svg")
trim("02_Logo_Vertical_outlined.svg", "logo-vertical.svg")
trim("07_Fondo_Oscuro_outlined.svg", "logo-horizontal-dark.svg", pad_ratio=0.0)

# ---------- isotipo: el ojo, con el aro blanco intacto ----------
def isotipo(bg=None, ring="#FFFFFF", pupil=NAVY, size=512, radius=None):
    r = size/2
    bgel = f'<rect width="{size}" height="{size}" rx="{radius or 0}" fill="{bg}"/>' if bg else ""
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{size}" height="{size}" '
            f'viewBox="0 0 {size} {size}"><defs>{GRAD}</defs>{bgel}'
            f'<circle cx="{r}" cy="{r}" r="{size*0.3125:.1f}" fill="url(#g)"/>'
            f'<circle cx="{r}" cy="{r}" r="{size*0.18125:.2f}" fill="{ring}"/>'
            f'<circle cx="{r}" cy="{r}" r="{size*0.0625:.2f}" fill="{pupil}"/>'
            f'<circle cx="{r-size*0.0172:.2f}" cy="{r-size*0.0187:.2f}" r="{size*0.0125:.2f}" '
            f'fill="#FFFFFF" opacity=".95"/></svg>')

(DST/"isotipo.svg").write_text(isotipo(), encoding="utf-8")
(DST/"favicon.svg").write_text(isotipo(bg=NAVY, radius=96), encoding="utf-8")

# maskable: el contenido debe caber en el 80% central (safe zone de Android)
def maskable(size=512):
    r = size/2; k = 0.78          # el ojo ocupa 78% del ancho -> dentro de la zona segura
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{size}" height="{size}" '
            f'viewBox="0 0 {size} {size}"><defs>{GRAD}</defs>'
            f'<rect width="{size}" height="{size}" fill="{NAVY}"/>'
            f'<circle cx="{r}" cy="{r}" r="{size*0.3125*k:.1f}" fill="url(#g)"/>'
            f'<circle cx="{r}" cy="{r}" r="{size*0.18125*k:.2f}" fill="#FFFFFF"/>'
            f'<circle cx="{r}" cy="{r}" r="{size*0.0625*k:.2f}" fill="{NAVY}"/></svg>')
(DST/"icon-maskable.svg").write_text(maskable(), encoding="utf-8")

# monocromo para notificaciones (Android exige silueta de un solo color)
(DST/"icon-monochrome.svg").write_text(
    f'<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" viewBox="0 0 512 512">'
    f'<path fill="#000" fill-rule="evenodd" d="M256 96a160 160 0 1 0 0 320 160 160 0 0 0 0-320zm0 67.2a92.8 92.8 0 1 1 0 185.6 92.8 92.8 0 0 1 0-185.6z"/>'
    f'<circle cx="256" cy="256" r="32" fill="#000"/></svg>', encoding="utf-8")

# ---------- PNG derivados ----------
print("\nPNG generados:")
for name, src, sizes in [
    ("favicon", "favicon.svg", [16, 32, 48, 180, 192, 512]),
    ("icon-maskable", "icon-maskable.svg", [192, 512]),
    ("isotipo", "isotipo.svg", [512]),
]:
    for s in sizes:
        out = DST / f"{name}-{s}.png"
        cairosvg.svg2png(url=str(DST/src), write_to=str(out), output_width=s, output_height=s)
    print(f"  {name}: {', '.join(str(x) for x in sizes)} px")

# ---------- Open Graph 1200x630 ----------
og = f'''<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="630" viewBox="0 0 1200 630">
<defs>{GRAD}
<linearGradient id="glow" x1="0%" y1="0%" x2="100%" y2="100%">
<stop offset="0%" stop-color="{PURPLE}" stop-opacity=".55"/><stop offset="100%" stop-color="{MAGENTA}" stop-opacity="0"/></linearGradient></defs>
<rect width="1200" height="630" fill="{NAVY}"/>
<circle cx="1080" cy="120" r="360" fill="url(#glow)"/>
<rect x="0" y="612" width="1200" height="18" fill="url(#g)"/>
</svg>'''
(DST/"_og_base.svg").write_text(og, encoding="utf-8")
cairosvg.svg2png(url=str(DST/"_og_base.svg"), write_to=str(DST/"_og_base.png"), output_width=1200, output_height=630)

base = Image.open(DST/"_og_base.png").convert("RGBA")
logo_png = cairosvg.svg2png(url=str(DST/"logo-horizontal-dark.svg"), output_width=760)
logo = Image.open(io.BytesIO(logo_png)).convert("RGBA")
base.alpha_composite(logo, (80, (630 - logo.height)//2 - 20))
base.convert("RGB").save(DST/"og-image.png", quality=92)
(DST/"_og_base.svg").unlink(); (DST/"_og_base.png").unlink()
print("  og-image.png: 1200x630")

# ---------- webmanifest ----------
manifest = {
  "name": "LATAM Social", "short_name": "LATAM Social",
  "description": "Plataforma de Creator Marketing",
  "start_url": "/creators/", "scope": "/creators/",
  "display": "standalone", "orientation": "portrait",
  "background_color": NAVY, "theme_color": NAVY,
  "lang": "es-PE", "dir": "ltr",
  "icons": [
    {"src": "/img/favicon-192.png", "sizes": "192x192", "type": "image/png", "purpose": "any"},
    {"src": "/img/favicon-512.png", "sizes": "512x512", "type": "image/png", "purpose": "any"},
    {"src": "/img/icon-maskable-192.png", "sizes": "192x192", "type": "image/png", "purpose": "maskable"},
    {"src": "/img/icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"},
    {"src": "/img/icon-monochrome.svg", "sizes": "any", "type": "image/svg+xml", "purpose": "monochrome"}
  ]
}
(DST/"site.webmanifest").write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
print("  site.webmanifest")
