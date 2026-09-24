#!/usr/bin/env python3
"""Generate the .wordpress-org/ banner and icon assets from assets/logo.svg.

Sized to the WordPress.org directory spec (banner 1544x500 + 772x250, icon
256x256 + 128x128) so the same files serve the GitHub release page and any
future directory listing. Run from the plugin root:

    python3 scripts/make-assets.py

Requires rsvg-convert (librsvg) and Pillow.
"""
import subprocess
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parent.parent
LOGO = ROOT / "assets" / "logo.svg"
OUT = ROOT / ".wordpress-org"

# CHIP brand: indigo banner, white mark. Sampled from the sibling plugins'
# published banners so this one sits in the same family.
INDIGO = (74, 65, 203)
INK = (15, 23, 42)
WHITE = (255, 255, 255)

FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_REGULAR = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"

TITLE = "CHIP for Sprout Invoices"
TAGLINE = "Accept DuitNow QR, FPX, cards and e-wallets on your invoices"


def render_logo(width: int, fill: str) -> Image.Image:
    """Rasterise assets/logo.svg at the requested width, recoloured."""
    svg = LOGO.read_text(encoding="utf-8")
    # The mark ships in ink; recolour it for whichever surface it lands on.
    svg = svg.replace('fill="#0F172A"', f'fill="{fill}"')
    tmp = Path("/tmp/chip-asset-logo.svg")
    tmp.write_text(svg, encoding="utf-8")
    png = Path("/tmp/chip-asset-logo.png")
    subprocess.run(
        ["rsvg-convert", "-w", str(width), "-o", str(png), str(tmp)],
        check=True,
    )
    return Image.open(png).convert("RGBA")


def font(path: str, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(path, size)


def banner(width: int, height: int) -> Image.Image:
    img = Image.new("RGB", (width, height), INDIGO)
    draw = ImageDraw.Draw(img)

    scale = width / 1544
    logo_w = int(190 * scale)
    logo = render_logo(logo_w, "#FFFFFF")
    logo_h = int(logo.height * (logo_w / logo.width))

    pad = int(96 * scale)
    text_x = pad + logo_w + int(44 * scale)

    title = font(FONT_BOLD, int(78 * scale))
    tag = font(FONT_REGULAR, int(34 * scale))

    t_box = draw.textbbox((0, 0), TITLE, font=title)
    s_box = draw.textbbox((0, 0), TAGLINE, font=tag)
    block = (t_box[3] - t_box[1]) + int(20 * scale) + (s_box[3] - s_box[1])
    top = (height - block) // 2

    img.paste(logo, (pad, (height - logo_h) // 2), logo)
    draw.text((text_x, top), TITLE, font=title, fill=WHITE)
    draw.text(
        (text_x, top + (t_box[3] - t_box[1]) + int(20 * scale)),
        TAGLINE,
        font=tag,
        fill=(214, 212, 245),
    )
    return img


def icon(size: int) -> Image.Image:
    """Icon keeps the mark on brand indigo, with the safe padding wp.org wants."""
    img = Image.new("RGB", (size, size), INDIGO)
    draw = ImageDraw.Draw(img)
    inner = int(size * 0.60)
    logo = render_logo(inner, "#FFFFFF")
    img.paste(
        logo,
        ((size - logo.width) // 2, (size - logo.height) // 2),
        logo,
    )
    # Rounded corners, matching how the directory renders icons.
    mask = Image.new("L", (size, size), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, size - 1, size - 1), radius=int(size * 0.22), fill=255
    )
    out = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    out.paste(img, (0, 0), mask)
    return out


def main() -> int:
    OUT.mkdir(exist_ok=True)
    for w, h, name in (
        (1544, 500, "banner-1544x500.png"),
        (772, 250, "banner-772x250.png"),
    ):
        banner(w, h).save(OUT / name, optimize=True)
        print(f"wrote {OUT / name}")
    for s, name in ((256, "icon-256x256.png"), (128, "icon-128x128.png")):
        icon(s).save(OUT / name, optimize=True)
        print(f"wrote {OUT / name}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
