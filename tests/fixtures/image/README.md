# Image fixtures

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All files were generated with ImageMagick 7.1.1 on macOS. The PNGs are 8-bit grayscale with no
resolution (DPI), date or time metadata, so Tesseract has to estimate the resolution itself. The
multi-image files exercise the pixel limit, which must hold for every page or frame, not only the
first.

| File | Contents |
|---|---|
| `text.png` | 900 x 160, black "Invoice 2026 total" in 72 pt Arial on white |
| `blank.png` | 200 x 100, plain white |
| `two-pages-le.tif` | little-endian TIFF, page 1 20 x 10, page 2 200 x 100, plain white |
| `two-pages-be.tif` | the same as big-endian TIFF |
| `two-frames.gif` | GIF with a 20 x 10 logical screen and a second frame of 200 x 100 |

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

magick -size 20x10 xc:white -size 200x100 xc:white -colorspace Gray -depth 8 -strip \
  -compress Zip -endian LSB two-pages-le.tif
magick -size 20x10 xc:white -size 200x100 xc:white -colorspace Gray -depth 8 -strip \
  -compress Zip -endian MSB two-pages-be.tif
magick -size 20x10 xc:white -size 200x100 xc:white -colorspace Gray -depth 8 -strip \
  two-frames.gif
```

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
