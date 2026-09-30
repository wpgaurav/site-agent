"""Build page-owned PBB blocks; no HTML-comment or slash loss in serialization."""
from pathlib import Path
import json,re,sys,shutil

root=Path(__file__).resolve().parents[1]
site=root/'site'
manifest=json.loads((site/'product.json').read_text()) if (site/'product.json').exists() else {'id':1180328,'variation_id':131,'checkout':'https://gauravtiwari.org/?fluent-cart=instant_checkout&item_id=131&quantity=1'}
media=json.loads((site/'assets/media.json').read_text()) if (site/'assets/media.json').exists() else {'icon':{'url':'assets/site-agent-icon.png'}}
html=(site/'content.html').read_text().replace('{{CHECKOUT}}',manifest['checkout']).replace('{{GITHUB}}','https://github.com/wpgaurav/site-agent').replace('{{ICON}}',media['icon']['url'])
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
assert level==0 and len(sections)==8
names=['Hero','WordPress Context','Tool Workbench','Access Boundaries','Connection Setup','Free Offer','Questions','Final Action']
def block(attributes):
 encoded=json.dumps(attributes,ensure_ascii=False,separators=(',',':'),allow_nan=False)
 for old,new in [('\\\\',r'\u005c'),('--',r'\u002d\u002d'),('<',r'\u003c'),('>',r'\u003e'),('&',r'\u0026'),('\\"',r'\u0022')]:encoded=encoded.replace(old,new)
 assert json.loads(encoded)==attributes
 return '<!-- wp:gt-page-block/page-block '+encoded+' /-->'
blocks=[]
for i,(name,section) in enumerate(zip(names,sections)):
 blocks.append(block({'name':name,'blockId':0,'blockSlug':'','content':section,'css':css if i==0 else '', 'js':js if i==2 else '', 'jsLocation':'footer','format':False,'phpExec':False,'output':'inline'}))
content='<!-- wp:group {"tagName":"main","className":"sa"} -->\n<main class="wp-block-group sa">\n'+'\n\n'.join(blocks)+'\n</main>\n<!-- /wp:group -->'
(site/'product-content.html').write_text(content)
payload={'id':manifest['id'],'content':content,'template':'pbb-template.php','title':'Site Agent','slug':'site-agent','excerpt':'An open-source WordPress MCP plugin for content, source inspection, file editing, PHP execution and WP-CLI. Free checkout includes an update license.','meta':{'rank_math_title':'Site Agent: WordPress MCP Developer Tools %sep% %sitename%','rank_math_description':'Connect your coding agent to WordPress with Site Agent. Control content, source, PHP and WP-CLI access. Free download with a free automatic-update license.','rank_math_focus_keyword':'Site Agent WordPress MCP'}}
(site/'product-payload.json').write_text(json.dumps(payload,ensure_ascii=False,indent=2))
preview=html.replace(media['icon']['url'],'assets/site-agent-icon.png')
preview_fonts='@font-face{font-family:FinlandicaPreview;src:url(.preview/FinlandicaTextVF-latin.otf)}@font-face{font-family:GTPreview;src:url(.preview/ReallySansLarge-Black.otf);font-weight:800}body{margin:0;font-family:FinlandicaPreview,sans-serif}.sa h1,.sa h2{font-family:GTPreview,sans-serif}body>.preview-theme{position:fixed;right:16px;top:16px;z-index:1000;background:#fffffc;color:#212121;border:1px solid #ddd;border-radius:4px;padding:10px 14px;font:inherit;cursor:pointer}'
(site/'index.html').write_text('<!doctype html><html lang="en" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Site Agent Product Preview</title><style>'+preview_fonts+css+'</style></head><body><button class="preview-theme" aria-label="Toggle preview theme">Toggle Theme</button><main class="sa">'+preview+'</main><script>'+js+"document.querySelector('.preview-theme').addEventListener('click',()=>document.documentElement.dataset.theme=document.documentElement.dataset.theme==='dark'?'light':'dark');"+'</script></body></html>')
fonts=site/'.preview';fonts.mkdir(exist_ok=True)
for name in ['ReallySansLarge-Black.otf','FinlandicaTextVF-latin.otf']:shutil.copy(Path.home()/'Library/Fonts'/name,fonts/name)
print('Built 8 named PBB sections, payload, and local preview.')
