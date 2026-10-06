"""Build page-owned PBB blocks; no HTML-comment or slash loss in serialization."""
from pathlib import Path
import json,re,sys,shutil

root=Path(__file__).resolve().parents[1]
site=root/'site'
manifest=json.loads((site/'product.json').read_text()) if (site/'product.json').exists() else {'id':1180328,'variation_id':131,'checkout':'https://gauravtiwari.org/?fluent-cart=instant_checkout&item_id=131&quantity=1'}
media=json.loads((site/'assets/media.json').read_text()) if (site/'assets/media.json').exists() else {'icon':{'url':'assets/site-agent-icon-approved-512.png'}}
# Tabler outline icons, inlined so every icon shares one size, stroke and color.
ICONS={
 'arrow-up-right':['M17 7l-10 10','M8 7l9 0l0 9'],
 'arrow-down':['M12 5l0 14','M18 13l-6 6','M6 13l6 6'],
 'arrow-back-up':['M9 14l-4 -4l4 -4','M5 10h11a4 4 0 1 1 0 8h-1'],
 'plus':['M12 5l0 14','M5 12l14 0'],
 'copy':['M7 9.667a2.667 2.667 0 0 1 2.667 -2.667h8.666a2.667 2.667 0 0 1 2.667 2.667v8.666a2.667 2.667 0 0 1 -2.667 2.667h-8.666a2.667 2.667 0 0 1 -2.667 -2.667l0 -8.666','M4.012 16.737a2.005 2.005 0 0 1 -1.012 -1.737v-10c0 -1.1 .9 -2 2 -2h10c.75 0 1.158 .385 1.5 1'],
 'database':['M4 6a8 3 0 1 0 16 0a8 3 0 1 0 -16 0','M4 6v6a8 3 0 0 0 16 0v-6','M4 12v6a8 3 0 0 0 16 0v-6'],
 'pencil':['M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4','M13.5 6.5l4 4'],
 'eye':['M10 12a2 2 0 1 0 4 0a2 2 0 0 0 -4 0','M21 12c-2.4 4 -5.4 6 -9 6c-3.6 0 -6.6 -2 -9 -6c2.4 -4 5.4 -6 9 -6c3.6 0 6.6 2 9 6'],
 'file-code':['M14 3v4a1 1 0 0 0 1 1h4','M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2','M10 13l-1 2l1 2','M14 13l1 2l-1 2'],
 'terminal':['M8 9l3 3l-3 3','M13 15l3 0','M3 6a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2l0 -12'],
 'shield-check':['M11.46 20.846a12 12 0 0 1 -7.96 -14.846a12 12 0 0 0 8.5 -3a12 12 0 0 0 8.5 3a12 12 0 0 1 -.09 7.06','M15 19l2 2l4 -4'],
 'lock':['M5 13a2 2 0 0 1 2 -2h10a2 2 0 0 1 2 2v6a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-6z','M11 16a1 1 0 1 0 2 0a1 1 0 0 0 -2 0','M8 11v-4a4 4 0 1 1 8 0v4'],
 'alert-circle':['M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0','M12 8v4','M12 16h.01'],
 'key':['M16.555 3.843l3.602 3.602a2.877 2.877 0 0 1 0 4.069l-2.643 2.643a2.877 2.877 0 0 1 -4.069 0l-.301 -.301l-6.558 6.558a2 2 0 0 1 -1.239 .578l-.175 .008h-1.172a1 1 0 0 1 -.993 -.883l-.007 -.117v-1.172a2 2 0 0 1 .467 -1.284l.119 -.13l.414 -.414h2v-2h2v-2l2.144 -2.144l-.301 -.301a2.877 2.877 0 0 1 0 -4.069l2.643 -2.643a2.877 2.877 0 0 1 4.069 0z','M15 9h.01'],
 'history':['M12 8l0 4l2 2','M3.05 11a9 9 0 1 1 .5 4m-.5 5v-5h5'],
 'code':['M7 8l-4 4l4 4','M17 8l4 4l-4 4','M14 4l-4 16'],
 'world':['M3 12a9 9 0 1 0 18 0a9 9 0 0 0 -18 0','M3.6 9h16.8','M3.6 15h16.8','M11.5 3a17 17 0 0 0 0 18','M12.5 3a17 17 0 0 1 0 18'],
}
def icon(match):
 paths=''.join('<path d="'+d+'"/>' for d in ICONS[match.group(1)])
 return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="sa-icon" aria-hidden="true" focusable="false">'+paths+'</svg>'
html=(site/'content.html').read_text().replace('{{CHECKOUT}}',manifest['checkout']).replace('{{GITHUB}}','https://github.com/wpgaurav/site-agent').replace('{{ICON}}',media['icon']['url'])
html=re.sub(r'\{\{icon:([a-z-]+)\}\}',icon,html)
assert '{{' not in html and '<!--CLIENT-GUIDE-->' not in html
css=(site/'style.css').read_text();js=(site/'script.js').read_text()
assert not re.search(r'letter-spacing\s*:',css)
assert not re.search(r'(?:[\u2013\u2014\u2018\u2019\u201c\u201d])',html)
sections=[];level=0;start=None
for match in re.finditer(r'<section\b[^>]*>|</section>',html):
 if match.group().startswith('</'):
  level-=1
  if level==0:sections.append(html[start:match.end()])
 else:
  if level==0:start=match.start()
  level+=1
names=['Hero','Tool Index','Page Builders','Safety Checks','Access Model','Connect Your Client','Free Offer','Questions','Final Action']
assert level==0 and len(sections)==len(names)
def block(attributes):
 encoded=json.dumps(attributes,ensure_ascii=False,separators=(',',':'),allow_nan=False)
 for old,new in [('\\\\',r'\u005c'),('--',r'\u002d\u002d'),('<',r'\u003c'),('>',r'\u003e'),('&',r'\u0026'),('\\"',r'\u0022')]:encoded=encoded.replace(old,new)
 assert json.loads(encoded)==attributes
 return '<!-- wp:gt-page-block/page-block '+encoded+' /-->'
blocks=[]
for i,(name,section) in enumerate(zip(names,sections)):
 blocks.append(block({'name':name,'blockId':0,'blockSlug':'','content':section,'css':css if i==0 else '', 'js':js if i==len(names)-1 else '', 'jsLocation':'footer','format':False,'phpExec':False,'output':'inline'}))
content='<!-- wp:group {"tagName":"main","className":"sa"} -->\n<main class="wp-block-group sa">\n'+'\n\n'.join(blocks)+'\n</main>\n<!-- /wp:group -->'
(site/'product-content.html').write_text(content)
payload={'id':manifest['id'],'content':content,'template':'pbb-template.php','title':'Site Agent','slug':'site-agent','excerpt':'A free WordPress MCP plugin that connects your AI client to your site, with 18 tools, skills for five page builders, Bricks abilities, staged edits and PHP rollback.','meta':{'rank_math_title':'Site Agent: WordPress MCP Developer Tools %sep% %sitename%','rank_math_description':'Connect your AI client to WordPress with Site Agent: 18 MCP tools, page builder skills, Bricks abilities, staged edits and PHP rollback. Free.','rank_math_focus_keyword':'Site Agent WordPress MCP'}}
(site/'product-payload.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2))
preview=html.replace(media['icon']['url'],'assets/site-agent-icon-approved-512.png')
preview_fonts='@font-face{font-family:FinlandicaPreview;src:url(.preview/FinlandicaTextVF-latin.otf)}@font-face{font-family:GTPreview;src:url(.preview/ReallySansLarge-Black.otf);font-weight:800}body{margin:0;font-family:FinlandicaPreview,sans-serif}.sa h1,.sa h2{font-family:GTPreview,sans-serif}body>.preview-theme{position:fixed;right:16px;top:16px;z-index:1000;background:#fffffc;color:#212121;border:1px solid #ddd;border-radius:4px;padding:10px 14px;font:inherit;cursor:pointer}'
(site/'index.html').write_text('<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Site Agent Product Preview</title><style>'+preview_fonts+css+'</style></head><body><button class="preview-theme" aria-label="Toggle preview theme">Toggle Theme</button><main class="sa">'+preview+'</main><script>'+js+"document.querySelector('.preview-theme').addEventListener('click',()=>document.documentElement.dataset.theme=document.documentElement.dataset.theme==='dark'?'light':'dark');"+'</script></body></html>')
fonts=site/'.preview';fonts.mkdir(exist_ok=True)
for name in ['ReallySansLarge-Black.otf','FinlandicaTextVF-latin.otf']:shutil.copy(Path.home()/'Library/Fonts'/name,fonts/name)
print('Built %d named PBB sections, payload, and local preview.'%len(sections))
