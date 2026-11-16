#!/usr/bin/env python3
"""Genera el icono de Liveo (Android) a partir de la geometría del logo.

Uso:  python3 generate_icons.py      (requiere: pip install pillow numpy)
Salida: android/res/mipmap-*/ (adaptive + legacy + monocromo) y liveo_icon_1024.png

La geometría (anillo, triángulo de play y punto "en vivo") es la misma que
dibuja LogoPainter en lib/main.dart, en un lienzo de 100 x 100 unidades.
"""
import math
import pathlib

import numpy as np
from PIL import Image, ImageDraw, ImageFilter

HERE = pathlib.Path(__file__).resolve().parent
OUT = HERE / "android" / "res"

LIME = (182, 255, 92)
CYAN = (34, 211, 238)
VIOLET = (139, 92, 246)
PINK = (244, 63, 94)
ORANGE = (251, 146, 60)
NAVY0 = (11, 18, 48)
NAVY1 = (7, 11, 20)

M = 2048  # tamaño del lienzo maestro


def lerp(a, b, t):
    return tuple(a[i] + (b[i] - a[i]) * t for i in range(3))


def sweep_image(size, cx, cy):
    """Degradado cónico a lo largo del anillo: lima -> cian -> violeta.

    El anillo empieza en 330° y recorre 300° en sentido horario; el degradado
    sigue ese recorrido (igual que SweepGradient en LogoPainter).
    """
    yy, xx = np.mgrid[0:size, 0:size].astype(np.float32)
    ang = (np.degrees(np.arctan2(yy - cy, xx - cx)) + 360.0) % 360.0
    s_ = np.clip(((ang - 330.0) % 360.0) / 300.0, 0.0, 1.0)
    # los píxeles del hueco (s_ > 1 antes de recortar) se asignan al extremo más cercano
    raw = ((ang - 330.0) % 360.0) / 300.0
    s_ = np.where(raw > 1.0, np.where(raw > 1.1, 0.0, 1.0), raw)
    out = np.zeros((size, size, 3), np.float32)
    lo = s_ <= 0.5
    t1 = (s_ / 0.5)[..., None]
    t2 = ((s_ - 0.5) / 0.5)[..., None]
    a = np.array(LIME, np.float32); b = np.array(CYAN, np.float32); c = np.array(VIOLET, np.float32)
    out = np.where(lo[..., None], a + (b - a) * t1, b + (c - b) * t2)
    return Image.fromarray(np.clip(out, 0, 255).astype(np.uint8), "RGB").convert("RGBA")


def linear_h(size, x0, x1, c0, c1):
    xs = np.clip((np.arange(size, dtype=np.float32) - x0) / max(x1 - x0, 1), 0, 1)
    row = np.array(c0, np.float32) + (np.array(c1, np.float32) - np.array(c0, np.float32)) * xs[:, None]
    img = np.repeat(row[None, :, :], size, axis=0)
    return Image.fromarray(img.astype(np.uint8), "RGB").convert("RGBA")


def logo_layer(u, mono=False):
    """Logo sobre lienzo transparente MxM. `u` = píxeles por unidad (100 u = logo)."""
    size = M
    ox = oy = (size - 100 * u) / 2.0

    def P(x, y):
        return (ox + x * u, oy + y * u)

    layer = Image.new("RGBA", (size, size), (0, 0, 0, 0))

    # ---- anillo (arco de 300°, hueco arriba a la derecha) ----
    r, w = 31.0, 7.0
    cx, cy = P(50, 50)
    outer = (r + w / 2) * u
    mask = Image.new("L", (size, size), 0)
    md = ImageDraw.Draw(mask)
    bbox = [cx - outer, cy - outer, cx + outer, cy + outer]
    wpx = int(round(w * u))
    md.arc(bbox, 330, 360, fill=255, width=wpx)
    md.arc(bbox, 0, 270, fill=255, width=wpx)
    for deg in (330, 270):  # remates redondeados
        px = cx + r * u * math.cos(math.radians(deg))
        py = cy + r * u * math.sin(math.radians(deg))
        md.ellipse([px - w * u / 2, py - w * u / 2, px + w * u / 2, py + w * u / 2], fill=255)
    ring_src = Image.new("RGBA", (size, size), (255, 255, 255, 255)) if mono else sweep_image(size, cx, cy)
    layer.paste(ring_src, (0, 0), mask)

    # ---- triángulo de play con esquinas redondeadas ----
    verts = [P(43, 36), P(43, 64), P(67, 50)]
    tm = Image.new("L", (size, size), 0)
    td = ImageDraw.Draw(tm)
    td.polygon(verts, fill=255)
    rr = 2.5 * u
    for i in range(3):
        a, b = verts[i], verts[(i + 1) % 3]
        dxe, dye = b[0] - a[0], b[1] - a[1]
        ln = math.hypot(dxe, dye)
        nx, ny = -dye / ln * rr, dxe / ln * rr
        td.polygon([(a[0] + nx, a[1] + ny), (b[0] + nx, b[1] + ny),
                    (b[0] - nx, b[1] - ny), (a[0] - nx, a[1] - ny)], fill=255)
        td.ellipse([a[0] - rr, a[1] - rr, a[0] + rr, a[1] + rr], fill=255)
    if mono:
        tri_src = Image.new("RGBA", (size, size), (255, 255, 255, 255))
    else:
        tri_src = linear_h(size, P(41, 0)[0], P(69, 0)[0], LIME, CYAN)
    layer.paste(tri_src, (0, 0), tm)

    # ---- punto "en vivo" ----
    dx, dy = P(50 + 31 * math.cos(math.radians(300)), 50 + 31 * math.sin(math.radians(300)))
    dr = 5.5 * u
    if not mono:
        glow = Image.new("RGBA", (size, size), (0, 0, 0, 0))
        ImageDraw.Draw(glow).ellipse([dx - dr * 1.5, dy - dr * 1.5, dx + dr * 1.5, dy + dr * 1.5], fill=PINK + (200,))
        glow = glow.filter(ImageFilter.GaussianBlur(dr * 0.9))
        layer = Image.alpha_composite(layer, glow)
    dm = Image.new("L", (size, size), 0)
    ImageDraw.Draw(dm).ellipse([dx - dr, dy - dr, dx + dr, dy + dr], fill=255)
    if mono:
        dot_src = Image.new("RGBA", (size, size), (255, 255, 255, 255))
    else:
        yy = np.linspace(0, 1, size, dtype=np.float32)
        grad = np.array(PINK, np.float32) + (np.array(ORANGE, np.float32) - np.array(PINK, np.float32)) * yy[:, None]
        dot_src = Image.fromarray(np.repeat(grad[:, None, :], size, axis=1).astype(np.uint8), "RGB").convert("RGBA")
    layer.paste(dot_src, (0, 0), dm)
    return layer


def background_layer():
    """Fondo azul noche con brillos violeta y cian."""
    size = M
    yy, xx = np.mgrid[0:size, 0:size].astype(np.float32)
    t = (xx + yy) / (2.0 * size)
    base = np.array(NAVY0, np.float32) * (1 - t[..., None]) + np.array(NAVY1, np.float32) * t[..., None]

    def blob(cx, cy, rad, color, strength):
        d = np.sqrt((xx - cx) ** 2 + (yy - cy) ** 2) / rad
        a = np.clip(1 - d, 0, 1) ** 2 * strength
        return a[..., None] * np.array(color, np.float32)

    out = base
    out = out * 1.0 + blob(size * 0.15, size * 0.12, size * 0.75, VIOLET, 0.55)
    out = out * 1.0 + blob(size * 0.92, size * 0.95, size * 0.70, CYAN, 0.40)
    out = out + blob(size * 0.05, size * 0.98, size * 0.45, LIME, 0.18)
    return Image.fromarray(np.clip(out, 0, 255).astype(np.uint8), "RGB").convert("RGBA")


def save(img, path, px):
    path.parent.mkdir(parents=True, exist_ok=True)
    img.resize((px, px), Image.LANCZOS).save(path, optimize=True)


def main():
    bg = background_layer()
    # Adaptive: el logo ocupa ~70 % del lienzo de 108 dp (zona segura = 61 %)
    fg = logo_layer(u=M * 0.70 / 100)
    mono = logo_layer(u=M * 0.70 / 100, mono=True)
    # Legacy / 1024: logo grande sobre tile redondeado
    full = Image.alpha_composite(bg, logo_layer(u=M * 0.80 / 100))

    densities = {"mdpi": 1.0, "hdpi": 1.5, "xhdpi": 2.0, "xxhdpi": 3.0, "xxxhdpi": 4.0}
    for name, k in densities.items():
        d = OUT / f"mipmap-{name}"
        save(bg, d / "ic_launcher_background.png", int(round(108 * k)))
        save(fg, d / "ic_launcher_foreground.png", int(round(108 * k)))
        save(mono, d / "ic_launcher_monochrome.png", int(round(108 * k)))
        px = int(round(48 * k))
        rr = Image.new("L", (M, M), 0)
        ImageDraw.Draw(rr).rounded_rectangle([0, 0, M - 1, M - 1], radius=int(M * 0.22), fill=255)
        legacy = full.copy()
        legacy.putalpha(rr)
        save(legacy, d / "ic_launcher.png", px)

    anydpi = OUT / "mipmap-anydpi-v26"
    anydpi.mkdir(parents=True, exist_ok=True)
    (anydpi / "ic_launcher.xml").write_text(
        '<?xml version="1.0" encoding="utf-8"?>\n'
        '<adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">\n'
        '    <background android:drawable="@mipmap/ic_launcher_background"/>\n'
        '    <foreground android:drawable="@mipmap/ic_launcher_foreground"/>\n'
        '    <monochrome android:drawable="@mipmap/ic_launcher_monochrome"/>\n'
        '</adaptive-icon>\n',
        encoding="utf-8",
    )

    full.resize((1024, 1024), Image.LANCZOS).convert("RGB").save(HERE / "liveo_icon_1024.png", optimize=True)
    print("Iconos generados en", OUT)


if __name__ == "__main__":
    main()
