"""Vectorises the official X2KEY logo (resources/brand-src/x2key-logo-source.png) into SVG paths.

    python scripts/trace-logo.py

Splits ink (black) and accent (red #ED1C24) pixels, upsamples 8x, traces contours with OpenCV, simplifies them and
writes resources/js/Components/Brand/logo-paths.json plus public/brand/x2key-logo*.svg / x2key-mark*.svg.
"""
import json
from pathlib import Path

import cv2
import numpy as np

ROOT = Path(__file__).resolve().parent.parent
SRC = ROOT / 'resources' / 'brand-src' / 'x2key-logo-source.png'
UP = 8
RED = '#ED1C24'
INK = '#111111'


def masks(img: np.ndarray):
    b, g, r = [img[..., i].astype(int) for i in range(3)]
    a = img[..., 3].astype(int) if img.shape[2] == 4 else np.full(r.shape, 255)
    visible = a > 128
    red = visible & (r > 150) & (g < 110) & (b < 110)
    ink = visible & (r < 110) & (g < 110) & (b < 110)
    return ink, red


def trace(mask: np.ndarray, ox: int, oy: int) -> str:
    m = cv2.resize(mask.astype(np.uint8) * 255, None, fx=UP, fy=UP, interpolation=cv2.INTER_CUBIC)
    m = cv2.GaussianBlur(m, (5, 5), 0)
    _, m = cv2.threshold(m, 127, 255, cv2.THRESH_BINARY)
    contours, _ = cv2.findContours(m, cv2.RETR_CCOMP, cv2.CHAIN_APPROX_NONE)
    parts = []
    for c in contours:
        if cv2.contourArea(c) < 40 * UP:
            continue
        poly = cv2.approxPolyDP(c, 0.7 * UP, True).reshape(-1, 2)
        pts = [f'{x / UP - ox:.1f} {y / UP - oy:.1f}' for x, y in poly]
        parts.append('M' + 'L'.join(pts) + 'Z')
    return ''.join(parts)


def bbox(mask: np.ndarray):
    ys, xs = np.nonzero(mask)
    return int(xs.min()), int(ys.min()), int(xs.max()) + 1, int(ys.max()) + 1


def main() -> None:
    img = cv2.imread(str(SRC), cv2.IMREAD_UNCHANGED)
    if img.shape[2] == 3:
        img = cv2.cvtColor(img, cv2.COLOR_BGR2BGRA)
    ink, red = masks(img)
    both = ink | red
    x0, y0, x1, y1 = bbox(both)

    # The mark is everything left of the first fully empty column gap.
    cols = both[y0:y1].any(axis=0)
    split = next(x for x in range(x0 + 20, x1) if not cols[x])
    mx0, my0, mx1, my1 = bbox(both[:, :split])

    def crop(mask, a, b):
        out = np.zeros_like(mask)
        out[:, a:b] = mask[:, a:b]
        return out

    data = {
        'mark': {'w': mx1 - mx0, 'h': my1 - my0, 'ink': trace(crop(ink, 0, split), mx0, my0), 'red': trace(crop(red, 0, split), mx0, my0)},
        'full': {'w': x1 - x0, 'h': y1 - y0, 'ink': trace(ink, x0, y0), 'red': trace(red, x0, y0)},
    }
    (ROOT / 'resources' / 'js' / 'Components' / 'Brand' / 'logo-paths.json').write_text(json.dumps(data, indent=2) + '\n')

    brand = ROOT / 'public' / 'brand'
    for name, key, ink_fill, red_fill in [
        ('x2key-logo', 'full', INK, RED),
        ('x2key-logo-white', 'full', '#FFFFFF', RED),
        ('x2key-mark', 'mark', INK, RED),
        ('x2key-mark-mono', 'mark', 'currentColor', 'currentColor'),
    ]:
        d = data[key]
        svg = (
            f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {d["w"]} {d["h"]}" role="img" aria-label="X2KEY">'
            f'<path fill="{ink_fill}" fill-rule="evenodd" d="{d["ink"]}"/>'
            f'<path fill="{red_fill}" fill-rule="evenodd" d="{d["red"]}"/></svg>\n'
        )
        (brand / f'{name}.svg').write_text(svg)
    print('mark', data['mark']['w'], data['mark']['h'], 'full', data['full']['w'], data['full']['h'], 'bytes', len(json.dumps(data)))


if __name__ == '__main__':
    main()
