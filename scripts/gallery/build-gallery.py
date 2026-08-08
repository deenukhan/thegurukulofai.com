#!/usr/bin/env python3
"""Rebuild the gallery from source media.

Reads  scripts/gallery/manifest.json  (curation: which file, slug, tags, caption)
Writes assets/gallery/<slug>-{800,1400}.{avif,webp}
       scripts/gallery/gallery-data.json  (dimensions + blur placeholders)
       gallery/index.html
Videos are transcoded to scripts/gallery/video-out/ then uploaded separately:
       scripts/bunny-upload-gallery.sh scripts/gallery/video-out

Requires:  pip3 install --user pillow imageio-ffmpeg
Usage:     python3 scripts/gallery/build-gallery.py [--images-only] [--html-only]
"""
import os, sys, json, io, base64, html, subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
SRC = os.path.join(ROOT, "Images for Website")   # originals, not committed
OUT_IMG = os.path.join(ROOT, "assets", "gallery")
OUT_VID = os.path.join(HERE, "video-out")
CDN = "https://tgai-cdn-2.b-cdn.net/gallery"
WIDTHS = [800, 1400]
QUALITY = {800: {"avif": 55, "webp": 76}, 1400: {"avif": 50, "webp": 70}}
CSS_V = {"base": 37, "components": 38, "gallery": 1}

images_only = "--images-only" in sys.argv
html_only = "--html-only" in sys.argv

CATS = [
    ("all", "All"), ("dubai", "Dubai"), ("google", "Google"),
    ("meta", "Meta &amp; Instagram"), ("microsoft", "Microsoft"),
    ("openai", "OpenAI"), ("stage", "On Stage"), ("access", "Backstage Passes"),
]


def build_media():
    from PIL import Image, ImageOps
    import imageio_ffmpeg
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    os.makedirs(OUT_IMG, exist_ok=True)
    os.makedirs(OUT_VID, exist_ok=True)

    def clean(path):
        if path.lower().endswith(".heic"):
            tmp = os.path.join(HERE, "_tmp.jpg")
            subprocess.run(["sips", "-s", "format", "jpeg", path, "--out", tmp],
                           check=True, capture_output=True)
            path = tmp
        return ImageOps.exif_transpose(Image.open(path)).convert("RGB")

    def blur_placeholder(im):
        t = im.copy()
        t.thumbnail((20, 20), Image.LANCZOS)
        buf = io.BytesIO()
        t.save(buf, "JPEG", quality=40)
        return "data:image/jpeg;base64," + base64.b64encode(buf.getvalue()).decode()

    manifest = json.load(open(os.path.join(HERE, "manifest.json")))
    data = []
    for n, item in enumerate(manifest, 1):
        slug = item["slug"]
        src = os.path.join(SRC, item["src"])
        entry = {k: item[k] for k in ("slug", "type", "cats", "caption", "place")}
        entry["feature"] = item.get("feature", False)

        if item["type"] == "video" and not images_only:
            vf = ("scale='if(gt(iw,ih),min(1280,iw),-2)':'if(gt(iw,ih),-2,min(1280,ih))'"
                  ",scale=trunc(iw/2)*2:trunc(ih/2)*2")
            subprocess.run([ffmpeg, "-y", "-i", src, "-vf", vf,
                            "-c:v", "libx264", "-crf", "26", "-preset", "slow",
                            "-profile:v", "high", "-pix_fmt", "yuv420p",
                            "-c:a", "aac", "-b:a", "96k", "-ac", "2",
                            "-movflags", "+faststart",
                            os.path.join(OUT_VID, slug + ".mp4")],
                           check=True, capture_output=True)
            subprocess.run([ffmpeg, "-y", "-t", "4", "-i", src, "-vf",
                            "scale='if(gt(iw,ih),min(640,iw),-2)':'if(gt(iw,ih),-2,min(640,ih))'"
                            ",scale=trunc(iw/2)*2:trunc(ih/2)*2",
                            "-an", "-c:v", "libx264", "-crf", "30", "-preset", "slow",
                            "-pix_fmt", "yuv420p", "-movflags", "+faststart",
                            os.path.join(OUT_VID, slug + "-loop.mp4")],
                           check=True, capture_output=True)

        if item["type"] == "video":
            poster = os.path.join(HERE, "_poster.png")
            subprocess.run([ffmpeg, "-y", "-ss", "1", "-i", src, "-frames:v", "1", poster],
                           check=True, capture_output=True)
            im = clean(poster)
            entry["mp4"] = slug + ".mp4"
            entry["loop"] = slug + "-loop.mp4"
        else:
            im = clean(src)

        entry["lqip"] = blur_placeholder(im)
        for w in WIDTHS:
            r = im if im.width <= w else im.resize(
                (w, round(im.height * w / im.width)), Image.LANCZOS)
            r.save(os.path.join(OUT_IMG, f"{slug}-{w}.avif"), quality=QUALITY[w]["avif"])
            r.save(os.path.join(OUT_IMG, f"{slug}-{w}.webp"), quality=QUALITY[w]["webp"], method=6)
            if w == 800:
                entry["w"], entry["h"] = r.width, r.height
        data.append(entry)
        print(f"[{n}/{len(manifest)}] {entry['type']:5} {slug}")

    json.dump(data, open(os.path.join(HERE, "gallery-data.json"), "w"), indent=1)
    for tmp in ("_tmp.jpg", "_poster.png"):
        p = os.path.join(HERE, tmp)
        if os.path.exists(p):
            os.remove(p)
    return data


def build_html(data):
    counts = {k: 0 for k, _ in CATS}
    counts["all"] = len(data)
    for d in data:
        for c in d["cats"]:
            counts[c] = counts.get(c, 0) + 1

    chips = "\n".join(
        '        <button type="button" class="g-chip" data-filter="{k}" aria-pressed="{p}">'
        '{label}<span class="g-chip-count">{n}</span></button>'.format(
            k=k, label=label, n=counts[k], p="true" if k == "all" else "false")
        for k, label in CATS)

    play = '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2 1l8 5-8 5z"/></svg>'
    tiles = []
    for i, d in enumerate(data):
        slug, title, place = d["slug"], html.escape(d["caption"], True), html.escape(d["place"], True)
        attrs = ['type="button"', 'class="g-item"',
                 'data-cats="%s"' % " ".join(d["cats"]),
                 'data-title="%s"' % title, 'data-place="%s"' % place,
                 'data-full="/assets/gallery/%s-1400.webp"' % slug,
                 'data-full-avif="/assets/gallery/%s-1400.avif"' % slug]
        if d["type"] == "video":
            attrs.append('data-mp4="%s/%s"' % (CDN, d["mp4"]))
            attrs.append('data-loop="%s/%s"' % (CDN, d["loop"]))
        attrs.append('style="background-image:url(%s)"' % d["lqip"])
        attrs.append('aria-label="Open: %s"' % title)
        badge = ('\n          <span class="g-badge">%s Video</span>' % play) if d["type"] == "video" else ""
        tiles.append(
            '        <button {attrs}>\n'
            '          <picture>\n'
            '            <source srcset="/assets/gallery/{slug}-800.avif" type="image/avif">\n'
            '            <img src="/assets/gallery/{slug}-800.webp" alt="{title}"\n'
            '                 width="{w}" height="{h}" loading="{load}" decoding="async"{fp}>\n'
            '          </picture>{badge}\n'
            '          <span class="g-cap">\n'
            '            <span class="g-cap-title">{title}</span>\n'
            '            <span class="g-cap-place">{place}</span>\n'
            '          </span>\n'
            '        </button>'.format(
                attrs=" ".join(attrs), slug=slug, title=title, place=place,
                w=d["w"], h=d["h"], load="eager" if i < 6 else "lazy",
                fp=' fetchpriority="high"' if i < 2 else "", badge=badge))

    tpl = open(os.path.join(HERE, "gallery.template.html")).read()
    page = tpl.replace("{{FIRST}}", data[0]["slug"]) \
              .replace("{{CHIPS}}", chips) \
              .replace("{{TILES}}", "\n".join(tiles)) \
              .replace("{{V_BASE}}", str(CSS_V["base"])) \
              .replace("{{V_COMPONENTS}}", str(CSS_V["components"])) \
              .replace("{{V_GALLERY}}", str(CSS_V["gallery"]))
    outdir = os.path.join(ROOT, "gallery")
    os.makedirs(outdir, exist_ok=True)
    open(os.path.join(outdir, "index.html"), "w").write(page)
    print("wrote gallery/index.html:", len(data), "items", counts)


if __name__ == "__main__":
    if html_only:
        build_html(json.load(open(os.path.join(HERE, "gallery-data.json"))))
    else:
        build_html(build_media())
