# Image fixtures

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

Both files were generated with ImageMagick 7.1.1 on macOS. They are 8-bit grayscale PNGs with no
resolution (DPI), date or time metadata, so Tesseract has to estimate the resolution itself.

| File | Contents |
|---|---|
| `text.png` | 900 x 160, black "Invoice 2026 total" in 72 pt Arial on white |
| `blank.png` | 200 x 100, plain white |

```sh
magick -size 900x160 xc:white \
  -font /System/Library/Fonts/Supplemental/Arial.ttf -pointsize 72 -fill black \
  -gravity center -annotate +0+0 'Invoice 2026 total' \
  -colorspace Gray -depth 8 -strip \
  -define png:color-type=0 -define png:bit-depth=8 -define png:exclude-chunks=date,time \
  text.png

magick -size 200x100 xc:white \
  -colorspace Gray -depth 8 -strip \
  -define png:color-type=0 -define png:bit-depth=8 -define png:exclude-chunks=date,time \
  blank.png
```

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
