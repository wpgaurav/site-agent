"""Export original Site Agent vector artwork without distributing font files."""
from pathlib import Path
import re, subprocess, xml.etree.ElementTree as ET
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen

root=Path(__file__).resolve().parents[1]
output=Path.home()/'Pictures'/'2026-09-30'/'gauravtiwari.org'
output.mkdir(parents=True,exist_ok=True)
fonts={
 'GTReallySans':TTFont(Path.home()/'Library/Fonts/ReallySansLarge-Black.otf'),
 'Finlandica Text':TTFont(Path.home()/'Library/Fonts/FinlandicaTextVF-latin.otf'),
}
ET.register_namespace('', 'http://www.w3.org/2000/svg')
namespace='{http://www.w3.org/2000/svg}'
for kind in ['icon','social','banner']:
 source=root/'site/assets'/f'site-agent-{kind}.svg'
 tree=ET.parse(source)
 for parent in list(tree.iter()):
  for text in list(parent):
   if text.tag!=namespace+'text':continue
   family=text.get('font-family') or parent.get('font-family','')
   font=fonts['GTReallySans' if 'GTReallySans' in family else 'Finlandica Text']
   location={'wght':400} if 'fvar' in font and any(axis.axisTag=='wght' for axis in font['fvar'].axes) else None
   glyphs=font.getGlyphSet(location=location);cmap=font.getBestCmap();scale=float(text.get('font-size') or parent.get('font-size'))/font['head'].unitsPerEm
   x=float(text.get('x'));y=float(text.get('y'));fill=text.get('fill') or parent.get('fill') or '#fffffc'
   group=ET.Element(namespace+'g',{'aria-label':''.join(text.itertext()),'fill':fill})
   advance=0
   for character in ''.join(text.itertext()):
    name=cmap.get(ord(character),'.notdef');pen=SVGPathPen(glyphs);glyphs[name].draw(pen)
    if pen.getCommands():
     ET.SubElement(group,namespace+'path',{'d':pen.getCommands(),'transform':f'translate({x+advance*scale:.4f} {y}) scale({scale:.6f} {-scale:.6f})'})
    advance+=glyphs[name].width
   index=list(parent).index(text);parent.remove(text);parent.insert(index,group)
 outlined=root/'site/assets'/f'site-agent-{kind}-outlined.svg'
 tree.write(outlined,encoding='unicode',xml_declaration=False)
 width={'icon':512,'social':1200,'banner':1544}[kind]
 png=root/'site/assets'/f'site-agent-{kind}.png'
 subprocess.run(['rsvg-convert','-w',str(width),'-o',str(png),str(outlined)],check=True)
 (output/source.name).write_bytes(source.read_bytes())
 (output/png.name).write_bytes(png.read_bytes())
 print(f'Exported {kind}: {width}px')
