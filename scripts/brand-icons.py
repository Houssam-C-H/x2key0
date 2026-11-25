"""Renders the X2KEY raster icons from the logo geometry (docs/BRAND.md §2).

    python scripts/brand-icons.py

Writes public/favicon.ico (16/32/48), public/brand/apple-touch-icon.png (180), icon-192.png, icon-512.png and
icon-maskable-512.png (mark inset in the safe zone). Drawn at 8x and downsampled for smooth edges.
Geometry matches public/brand/x2key-mark.svg (64-unit grid).
"""
from pathlib import Path

from PIL import Image, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
VIOLET = (0x5B, 0x3D, 0xF5)
MAGENTA = (0xDB, 0x27, 0x77)
DARK = (0x0B, 0x0E, 0x1F)
SS = 8  # supersampling


def gradient(size: int) -> Image.Image:
    img = Image.new('RGB', (size, size))
    px = img.load()
    for y in range(size):
        for x in range(size):
            t = (x + y) / (2 * (size - 1))
            px[x, y] = tuple(round(a + (b - a) * t) for a, b in zip(VIOLET, MAGENTA))
    return img


def mark(size: int, inset: float = 0.0, background: tuple | None = None) -> Image.Image:
    big = size * SS
    canvas = Image.new('RGBA', (big, big), background + (255,) if background else (0, 0, 0, 0))
    tile = int(big * (1 - 2 * inset))
    off = (big - tile) // 2
    u = tile / 64  # one unit of the 64-grid

    mask = Image.new('L', (tile, tile), 0)
    ImageDraw.Draw(mask).rounded_rectangle([0, 0, tile - 1, tile - 1], radius=int(14 * u), fill=255)
    canvas.paste(gradient(tile), (off, off), mask)

    d = ImageDraw.Draw(canvas)
    p = lambda x, y: (off + x * u, off + y * u)
    white = (255, 255, 255, 255)

    def line(a, b, w):
        d.line([p(*a), p(*b)], fill=white, width=int(w * u))
        for c in (a, b):  # round caps
            cx, cy = p(*c)
            r = w * u / 2
            d.ellipse([cx - r, cy - r, cx + r, cy + r], fill=white)

    cx, cy = p(22, 22)
    r_out, r_in = (7 + 2.5) * u, (7 - 2.5) * u
    d.ellipse([cx - r_out, cy - r_out, cx + r_out, cy + r_out], fill=white)
    d.ellipse([cx - r_in, cy - r_in, cx + r_in, cy + r_in], fill=(0, 0, 0, 0))
    # punch the bow's hole back to the gradient
    hole = Image.new('L', (big, big), 0)
    ImageDraw.Draw(hole).ellipse([cx - r_in, cy - r_in, cx + r_in, cy + r_in], fill=255)
    grad_full = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    grad_full.paste(gradient(tile), (off, off), mask)
    canvas.paste(grad_full, (0, 0), hole)

    line((27, 27), (45, 45), 6)
    line((39, 39), (43.5, 34.5), 5)
    line((43.5, 43.5), (48, 39), 5)
    line((45, 19), (19, 45), 6)

    return canvas.resize((size, size), Image.LANCZOS)


def main() -> None:
    brand = ROOT / 'public' / 'brand'
    brand.mkdir(parents=True, exist_ok=True)

    mark(48).save(ROOT / 'public' / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    mark(180, inset=0.08, background=DARK).convert('RGB').save(brand / 'apple-touch-icon.png', optimize=True)
    mark(192).save(brand / 'icon-192.png', optimize=True)
    mark(512).save(brand / 'icon-512.png', optimize=True)
    mark(512, inset=0.12, background=DARK).save(brand / 'icon-maskable-512.png', optimize=True)
    print('icons written to', brand)


if __name__ == '__main__':
    main()
