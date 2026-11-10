"""Собирает planerka/app.html — полную страницу Планёрки (OG-превью, шрифты, значки и логотип вшиты в один файл).
Исходники: src.html (разметка и JS), app.css (общий стиль), fonts/*.woff2, assets/*.png.
Адрес сайта в превью не вшивается: в app.html стоит %%SITE%%, его подставляет index.php из config.php.
Запуск (Python 3.6+): python build.py     — после КАЖДОЙ правки src.html или app.css.
Номер сборки (build.txt) меняется при каждой сборке: открытые приложения сами перезагрузятся на новую версию."""
import base64, os, re, time
here = os.path.dirname(os.path.abspath(__file__))
rd = lambda *p: open(os.path.join(here, *p), 'rb').read()
b64 = lambda *p: base64.b64encode(rd(*p)).decode()

def ff(fam, file, rng, wt):
    return (f"@font-face{{font-family:'{fam}';font-style:normal;font-weight:{wt};font-display:swap;"
            f"src:url(data:font/woff2;base64,{b64('fonts', file)}) format('woff2');unicode-range:{rng}}}\n")

CY = "U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116"
LA = "U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD"
fonts = ff('Inter', 'Inter-cyrillic.woff2', CY, '100 900') + ff('Inter', 'Inter-latin.woff2', LA, '100 900')

css = open(os.path.join(here, 'app.css'), encoding='utf8').read().replace('/*FONTS*/', fonts)

s = open(os.path.join(here, 'src.html'), encoding='utf8').read()
s = s.replace('/*CSS*/', css).replace('/*MARK*/', b64('assets', 'mark-96.png'))
fav = f'<link rel="icon" type="image/png" href="data:image/png;base64,{b64("assets", "favicon-64.png")}">\n' \
      f'<link rel="apple-touch-icon" href="data:image/png;base64,{b64("assets", "apple-touch-icon.png")}">'
s = s.replace('<!--ICONS-->', fav)

U = '%%SITE%%/planerka/'
head = f'''<!doctype html>
<html lang="ru"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#131921">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Планёрка">
<meta property="og:type" content="website">
<meta property="og:title" content="Планёрка">
<meta property="og:description" content="Общий список задач: входящие и исполнители.">
<meta property="og:url" content="{U}">
<meta property="og:image" content="{U}og.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<style>:root{{padding-block:env(safe-area-inset-top,0px) env(safe-area-inset-bottom,0px);color-scheme:light}}body{{margin:0}}[hidden]{{display:none!important}}</style>
'''
n = s.replace('<title>Планёрка</title>\n', head + '<title>Планёрка</title>\n', 1)
n = n.replace('</style>\n<div class="app"', '</style>\n</head><body>\n<div class="app"', 1) + '\n</body></html>\n'
for need in ('%%SITE%%/planerka/og.png', '</head><body>', 'data:font/woff2', 'data:image/png;base64,iVBOR'):
    assert need in n, 'сборка не удалась: нет ' + need
BUILD = str(int(time.time()))
n = n.replace('/*BUILD*/', BUILD)
open(os.path.join(here, 'app.html'), 'w', encoding='utf8', newline='\n').write(n)
open(os.path.join(here, 'build.txt'), 'w', encoding='utf8').write(BUILD)
print('app.html', len(n), 'build', BUILD)
