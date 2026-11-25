"""Renders the X2KEY raster icons from the traced logo mark (docs/BRAND.md §2).

    python scripts/trace-logo.py   # once, when the source logo changes
    python scripts/brand-icons.py

Writes public/favicon.ico (16/32/48), public/brand/apple-touch-icon.png (180), icon-192.png, icon-512.png and
icon-maskable-512.png. The mark sits on a white rounded tile so it reads on light and dark browser chrome.
Drawn at 8x and downsampled for smooth edges.
"""
import json
import re
from pathlib import Path

from PIL import Image, ImageChops, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
INK = (0x11, 0x11, 0x11, 255)
RED = (0xED, 0x1C, 0x24, 255)
WHITE = (255, 255, 255, 255)
SS = 8


def subpaths(d: str) -> list[list[tuple[float, float]]]:
    out = []
    for chunk in re.findall(r'M([^Z]+)Z', d):
        pts = [tuple(map(float, p.split())) for p in chunk.split('L')]
        out.append(pts)
    return out


def shape_mask(d: str, size: int, scale: float, off: float) -> Image.Image:
    """Even-odd fill: XOR every subpath polygon."""
    mask = Image.new('1', (size, size), 0)
    for pts in subpaths(d):
        layer = Image.new('1', (size, size), 0)
        ImageDraw.Draw(layer).polygon([(off + x * scale, off + y * scale) for x, y in pts], fill=1)
        mask = ImageChops.logical_xor(mask, layer)
    return mask.convert('L')


def icon(size: int, inset: float, rounded: bool = True) -> Image.Image:
    mark = json.loads((ROOT / 'resources/js/Components/Brand/logo-paths.json').read_text())['mark']
    big = size * SS
    canvas = Image.new('RGBA', (big, big), (0, 0, 0, 0))
    tile = Image.new('L', (big, big), 0)
    if rounded:
        ImageDraw.Draw(tile).rounded_rectangle([0, 0, big - 1, big - 1], radius=int(big * 0.22), fill=255)
    else:
        tile.paste(255, (0, 0, big, big))
    canvas.paste(WHITE, (0, 0), tile)

    inner = big * (1 - 2 * inset)
    scale = inner / max(mark['w'], mark['h'])
    off = big * inset
    canvas.paste(INK, (0, 0), shape_mask(mark['ink'], big, scale, off))
    canvas.paste(RED, (0, 0), shape_mask(mark['red'], big, scale, off))
    return canvas.resize((size, size), Image.LANCZOS)


def main() -> None:
    brand = ROOT / 'public' / 'brand'
    brand.mkdir(parents=True, exist_ok=True)
    icon(48, 0.12).save(ROOT / 'public' / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    icon(180, 0.16, rounded=False).convert('RGB').save(brand / 'apple-touch-icon.png', optimize=True)
    icon(192, 0.14).save(brand / 'icon-192.png', optimize=True)
    icon(512, 0.14).save(brand / 'icon-512.png', optimize=True)
    icon(512, 0.22, rounded=False).save(brand / 'icon-maskable-512.png', optimize=True)
    print('icons written to', brand)


if __name__ == '__main__':
    main()
