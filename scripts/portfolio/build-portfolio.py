#!/usr/bin/env python3
"""Rebuild the ads portfolio from source video.

Reads  scripts/portfolio/manifest.json  (curation: which file, slug, brand, copy)
Writes assets/portfolio/<slug>-{600,1200}.{avif,webp}   posters, committed
       scripts/portfolio/portfolio-data.json            dimensions + blur placeholders
       portfolio/index.html
Video is transcoded to scripts/portfolio/video-out/ then uploaded separately:
       scripts/bunny-upload-portfolio.sh scripts/portfolio/video-out

Each source video produces three web assets:
  <slug>.mp4       full spot, longest side capped at 1280, faststart, AAC 128k
  <slug>-loop.mp4  silent 4s preview at 540px for desktop hover
  poster frame     grabbed at 1.5s, encoded to AVIF + WebP at 600 and 1200

Requires:  pip3 install --user pillow imageio-ffmpeg
Usage:     python3 scripts/portfolio/build-portfolio.py [--media-only] [--html-only] [--force]
"""
import os, sys, json, io, base64, html, subprocess

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", ".."))
SRC = os.path.expanduser(
    "~/Library/CloudStorage/GoogleDrive-dr.deenukhan001@gmail.com/Other computers/"
    "My Mac/MacBook Google Drive Mirror/thegurukulofai x ADS/Our Video Ads")
OUT_IMG = os.path.join(ROOT, "assets", "portfolio")
OUT_VID = os.path.join(HERE, "video-out")
CDN = "https://tgai-cdn-2.b-cdn.net/portfolio"
WIDTHS = [600, 1200]
QUALITY = {600: {"avif": 55, "webp": 76}, 1200: {"avif": 50, "webp": 70}}
CSS_V = {"base": 37, "components": 38, "portfolio": 1}

WHATSAPP = ("https://wa.me/917827876564?text="
            "Hi%20Deenu%2C%20I%20saw%20your%20ads%20portfolio%20and%20want%20to%20discuss%20a%20project.")

media_only = "--media-only" in sys.argv
html_only = "--html-only" in sys.argv
force = "--force" in sys.argv          # re-transcode video that already exists

CATS = [
    ("all", "All work"), ("d2c", "D2C Ads"), ("ugc", "UGC"),
    ("product", "Product Films"), ("cgi", "3D &amp; CGI"),
    ("fashion", "Fashion &amp; Beauty"), ("food", "Food &amp; Drink"),
]

# How each piece is labelled on the tile. Paid work says so; everything else is
# openly marked, so nothing on this page overstates the relationship.
STATUS = {"client": "Client work", "spec": "Spec", "concept": "Concept"}


def build_media():
    from PIL import Image, ImageOps
    import imageio_ffmpeg
    ffmpeg = imageio_ffmpeg.get_ffmpeg_exe()
    os.makedirs(OUT_IMG, exist_ok=True)
    os.makedirs(OUT_VID, exist_ok=True)

    def blur_placeholder(im):
        t = im.copy()
        t.thumbnail((20, 20), Image.LANCZOS)
        buf = io.BytesIO()
        t.save(buf, "JPEG", quality=40)
        return "data:image/jpeg;base64," + base64.b64encode(buf.getvalue()).decode()

    # Cap the longest side without ever upscaling, and keep both sides even for h264.
    def cap(px):
        return ("scale='if(gt(iw,ih),min({p},iw),-2)':'if(gt(iw,ih),-2,min({p},ih))'"
                ",scale=trunc(iw/2)*2:trunc(ih/2)*2".format(p=px))

    manifest = json.load(open(os.path.join(HERE, "manifest.json")))
    data = []
    for n, item in enumerate(manifest, 1):
        slug = item["slug"]
        src = os.path.join(SRC, item["src"])
        if not os.path.exists(src):
            sys.exit("missing source: " + src)
        entry = {k: item[k] for k in ("slug", "brand", "title", "kind", "status", "cats")}

        done = os.path.exists(os.path.join(OUT_VID, slug + ".mp4"))
        if not done or force:
            subprocess.run([ffmpeg, "-y", "-i", src, "-vf", cap(1280),
                            "-c:v", "libx264", "-crf", "24", "-preset", "slow",
                            "-profile:v", "high", "-pix_fmt", "yuv420p",
                            "-c:a", "aac", "-b:a", "128k", "-ac", "2",
                            "-movflags", "+faststart",
                            os.path.join(OUT_VID, slug + ".mp4")],
                           check=True, capture_output=True)
            subprocess.run([ffmpeg, "-y", "-ss", "1", "-t", "4", "-i", src,
                            "-vf", cap(540), "-an",
                            "-c:v", "libx264", "-crf", "30", "-preset", "slow",
                            "-pix_fmt", "yuv420p", "-movflags", "+faststart",
                            os.path.join(OUT_VID, slug + "-loop.mp4")],
                           check=True, capture_output=True)

        poster = os.path.join(HERE, "_poster.png")
        subprocess.run([ffmpeg, "-y", "-ss", "1.5", "-i", src, "-frames:v", "1", poster],
                       check=True, capture_output=True)
        im = ImageOps.exif_transpose(Image.open(poster)).convert("RGB")

        entry["mp4"] = slug + ".mp4"
        entry["loop"] = slug + "-loop.mp4"
        entry["lqip"] = blur_placeholder(im)
        for w in WIDTHS:
            r = im if im.width <= w else im.resize(
                (w, round(im.height * w / im.width)), Image.LANCZOS)
            r.save(os.path.join(OUT_IMG, f"{slug}-{w}.avif"), quality=QUALITY[w]["avif"])
            r.save(os.path.join(OUT_IMG, f"{slug}-{w}.webp"), quality=QUALITY[w]["webp"], method=6)
            if w == 600:
                entry["w"], entry["h"] = r.width, r.height
        data.append(entry)
        print(f"[{n}/{len(manifest)}] {slug}", flush=True)

    json.dump(data, open(os.path.join(HERE, "portfolio-data.json"), "w"), indent=1)
    p = os.path.join(HERE, "_poster.png")
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
        '        <button type="button" class="p-chip" data-filter="{k}" aria-pressed="{p}">'
        '{label}<span class="p-chip-count">{n}</span></button>'.format(
            k=k, label=label, n=counts[k], p="true" if k == "all" else "false")
        for k, label in CATS)

    play = '<svg viewBox="0 0 12 12" aria-hidden="true"><path d="M2 1l8 5-8 5z"/></svg>'
    tiles = []
    for i, d in enumerate(data):
        slug, status = d["slug"], d["status"]
        brand = html.escape(d["brand"], True)
        title = html.escape(d["title"], True)
        kind = html.escape(d["kind"], True)
        label = "%s - %s" % (brand, title)
        ratio = "wide" if d["w"] > d["h"] else "tall"
        attrs = ['type="button"', 'class="p-item p-%s"' % ratio,
                 'data-cats="%s"' % " ".join(d["cats"]),
                 'data-brand="%s"' % brand, 'data-title="%s"' % title,
                 'data-kind="%s"' % kind, 'data-status="%s"' % STATUS[status],
                 'data-poster="/assets/portfolio/%s-1200.webp"' % slug,
                 'data-mp4="%s/%s"' % (CDN, d["mp4"]),
                 'data-loop="%s/%s"' % (CDN, d["loop"]),
                 'style="background-image:url(%s)"' % d["lqip"],
                 'aria-label="Play: %s"' % label]
        tiles.append(
            '        <button {attrs}>\n'
            '          <picture>\n'
            '            <source srcset="/assets/portfolio/{slug}-600.avif" type="image/avif">\n'
            '            <img src="/assets/portfolio/{slug}-600.webp" alt="{label}"\n'
            '                 width="{w}" height="{h}" loading="{load}" decoding="async"{fp}>\n'
            '          </picture>\n'
            '          <span class="p-badge">{play} Play</span>\n'
            '          <span class="p-status p-status-{status}">{statuslabel}</span>\n'
            '          <span class="p-cap">\n'
            '            <span class="p-cap-brand">{brand}</span>\n'
            '            <span class="p-cap-title">{title}</span>\n'
            '            <span class="p-cap-kind">{kind}</span>\n'
            '          </span>\n'
            '        </button>'.format(
                attrs=" ".join(attrs), slug=slug, label=label, brand=brand,
                title=title, kind=kind, play=play, w=d["w"], h=d["h"],
                status=status, statuslabel=STATUS[status],
                load="eager" if i < 4 else "lazy",
                fp=' fetchpriority="high"' if i < 2 else ""))

    # Logo wall: every named paying client, in manifest order, no repeats.
    seen, brands = set(), []
    for d in data:
        if d["status"] == "client" and d["brand"] not in seen:
            seen.add(d["brand"])
            brands.append(d["brand"])
    wall = "\n".join(
        '          <span class="p-logo">%s</span>' % html.escape(b, True) for b in brands)

    tpl = open(os.path.join(HERE, "portfolio.template.html")).read()
    page = (tpl.replace("{{FIRST}}", data[0]["slug"])
               .replace("{{CHIPS}}", chips)
               .replace("{{TILES}}", "\n".join(tiles))
               .replace("{{BRANDS}}", wall)
               .replace("{{COUNT}}", str(len(data)))
               .replace("{{WHATSAPP}}", WHATSAPP)
               .replace("{{V_BASE}}", str(CSS_V["base"]))
               .replace("{{V_COMPONENTS}}", str(CSS_V["components"]))
               .replace("{{V_PORTFOLIO}}", str(CSS_V["portfolio"])))
    outdir = os.path.join(ROOT, "portfolio")
    os.makedirs(outdir, exist_ok=True)
    open(os.path.join(outdir, "index.html"), "w").write(page)
    print("wrote portfolio/index.html:", len(data), "items", counts)


if __name__ == "__main__":
    if html_only:
        build_html(json.load(open(os.path.join(HERE, "portfolio-data.json"))))
    elif media_only:
        build_media()
    else:
        build_html(build_media())
